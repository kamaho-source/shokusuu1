<?php
declare(strict_types=1);

namespace App\Controller;

use App\Policy\ApprovalPolicy;
use App\Service\ApprovalService;
use App\Service\RoomAccessService;
use Cake\Http\Response;

/**
 * 承認フロー コントローラー
 *
 * ブロック長（i_admin = 2）と管理者（i_admin = 1）向けの承認画面を提供する。
 * Authorization は skipAuthorization() + ApprovalPolicy の直接呼び出しで制御する。
 */
class ApprovalController extends AppController
{
    private ApprovalService $approvalService;
    private RoomAccessService $roomAccessService;
    private ApprovalPolicy $approvalPolicy;

    public function initialize(): void
    {
        parent::initialize();
        $this->approvalService   = new ApprovalService();
        $this->roomAccessService = new RoomAccessService();
        $this->approvalPolicy    = new ApprovalPolicy();
    }

    // ------------------------------------------------------------------
    // ブロック長用 承認一覧
    // ------------------------------------------------------------------

    /**
     * GET /Approval/blockLeaderIndex
     */
    public function blockLeaderIndex(): ?Response
    {
        $this->Authorization->skipAuthorization();

        $user = $this->Authentication->getIdentity();
        if (!$this->approvalPolicy->canBlockLeaderIndex($user, null)) {
            return $this->jsonForbidden();
        }

        $userId  = (int)$user->get('i_id_user');
        $roomIds = $this->roomAccessService->getUserRoomIds($userId);

        $filterRoomId = $this->request->getQuery('room_id') ? (int)$this->request->getQuery('room_id') : null;
        $filterStatus = $this->request->getQuery('status') !== null && $this->request->getQuery('status') !== ''
            ? (int)$this->request->getQuery('status')
            : ApprovalService::STATUS_BLOCK_LEADER;
        $dateFrom = $this->request->getQuery('date_from') ?? date('Y-m-d', strtotime('monday this week'));
        $dateTo   = $this->request->getQuery('date_to')   ?? date('Y-m-d', strtotime('sunday this week'));

        $records = $this->approvalService->getBlockLeaderList(
            $userId, $filterRoomId, $dateFrom, $dateTo, $filterStatus
        );

        $roomTable = $this->fetchTable('MRoomInfo');
        $rooms = $roomTable->find()
            ->where(['i_id_room IN' => empty($roomIds) ? [0] : $roomIds, 'i_del_flg' => 0])
            ->order(['i_disp_no' => 'ASC'])
            ->all()
            ->combine('i_id_room', 'c_room_name')
            ->toArray();

        $this->set(compact('records', 'rooms', 'filterRoomId', 'filterStatus', 'dateFrom', 'dateTo'));
        return null;
    }

    /**
     * POST /Approval/blockLeaderApprove
     */
    public function blockLeaderApprove(): Response
    {
        $this->Authorization->skipAuthorization();
        $this->request->allowMethod('post');

        $user = $this->Authentication->getIdentity();
        if (!$this->approvalPolicy->canBlockLeaderApprove($user, null)) {
            return $this->jsonForbidden();
        }

        $approver = (int)$user->get('i_id_user');
        $actor    = $user->get('c_login_account') ?? (string)$approver;
        $keys     = (array)($this->request->getData('keys') ?? []);

        if (empty($keys)) {
            return $this->jsonError('対象が指定されていません', 400);
        }

        try {
            $ok = $this->approvalService->blockLeaderApprove($keys, $approver, $actor);
            return $this->jsonResponse(['success' => $ok]);
        } catch (\Throwable $e) {
            return $this->jsonError($e->getMessage(), 500);
        }
    }

