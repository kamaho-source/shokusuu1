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

use App\Service\NotificationService;
use Cake\Controller\Controller;
use Cake\Event\EventInterface;
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
     * クライアントの実IPアドレスを取得する。
     *
     * X-Forwarded-For / X-Real-IP はクライアントが自由に付与できるため、無条件には信頼しない。
     *
     * - .env の TRUSTED_PROXIES が設定されている場合は CakePHP 標準の解決に委ねる
     *   （信頼プロキシを除いた最左のアドレスが採用される）。
     * - 未設定の場合は、直近の接続元がプライベート/ループバックアドレスのとき、
     *   すなわち自前のリバースプロキシ経由で届いたときに限り X-Forwarded-For の最右
     *   （直近のプロキシが付与した値）を採用する。
     *
     * インターネットから直接届いたリクエストではヘッダを一切参照しないため、
     * クライアントがヘッダを偽装してもレート制限の回避や監査ログの汚染はできない。
     *
     * @return string IPアドレス文字列
     */
    protected function getClientIp(): string
    {
        if ($this->request->getTrustedProxies() !== []) {
            return (string)$this->request->clientIp();
        }

        $remoteAddr = (string)$this->request->getEnv('REMOTE_ADDR');

        if ($remoteAddr !== '' && $this->isReverseProxyAddress($remoteAddr)) {
            $forwardedFor = $this->request->getHeaderLine('X-Forwarded-For');
            if ($forwardedFor !== '') {
                $addresses = array_map('trim', explode(',', $forwardedFor));
                $candidate = (string)end($addresses);
                if (filter_var($candidate, FILTER_VALIDATE_IP) !== false) {
                    return $candidate;
                }
            }
        }

        return $remoteAddr !== '' ? $remoteAddr : (string)$this->request->clientIp();
    }

    /**
     * インターネット経由の送信元にはなり得ないアドレス（プライベート・ループバック等）か判定する。
     *
     * ここが true のときだけ、接続元を自前のリバースプロキシとみなす。
     *
     * @param string $ip 判定対象のIPアドレス
     * @return bool プライベート/予約済みレンジなら true
     */
    private function isReverseProxyAddress(string $ip): bool
    {
        if (filter_var($ip, FILTER_VALIDATE_IP) === false) {
            return false;
        }

        return filter_var(
            $ip,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
        ) === false;
    }

    public function beforeFilter(EventInterface $event)
    {
        parent::beforeFilter($event);
        $this->Authentication->allowUnauthenticated(['login']);
        $user = $this->Authentication->getIdentity();

        $this->set('user', $user);
        if ($user !== null) {
            $userId = (int)$user->get('i_id_user');
            $this->set('notificationUnreadCount', $this->notificationService->getUnreadCount($userId));
            $this->set('recentNotifications', $this->notificationService->getRecentNotifications($userId));
        } else {
            $this->set('notificationUnreadCount', 0);
            $this->set('recentNotifications', []);
        }
    }

}
