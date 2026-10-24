<?php
declare(strict_types=1);

namespace App\Controller;

use App\Service\NotificationService;
use Cake\Http\Response;

class NotificationsController extends AppController
{
    use JsonResponseTrait;

    private NotificationService $notificationService;

    public function initialize(): void
    {
        parent::initialize();
        $this->notificationService = new NotificationService();

        // JSON ボディを受け取る AJAX エンドポイントのため FormProtection のフォームトークン検証対象外にする。
        // CSRF 保護は CsrfProtectionMiddleware がミドルウェア層で適用済み。
        $this->FormProtection->setConfig('unlockedActions', [
            'markRead',
            'markAllRead',
        ]);
    }

    public function index(): ?Response
    {
        $this->Authorization->authorize($this, 'index');

        $user = $this->Authentication->getIdentity();
        $userId = (int)$user->get('i_id_user');
        $notifications = $this->notificationService->getNotifications($userId);
        $this->set(compact('notifications'));

        return null;
    }

    public function markRead(): Response
    {
        $this->Authorization->authorize($this, 'markRead');
        $this->request->allowMethod('post');

        $user = $this->Authentication->getIdentity();
        $ids = (array)($this->request->getData('ids') ?? []);
        $count = $this->notificationService->markAsRead((int)$user->get('i_id_user'), $ids);

        return $this->jsonResponse(['success' => true, 'count' => $count]);
    }

    public function markAllRead(): Response
    {
        $this->Authorization->authorize($this, 'markAllRead');
        $this->request->allowMethod('post');

        $user = $this->Authentication->getIdentity();
        $count = $this->notificationService->markAllAsRead((int)$user->get('i_id_user'));

        return $this->jsonResponse(['success' => true, 'count' => $count]);
    }

}
