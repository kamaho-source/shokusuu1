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

use App\Application\Plan\PlanGuard;
use App\Application\Tenant\TenantContext;
use App\Application\Tenant\TenantContextHolder;
use App\Domain\ValueObject\PlanCode;
use App\Domain\ValueObject\UserRole;
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

    protected PlanGuard $planGuard;

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
     * プラン制限に引っかかる場合にエラーレスポンスを返す。
     *
     * $allowed が false のとき:
     *   - JSON リクエスト ($isJson=true): 403 JSON エラーを返す
     *   - 通常リクエスト: Flash エラーを表示してダッシュボードへリダイレクト
     *
     * $allowed が true の場合は null を返す（続行）。
     */
    protected function rejectIfPlanBlocked(bool $allowed, bool $isJson = false): ?Response
    {
        if ($allowed) {
            return null;
        }
        $msg = $this->planGuard->upgradeRequiredMessage();
        if ($isJson) {
            return $this->response
                ->withStatus(403)
                ->withType('application/json')
                ->withStringBody((string)json_encode([
                    'error'                 => $msg,
                    'plan_upgrade_required' => true,
                ], JSON_UNESCAPED_UNICODE));
        }
        $this->Flash->error($msg);
        return $this->redirect(['controller' => 'Pages', 'action' => 'dashboard']);
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
     * リクエストのテナントコンテキストを返す。
     * TenantResolutionMiddleware が設定した request attribute から取得する。
     */
    protected function getTenantContext(): ?TenantContext
    {
        return $this->request->getAttribute('tenantContext');
    }

    public function beforeFilter(EventInterface $event)
    {
        parent::beforeFilter($event);
        $this->Authentication->allowUnauthenticated(['login']);
        $user = $this->Authentication->getIdentity();

        // システム管理者（i_admin=3）はテナントセッションに従いコンテキストを切り替える。
        // セッション未設定（全テナントモード）の場合はコンテキストをクリアして全データを参照可能にする。
        if ($user !== null && UserRole::isSystemAdmin((int)$user->get('i_admin'))) {
            $tenantsTable = $this->fetchTable('Tenants');
            $allTenants   = $tenantsTable->find()->orderBy(['id' => 'ASC'])->all()->toArray();

            $activeTenantId = $this->request->getSession()->read('SystemAdmin.activeTenantId');
            if ($activeTenantId !== null) {
                $activeTenantId   = (int)$activeTenantId;
                $activeTenantEntity = null;
                foreach ($allTenants as $t) {
                    if ($t->id === $activeTenantId) {
                        $activeTenantEntity = $t;
                        break;
                    }
                }
                if ($activeTenantEntity !== null) {
                    TenantContextHolder::set(new TenantContext(
                        tenantId:     $activeTenantEntity->id,
                        tenantCode:   $activeTenantEntity->tenant_code,
                        tenantStatus: $activeTenantEntity->status,
                    ));
                } else {
                    TenantContextHolder::clear();
                    $activeTenantId = null;
                    $this->request->getSession()->delete('SystemAdmin.activeTenantId');
                }
            } else {
                TenantContextHolder::clear();
            }

            $this->set('allTenants', $allTenants);
            $this->set('activeTenantId', $activeTenantId);
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

        $this->planGuard = $this->resolvePlanGuard($user, $activeTenantId ?? null, $allTenants ?? []);
        $this->set('planGuard', $this->planGuard);
        $this->set('isSysAdmin', $user !== null && UserRole::isSystemAdmin((int)$user->get('i_admin')));
        $this->set('isAdmin', $user !== null && UserRole::isAdmin((int)$user->get('i_admin')));
    }

    /**
     * 現在のユーザーとテナント状態から PlanGuard を解決する。
     *
     * @param array<\App\Model\Entity\Tenant> $allTenants
     */
    private function resolvePlanGuard(mixed $user, ?int $activeTenantId, array $allTenants): PlanGuard
    {
        if ($user === null) {
            return new PlanGuard(PlanCode::Starter);
        }

        $isSysAdmin = UserRole::isSystemAdmin((int)$user->get('i_admin'));

        // システム管理者はプラン制限なし（操作中テナントがある場合はそのプランを表示用に保持）
        if ($isSysAdmin) {
            $planCode = PlanCode::Premium;
            if ($activeTenantId !== null) {
                foreach ($allTenants as $t) {
                    if ($t->id === $activeTenantId) {
                        $planCode = PlanCode::fromTenant($t->plan_code, $t->status);
                        break;
                    }
                }
            }
            return new PlanGuard($planCode, isSysAdmin: true);
        }

        // 通常ユーザー：リクエスト属性またはDBからテナントプランを取得
        $tenant = $this->request->getAttribute('tenant');
        if ($tenant !== null) {
            return new PlanGuard(PlanCode::fromTenant($tenant->plan_code, $tenant->status));
        }

        // フォールバック：tenant_idでDBを引く（ローカル開発環境等）
        $tenantId = $user->get('tenant_id');
        if ($tenantId !== null) {
            $t = $this->fetchTable('Tenants')->find()->where(['id' => (int)$tenantId])->first();
            if ($t !== null) {
                return new PlanGuard(PlanCode::fromTenant($t->plan_code, $t->status));
            }
        }

        return new PlanGuard(PlanCode::Starter);
    }

}
