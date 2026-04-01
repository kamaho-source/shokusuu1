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
 * ブロック長（i_user_level = 2）と管理者（i_admin = 1）向けの承認画面を提供する。
 */
class ApprovalController extends AppController
{
    private ApprovalService $approvalService;
    private RoomAccessService $roomAccessService;
    private ApprovalPolicy $policy;

    public function initialize(): void
    {
        parent::initialize();
        $this->approvalService   = new ApprovalService();
        $this->roomAccessService = new RoomAccessService();
        $this->policy            = new ApprovalPolicy();
    }

    // ------------------------------------------------------------------
    // ブロック長用 承認一覧
    // ------------------------------------------------------------------

    /**
     * GET /approval/block-leader-index
     */
    public function blockLeaderIndex(): void
    {
        $user = $this->Authentication->getIdentity();
        $this->Authorization->authorize($this, 'blockLeaderIndex');

        $userId = (int)$user->get('i_id_user');
        $roomIds = $this->roomAccessService->getUserRoomIds($userId);

        // フィルタ取得
        $filterRoomId  = $this->request->getQuery('room_id')   ? (int)$this->request->getQuery('room_id')   : null;
        $filterStatus  = $this->request->getQuery('status')    !== null ? (int)$this->request->getQuery('status') : null;
        $dateFrom      = $this->request->getQuery('date_from') ?? date('Y-m-d', strtotime('monday this week'));
        $dateTo        = $this->request->getQuery('date_to')   ?? date('Y-m-d', strtotime('sunday this week'));

        $records = $this->approvalService->getBlockLeaderList(
            $userId,
            $filterRoomId,
            $dateFrom,
            $dateTo,
            $filterStatus
        );

        // ブロック一覧（担当分のみ）
        $roomTable = $this->fetchTable('MRoomInfo');
        $rooms = $roomTable->find()
            ->where(['i_id_room IN' => $roomIds, 'i_enable' => 1, 'i_del_flg' => 0])
            ->order(['i_disp_no' => 'ASC'])
            ->all()
            ->combine('i_id_room', 'c_room_name')
            ->toArray();

        $this->set(compact('records', 'rooms', 'filterRoomId', 'filterStatus', 'dateFrom', 'dateTo'));
    }

    /**
     * POST /approval/block-leader-approve
     * JSON: { "keys": [{"i_id_user":1,"d_reservation_date":"2026-04-01","i_id_room":1,"i_reservation_type":1}, ...] }
     */
    public function blockLeaderApprove(): ?Response
    {
        $this->request->allowMethod('post');
        $this->Authorization->authorize($this, 'blockLeaderApprove');

        $user     = $this->Authentication->getIdentity();
        $approver = (int)$user->get('i_id_user');
        $actor    = $user->get('c_login_account') ?? (string)$approver;
        $keys     = (array)($this->request->getData('keys') ?? []);

        if (empty($keys)) {
            return $this->response->withStatus(400)->withStringBody(json_encode(['error' => '対象が指定されていません']));
        }

        $ok = $this->approvalService->blockLeaderApprove($keys, $approver, $actor);

        $this->response = $this->response->withType('application/json');
        return $this->response->withStringBody(json_encode(['success' => $ok]));
    }

    /**
     * POST /approval/block-leader-reject
     * JSON: { "keys": [...], "reason": "差し戻し理由" }
     */
    public function blockLeaderReject(): ?Response
    {
        $this->request->allowMethod('post');
        $this->Authorization->authorize($this, 'blockLeaderReject');

        $user     = $this->Authentication->getIdentity();
        $approver = (int)$user->get('i_id_user');
        $actor    = $user->get('c_login_account') ?? (string)$approver;
        $keys     = (array)($this->request->getData('keys') ?? []);
        $reason   = $this->request->getData('reason');

        if (empty($keys)) {
            return $this->response->withStatus(400)->withStringBody(json_encode(['error' => '対象が指定されていません']));
        }

        $ok = $this->approvalService->reject($keys, $approver, $actor, $reason);

        $this->response = $this->response->withType('application/json');
        return $this->response->withStringBody(json_encode(['success' => $ok]));
    }

