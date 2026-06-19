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
