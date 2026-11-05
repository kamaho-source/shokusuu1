<?php
declare(strict_types=1);

/**
 * CakePHP(tm) : Rapid Development Framework (https://cakephp.org)
 * Copyright (c) Cake Software Foundation, Inc. (https://cakefoundation.org)
 *
 * Licensed under The MIT License
 * For full copyright and license information, please see the LICENSE.txt
 * Redistributions of files must retain the above copyright notice.
 *
 * @copyright Copyright (c) Cake Software Foundation, Inc. (https://cakefoundation.org)
 * @link      https://cakephp.org CakePHP(tm) Project
 * @since     0.2.9
 * @license   https://opensource.org/licenses/mit-license.php MIT License
 */
namespace App\Controller;

use App\Application\Port\AuditActor;
use App\Domain\Service\DatePeriodRule;
use App\Domain\ValueObject\AccountStatus;
use App\Service\AuditLogService;
use App\Service\NotificationService;
use Cake\Controller\Controller;
use Cake\Event\EventInterface;
use Cake\Http\Exception\BadRequestException;
use Cake\Http\Response;
use Psr\Http\Message\UriInterface;

/**
 * Application Controller
 *
 * Add your application-wide methods in the class below, your controllers
 * will inherit them.
 *
 * @link https://book.cakephp.org/4/en/controllers.html#the-app-controller
 */
class AppController extends Controller
{
    private NotificationService $notificationService;

    /**
     * Initialization hook method.
     *
     * Use this method to add common initialization code like loading components.
     *
     * e.g. `$this->loadComponent('FormProtection');`
     *
     * @return void
     */
    public function initialize(): void
    {
        parent::initialize();
        $this->loadComponent('Flash');
        $this->loadComponent('Authentication.Authentication');
        $this->loadComponent('Authorization.Authorization');
        $this->notificationService = new NotificationService();
        $this->set('user', $this->Authentication->getIdentity());
        $this->loadComponent('FormProtection');
    }


    /**
     * リダイレクト先が安全な内部パスかどうかを検証する。
     * 外部ドメインやプロトコル相対URLへのオープンリダイレクトを防ぐ。
     */
    protected function isSafeRedirect(string|array|null $url): bool
    {
        if (!is_string($url) || $url === '') {
            return false;
        }

        // バックスラッシュはブラウザ側で / に正規化されるため、
        // `/\evil.com` → `//evil.com` となるオープンリダイレクトバイパスを拒否する（CVE-2026-55590 と同種）
        if (str_contains($url, '\\')) {
            return false;
        }

        $parsed = parse_url($url);
        if ($parsed === false) {
            return false;
        }

        // スキームまたはホストが含まれる場合は外部URLとして拒否（//evil.com 亜種も含む）
        if (!empty($parsed['scheme']) || !empty($parsed['host'])) {
            return false;
        }

        // '/' から始まる内部パスのみ許可
        return str_starts_with($url, '/');
    }

    /**
     * 文字列URLに対してオープンリダイレクト検証を自動適用する。
     * 外部ドメインへのリダイレクトはルートにフォールバックする。
     */
    public function redirect(UriInterface|array|string $url, int $status = 302): ?Response
    {
        if (is_string($url) && !$this->isSafeRedirect($url)) {
            $url = '/';
        }
        return parent::redirect($url, $status);
    }

    /**
     * クエリの値を文字列で返す。無い・配列（?from[]=x など）は空文字。
     *
     * (string) で変換すると、配列のとき「Array to string conversion」の警告が出て値が "Array" になるため。
     */
    protected function queryString(string $key): string
    {
        $value = $this->request->getQuery($key);

        return is_string($value) ? $value : '';
    }

    /**
     * クエリの日付（YYYY-MM-DD）。無い・空は null。
     *
     * @throws BadRequestException 配列や暦にない日付（2026-02-30 など）。以前は 500 や、翌月に繰り越した 200 になっていた
     */
    protected function queryDate(string $key): ?string
    {
        $value = $this->request->getQuery($key);
        if ($value === null || $value === '') {
            return null;
        }
        if (!is_string($value) || !DatePeriodRule::isDate($value)) {
            throw new BadRequestException('日付が正しくありません。');
        }

        return $value;
    }

    /**
     * 送信された値を文字列で返す。無い・配列は空文字（理由は queryString と同じ）。
     */
    protected function dataString(string $key): string
    {
        $value = $this->request->getData($key);

        return is_string($value) ? $value : '';
    }