    // ------------------------------------------------------------------
    // 管理者用 承認一覧
    // ------------------------------------------------------------------

    /**
     * GET /approval/admin-index
     */
    public function adminIndex(): void
    {
        $this->Authorization->authorize($this, 'adminIndex');

        $filterRoomId = $this->request->getQuery('room_id') ? (int)$this->request->getQuery('room_id') : null;
        $filterStatus = $this->request->getQuery('status')  !== null ? (int)$this->request->getQuery('status') : null;
        $dateFrom     = $this->request->getQuery('date_from') ?? date('Y-m-d', strtotime('monday this week'));
        $dateTo       = $this->request->getQuery('date_to')   ?? date('Y-m-d', strtotime('sunday this week'));

        $records = $this->approvalService->getAdminList($filterRoomId, $dateFrom, $dateTo, $filterStatus);
        $summary = $this->approvalService->getAdminSummary($dateFrom, $dateTo);

        // 全ブロック一覧
        $roomTable = $this->fetchTable('MRoomInfo');
        $rooms = $roomTable->find()
            ->where(['i_enable' => 1, 'i_del_flg' => 0])
            ->order(['i_disp_no' => 'ASC'])
            ->all()
            ->combine('i_id_room', 'c_room_name')
            ->toArray();

        $this->set(compact('records', 'summary', 'rooms', 'filterRoomId', 'filterStatus', 'dateFrom', 'dateTo'));
    }

    /**
     * POST /approval/admin-approve
     * JSON: { "keys": [...] }
     */
    public function adminApprove(): ?Response
    {
        $this->request->allowMethod('post');
        $this->Authorization->authorize($this, 'adminApprove');

        $user     = $this->Authentication->getIdentity();
        $approver = (int)$user->get('i_id_user');
        $actor    = $user->get('c_login_account') ?? (string)$approver;
        $keys     = (array)($this->request->getData('keys') ?? []);

        if (empty($keys)) {
            return $this->response->withStatus(400)->withStringBody(json_encode(['error' => '対象が指定されていません']));
        }

        $ok = $this->approvalService->adminApprove($keys, $approver, $actor);

        $this->response = $this->response->withType('application/json');
        return $this->response->withStringBody(json_encode(['success' => $ok]));
    }

    /**
     * POST /approval/admin-reject
     * JSON: { "keys": [...], "reason": "差し戻し理由" }
     */
    public function adminReject(): ?Response
    {
        $this->request->allowMethod('post');
        $this->Authorization->authorize($this, 'adminReject');

        $user     = $this->Authentication->getIdentity();
        $approver = (int)$user->get('i_id_user');
        $actor    = $user->get('c_login_account') ?? (string)$approver;
        $keys     = (array)($this->request->getData('keys') ?? []);
        $reason   = $this->request->getData('reason');

        if (empty($keys)) {
            return $this->response->withStatus(400)->withStringBody(json_encode(['error' => '対象が指定されていません']));
        }

        $ok = $this->approvalService->reject($keys, $approver, $actor, $reason);

        $this->response = $this->response->withType('application/json');
        return $this->response->withStringBody(json_encode(['success' => $ok]));
    }

    /**
     * POST /approval/admin-reflect
     * JSON: { "room_id": 1, "date": "2026-04-01" }  ※ どちらも省略可（全件反映）
     */
    public function adminReflect(): ?Response
    {
        $this->request->allowMethod('post');
        $this->Authorization->authorize($this, 'adminReflect');

        $user   = $this->Authentication->getIdentity();
        $actor  = $user->get('c_login_account') ?? (string)$user->get('i_id_user');
        $roomId = $this->request->getData('room_id') ? (int)$this->request->getData('room_id') : null;
        $date   = $this->request->getData('date');

        $count = $this->approvalService->reflectToReservation($roomId, $date, $actor);

        $this->response = $this->response->withType('application/json');
        return $this->response->withStringBody(json_encode(['success' => true, 'count' => $count]));
    }

    // ------------------------------------------------------------------
    // Authorization hook
    // ------------------------------------------------------------------

    public function isAuthorized(mixed $user): bool
    {
        return true; // Policy で判断するため常に true
    }
}
