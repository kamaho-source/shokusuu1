<?php
declare(strict_types=1);

namespace App\Controller;

use App\Domain\ValueObject\UserRole;
use App\Service\ApprovalService;
use App\Service\RoomAccessService;
use Cake\Http\Response;

/**
 * 承認フロー コントローラー
 *
 * ブロック長（i_admin = 2）と管理者（i_admin = 1）向けの承認画面を提供する。
 * 認可は ApprovalPolicy を通じて Authorization::authorize() で制御する。
 */
class ApprovalController extends AppController
{
    private ApprovalService $approvalService;
    private RoomAccessService $roomAccessService;

    public function initialize(): void
    {
        parent::initialize();
        $this->approvalService   = new ApprovalService();
        $this->roomAccessService = new RoomAccessService();

        // これらのアクションは JSON ボディを受け取る AJAX エンドポイントのため
        // FormProtection のフォームトークン検証対象外にする。
        // CSRF 保護は CsrfProtectionMiddleware がミドルウェア層で適用済み。
        $this->FormProtection->setConfig('unlockedActions', [
            'blockLeaderApprove',
            'blockLeaderReject',
            'adminApprove',
            'adminReject',
            'adminReflect',
        ]);
    }

    /**
     * GET /Approval
     *
     * 直アクセス時のフォールバック。権限に応じた承認画面へリダイレクトする。
     */
    public function index(): Response
    {
        $this->Authorization->skipAuthorization();
        $user  = $this->Authentication->getIdentity();
        $admin = (int)($user?->get('i_admin') ?? 0);

        if (UserRole::isAdmin($admin)) {
            return $this->redirect(['action' => 'adminIndex']);
        }
        if (UserRole::isBlockLeader($admin)) {
            return $this->redirect(['action' => 'blockLeaderIndex']);
        }

        $this->Flash->error('この画面を表示する権限がありません。');
        return $this->redirect(['controller' => 'Pages', 'action' => 'display', 'home']);
    }

    // ------------------------------------------------------------------
    // ブロック長用 承認一覧
    // ------------------------------------------------------------------

    /**
     * GET /Approval/blockLeaderIndex
     */
    public function blockLeaderIndex(): ?Response
    {
        $this->Authorization->authorize($this, 'blockLeaderIndex');

        $user    = $this->Authentication->getIdentity();
        $userId  = (int)$user->get('i_id_user');
        $roomIds = $this->roomAccessService->getUserRoomIds($userId);

        $filterRoomId = $this->request->getQuery('room_id') ? (int)$this->request->getQuery('room_id') : null;
        $filterStatus = $this->request->getQuery('status') !== null && $this->request->getQuery('status') !== ''
            ? (int)$this->request->getQuery('status')
            : ApprovalService::STATUS_PENDING;
        $dateFrom = $this->request->getQuery('date_from') ?? date('Y-m-d', strtotime('monday this week'));
        $dateTo   = $this->request->getQuery('date_to')   ?? date('Y-m-d', strtotime('sunday this week'));

        $records = $this->approvalService->getBlockLeaderList(
            $userId, $filterRoomId, $dateFrom, $dateTo, $filterStatus
        );

        $rooms = $this->roomAccessService->getRoomsByIds($roomIds);

        $this->set(compact('records', 'rooms', 'filterRoomId', 'filterStatus', 'dateFrom', 'dateTo'));
        return null;
    }

    /**
     * POST /Approval/blockLeaderApprove
     */
    public function blockLeaderApprove(): Response
    {
        $this->Authorization->authorize($this, 'blockLeaderApprove');
        $this->request->allowMethod('post');

        $user     = $this->Authentication->getIdentity();
        $approver = (int)$user->get('i_id_user');
        $actor    = $user->get('c_login_account') ?? (string)$approver;
        $keys     = (array)($this->request->getData('keys') ?? []);

        if (empty($keys)) {
            return $this->jsonError('対象が指定されていません', 400);
        }

        try {
            $ok = $this->approvalService->blockLeaderApprove($keys, $approver, $actor, $this->getClientIp());
            return $this->jsonResponse(['success' => $ok]);
        } catch (\Throwable $e) {
            $this->log('blockLeaderApprove error: ' . $e->getMessage(), 'error');
            return $this->jsonError('承認処理中にエラーが発生しました。', 500);
        }
    }

    /**
     * POST /Approval/blockLeaderReject
     */
    public function blockLeaderReject(): Response
    {
        $this->Authorization->authorize($this, 'blockLeaderReject');
        $this->request->allowMethod('post');

        $user     = $this->Authentication->getIdentity();
        $approver = (int)$user->get('i_id_user');
        $actor    = $user->get('c_login_account') ?? (string)$approver;
        $keys     = (array)($this->request->getData('keys') ?? []);
        $reason   = $this->request->getData('reason');

        if (empty($keys)) {
            return $this->jsonError('対象が指定されていません', 400);
        }

        try {
            $ok = $this->approvalService->reject($keys, $approver, $actor, $reason, $this->getClientIp());
            return $this->jsonResponse(['success' => $ok]);
        } catch (\Throwable $e) {
            $this->log('blockLeaderReject error: ' . $e->getMessage(), 'error');
            return $this->jsonError('却下処理中にエラーが発生しました。', 500);
        }
    }

    // ------------------------------------------------------------------
    // 管理者用 承認一覧
    // ------------------------------------------------------------------