    /** 監査ログに残す操作者（ログイン中の利用者）。 */
    protected function auditActor(): AuditActor
    {
        $identity = $this->request->getAttribute('identity');

        return new AuditActor(
            $identity ? (string)$identity->get('c_user_name') : '不明なユーザー',
            $identity ? (int)$identity->get('i_id_user') : 0,
            (string)($identity?->get('c_login_account') ?? ''),
            $this->getClientIp(),
        );
    }

    /**
     * 予約の書き込みの監査ログを1件残す（成功・失敗のどちらでも）。
     *
     * @param array<string, mixed> $detail 日付・部屋・件数など。利用者名などの個人情報は入れない
     */
    protected function recordReservationAudit(string $action, ?string $targetId, array $detail, bool $succeeded, ?string $failureReason = null): void
    {
        $actor = $this->auditActor();
        AuditLogService::record(
            'reservation', $action, $actor->name, $actor->id, 't_reservation_info', $targetId, $detail,
            $actor->ipAddress !== '' ? $actor->ipAddress : null, $succeeded ? 1 : 0, $actor->loginId, $failureReason
        );
    }

    /**
     * クライアントの実IPアドレスを取得する。
     *
     * Docker + リバースプロキシ構成では REMOTE_ADDR がプロキシのIPになるため、
     * X-Forwarded-For → X-Real-IP → REMOTE_ADDR の優先順位で実IPを取得する。
     * X-Forwarded-For が複数IPを持つ場合（例: "client, proxy1"）は
     * 最左（クライアント側）のIPを使用する。
     *
     * @return string IPアドレス文字列
     */
    protected function getClientIp(): string
    {
        $forwardedFor = $this->request->getHeaderLine('X-Forwarded-For');
        if ($forwardedFor !== '') {
            $ip = trim(explode(',', $forwardedFor)[0]);
            if (filter_var($ip, FILTER_VALIDATE_IP) !== false) {
                return $ip;
            }
        }

        $realIp = $this->request->getHeaderLine('X-Real-IP');
        if ($realIp !== '' && filter_var($realIp, FILTER_VALIDATE_IP) !== false) {
            return $realIp;
        }

        return (string)$this->request->clientIp();
    }

    /**
     * 削除済み(i_del_flag=1)または無効(i_enable=1)のユーザーか。
     * 判定は AccountStatus に1つだけあり、AuthController::login() も同じものを使う。
     */
    private function isDisabledIdentity(\Authentication\IdentityInterface $user): bool
    {
        return AccountStatus::isDisabled($user->get('i_del_flag'), $user->get('i_enable'));
    }

    /**
     * 全コントローラー共通の前処理。ログイン中に削除・無効化された人は、ここで締め出す応答（401 の JSON、またはログイン画面への転送）を返す。
     * beforeFilter を上書きするコントローラーは、この戻り値を必ず返すこと（捨てると、締め出しが認証ミドルウェアの副作用頼みになる）。
     */
    public function beforeFilter(EventInterface $event): ?Response
    {
        parent::beforeFilter($event);
        $this->Authentication->allowUnauthenticated(['login']);
        $user = $this->Authentication->getIdentity();

        // 削除・無効化されたユーザーのログイン済みセッションを締め出す。
        // 有効性の確認がログインフォームだけだと、ログイン中のセッションは使い続けられてしまう。
        if ($user !== null && $this->isDisabledIdentity($user)
            && !in_array($this->request->getParam('action'), ['login', 'logout'], true)
        ) {
            $this->Authentication->logout();
            // ここで処理を打ち切るため、認可チェック必須ミドルウェアが 500 にしないよう明示的にスキップする。
            $this->Authorization->skipAuthorization();
            if ($this->request->is('ajax') || $this->request->accepts('application/json')) {
                return $this->response->withStatus(401)
                    ->withType('application/json')
                    ->withStringBody((string)json_encode(
                        ['status' => 'error', 'message' => 'このアカウントは無効化されています。'],
                        JSON_UNESCAPED_UNICODE
                    ));
            }
            $this->Flash->error(__('このアカウントは無効化されています。'));

            return $this->redirect(['controller' => 'Auth', 'action' => 'login']);
        }

        $this->set('user', $user);
        if ($user !== null) {
            $userId = (int)$user->get('i_id_user');
            $this->set('notificationUnreadCount', $this->notificationService->getUnreadCount($userId));
            $this->set('recentNotifications', $this->notificationService->getRecentNotifications($userId));
        } else {
            $this->set('notificationUnreadCount', 0);
            $this->set('recentNotifications', []);
        }

        return null;
    }

}