    /**
     * POST /Approval/blockLeaderReject
     */
    public function blockLeaderReject(): Response
    {
        $this->Authorization->skipAuthorization();
        $this->request->allowMethod('post');

        $user = $this->Authentication->getIdentity();
        if (!$this->approvalPolicy->canBlockLeaderReject($user, null)) {
            return $this->jsonForbidden();
        }

        $approver = (int)$user->get('i_id_user');
        $actor    = $user->get('c_login_account') ?? (string)$approver;
        $keys     = (array)($this->request->getData('keys') ?? []);
        $reason   = $this->request->getData('reason');

        if (empty($keys)) {
            return $this->jsonError('対象が指定されていません', 400);
        }

        try {
            $ok = $this->approvalService->reject($keys, $approver, $actor, $reason);
            return $this->jsonResponse(['success' => $ok]);
        } catch (\Throwable $e) {
            return $this->jsonError($e->getMessage(), 500);
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
        $this->Authorization->skipAuthorization();

        $user = $this->Authentication->getIdentity();
        if (!$this->approvalPolicy->canAdminIndex($user, null)) {
            return $this->jsonForbidden();
        }

        $filterRoomId = $this->request->getQuery('room_id') ? (int)$this->request->getQuery('room_id') : null;
        $filterStatus = $this->request->getQuery('status') !== null && $this->request->getQuery('status') !== ''
            ? (int)$this->request->getQuery('status')
            : ApprovalService::STATUS_BLOCK_LEADER;
        $dateFrom = $this->request->getQuery('date_from') ?? date('Y-m-d', strtotime('monday this week'));
        $dateTo   = $this->request->getQuery('date_to')   ?? date('Y-m-d', strtotime('sunday this week'));

        $records = $this->approvalService->getAdminList($filterRoomId, $dateFrom, $dateTo, $filterStatus);
        $summary = $this->approvalService->getAdminSummary($dateFrom, $dateTo);

        $roomTable = $this->fetchTable('MRoomInfo');
        $rooms = $roomTable->find()
            ->where(['i_del_flg' => 0])
            ->order(['i_disp_no' => 'ASC'])
            ->all()
            ->combine('i_id_room', 'c_room_name')
            ->toArray();

        $this->set(compact('records', 'summary', 'rooms', 'filterRoomId', 'filterStatus', 'dateFrom', 'dateTo'));
        return null;
    }

    /**
     * POST /Approval/adminApprove
     */
    public function adminApprove(): Response
    {
        $this->Authorization->skipAuthorization();
        $this->request->allowMethod('post');

        $user = $this->Authentication->getIdentity();
        if (!$this->approvalPolicy->canAdminApprove($user, null)) {
            return $this->jsonForbidden();
        }

        $approver = (int)$user->get('i_id_user');
        $actor    = $user->get('c_login_account') ?? (string)$approver;
        $keys     = (array)($this->request->getData('keys') ?? []);

        if (empty($keys)) {
            return $this->jsonError('対象が指定されていません', 400);
        }

        try {
            $ok = $this->approvalService->adminApprove($keys, $approver, $actor);
            return $this->jsonResponse(['success' => $ok]);
        } catch (\Throwable $e) {
            return $this->jsonError($e->getMessage(), 500);
        }
    }

    /**
     * POST /Approval/adminReject
     */
    public function adminReject(): Response
    {
        $this->Authorization->skipAuthorization();
        $this->request->allowMethod('post');

        $user = $this->Authentication->getIdentity();
        if (!$this->approvalPolicy->canAdminReject($user, null)) {
            return $this->jsonForbidden();
        }

        $approver = (int)$user->get('i_id_user');
        $actor    = $user->get('c_login_account') ?? (string)$approver;
        $keys     = (array)($this->request->getData('keys') ?? []);
        $reason   = $this->request->getData('reason');

        if (empty($keys)) {
            return $this->jsonError('対象が指定されていません', 400);
        }

        try {
            $ok = $this->approvalService->reject($keys, $approver, $actor, $reason);
            return $this->jsonResponse(['success' => $ok]);
        } catch (\Throwable $e) {
            return $this->jsonError($e->getMessage(), 500);
        }
    }

    /**
     * POST /Approval/adminReflect
     */
    public function adminReflect(): Response
    {
        $this->Authorization->skipAuthorization();
        $this->request->allowMethod('post');

        $user = $this->Authentication->getIdentity();
        if (!$this->approvalPolicy->canAdminReflect($user, null)) {
            return $this->jsonForbidden();
        }

        $actor  = $user->get('c_login_account') ?? (string)$user->get('i_id_user');
        $roomId = $this->request->getData('room_id') ? (int)$this->request->getData('room_id') : null;
        $date   = $this->request->getData('date');

        try {
            $count = $this->approvalService->reflectToReservation($roomId, $date, $actor);
            return $this->jsonResponse(['success' => true, 'count' => $count]);
        } catch (\Throwable $e) {
            return $this->jsonError($e->getMessage(), 500);
        }
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
