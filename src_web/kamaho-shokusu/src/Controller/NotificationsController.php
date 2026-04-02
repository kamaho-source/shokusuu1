<?php
declare(strict_types=1);

namespace App\Controller;

use App\Service\NotificationService;
use Cake\Http\Response;

class NotificationsController extends AppController
{
    private NotificationService $notificationService;

    public function initialize(): void
    {
        parent::initialize();
        $this->notificationService = new NotificationService();
    }

    public function index(): ?Response
    {
        $this->Authorization->skipAuthorization();

        $user = $this->Authentication->getIdentity();
        if ($user === null) {
            return $this->redirect('/MUserInfo/login');
        }

        $userId = (int)$user->get('i_id_user');
        $notifications = $this->notificationService->getNotifications($userId);
        $this->set(compact('notifications'));

        return null;
    }

    public function markRead(): Response
    {
        $this->Authorization->skipAuthorization();
        $this->request->allowMethod('post');

        $user = $this->Authentication->getIdentity();
        if ($user === null) {
            return $this->jsonError('認証が必要です', 401);
        }

        $ids = (array)($this->request->getData('ids') ?? []);
        $count = $this->notificationService->markAsRead((int)$user->get('i_id_user'), $ids);

        return $this->jsonResponse(['success' => true, 'count' => $count]);
    }

    public function markAllRead(): Response
    {
        $this->Authorization->skipAuthorization();
        $this->request->allowMethod('post');

        $user = $this->Authentication->getIdentity();
        if ($user === null) {
            return $this->jsonError('認証が必要です', 401);
        }

        $count = $this->notificationService->markAllAsRead((int)$user->get('i_id_user'));

        return $this->jsonResponse(['success' => true, 'count' => $count]);
    }

    private function jsonResponse(array $data, int $status = 200): Response
    {
        return $this->response
            ->withStatus($status)
            ->withType('application/json')
            ->withStringBody(json_encode($data, JSON_UNESCAPED_UNICODE));
    }

    private function jsonError(string $message, int $status = 400): Response
    {
        return $this->jsonResponse(['success' => false, 'error' => $message], $status);
    }
}