    /**
     * GET /Approval/adminIndex
     */
    public function adminIndex(): ?Response
    {
        $this->Authorization->authorize($this, 'adminIndex');

        $filterRoomId = $this->request->getQuery('room_id') ? (int)$this->request->getQuery('room_id') : null;
        $filterStatus = $this->request->getQuery('status') !== null && $this->request->getQuery('status') !== ''
            ? (int)$this->request->getQuery('status')
            : ApprovalService::STATUS_BLOCK_LEADER;
        $dateFrom = $this->request->getQuery('date_from') ?? date('Y-m-d', strtotime('monday this week'));
        $dateTo   = $this->request->getQuery('date_to')   ?? date('Y-m-d', strtotime('sunday this week'));

        $records = $this->approvalService->getAdminList($filterRoomId, $dateFrom, $dateTo, $filterStatus);
        $summary = $this->approvalService->getAdminSummary($dateFrom, $dateTo);

        $rooms = $this->roomAccessService->getAllActiveRooms();

        $this->set(compact('records', 'summary', 'rooms', 'filterRoomId', 'filterStatus', 'dateFrom', 'dateTo'));
        return null;
    }

    /**
     * POST /Approval/adminApprove
     */
    public function adminApprove(): Response
    {
        $this->Authorization->authorize($this, 'adminApprove');
        $this->request->allowMethod('post');

        $user     = $this->Authentication->getIdentity();
        $approver = (int)$user->get('i_id_user');
        $actor    = $user->get('c_login_account') ?? (string)$approver;
        $keys     = (array)($this->request->getData('keys') ?? []);

        if (empty($keys)) {
            return $this->jsonError('対象が指定されていません', 400);
        }

        try {
            $ok = $this->approvalService->adminApprove($keys, $approver, $actor, $this->getClientIp());
            return $this->jsonResponse(['success' => $ok]);
        } catch (\Throwable $e) {
            $this->log('adminApprove error: ' . $e->getMessage(), 'error');
            return $this->jsonError('承認処理中にエラーが発生しました。', 500);
        }
    }

    /**
     * POST /Approval/adminReject
     */
    public function adminReject(): Response
    {
        $this->Authorization->authorize($this, 'adminReject');
        $this->request->allowMethod('post');

        $user     = $this->Authentication->getIdentity();
        $approver = (int)$user->get('i_id_user');
        $actor    = $user->get('c_login_account') ?? (string)$approver;
        $keys     = (array)($this->request->getData('keys') ?? []);
        $reason   = $this->request->getData('reason');

        if (empty($keys)) {
            return $this->jsonError('対象が指定されていません', 400);
        }

        try {
            $ok = $this->approvalService->reject($keys, $approver, $actor, $reason, $this->getClientIp());
            return $this->jsonResponse(['success' => $ok]);
        } catch (\Throwable $e) {
            $this->log('adminReject error: ' . $e->getMessage(), 'error');
            return $this->jsonError('却下処理中にエラーが発生しました。', 500);
        }
    }

    /**
     * POST /Approval/adminReflect
     */
    public function adminReflect(): Response
    {
        $this->Authorization->authorize($this, 'adminReflect');
        $this->request->allowMethod('post');

        $user     = $this->Authentication->getIdentity();
        $actor    = $user->get('c_login_account') ?? (string)$user->get('i_id_user');
        $roomId   = $this->request->getData('room_id') ? (int)$this->request->getData('room_id') : null;
        $dateFrom = $this->request->getData('date_from') ?: null;
        $dateTo   = $this->request->getData('date_to') ?: null;

        try {
            [$reflectedCount, $recordCount] = $this->approvalService->reflectToReservation($roomId, $dateFrom, $dateTo, $actor);
            return $this->jsonResponse(['success' => true, 'reflected_count' => $recordCount, 'group_count' => $reflectedCount]);
        } catch (\Throwable $e) {
            $this->log('adminReflect error: ' . $e->getMessage(), 'error');
            return $this->jsonError('反映処理中にエラーが発生しました。', 500);
        }
    }

    /**
     * 承認履歴（ログ）一覧を表示
     */
    public function approvalLog(): ?Response
    {
        $this->Authorization->skipAuthorization();
        $user = $this->Authentication->getIdentity();
        $isAdmin = UserRole::isAdmin((int)($user->get('i_admin') ?? 0));
        $isBlockLeader = UserRole::isBlockLeader((int)($user->get('i_admin') ?? 0));

        if (!$isAdmin && !$isBlockLeader) {
            $this->Flash->error('この画面を表示する権限がありません。');
            return $this->redirect(['controller' => 'Pages', 'action' => 'display', 'home']);
        }

        $query = $this->fetchTable('TApprovalLog')->find()
            ->contain(['MUserInfo', 'MRoomInfo', 'Approvers']);

        // ブロック長の場合は自分が担当する部屋のログ、または自分が承認したログのみに制限
        if (!$isAdmin && $isBlockLeader) {
            $roomAccessService = new \App\Service\RoomAccessService();
            $myRoomIds = $roomAccessService->getUserRoomIds((int)$user->get('i_id_user'));
            $query->where([
                'OR' => [
                    'TApprovalLog.i_id_room IN' => $myRoomIds,
                    'TApprovalLog.i_approver_id' => (int)$user->get('i_id_user'),
                ]
            ]);
        }

        $query->order(['TApprovalLog.dt_create' => 'DESC']);

        $logs = $this->paginate($query, [
            'limit' => 50,
        ]);

        $this->set(compact('logs'));
        return null;
    }

    // ------------------------------------------------------------------
    // helpers
    // ------------------------------------------------------------------

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

    private function jsonForbidden(): Response
    {
        return $this->jsonError('権限がありません', 403);
    }
}