<?php
declare(strict_types=1);

namespace App\Controller;

use App\Domain\ValueObject\UserRole;
use App\Service\BulkReservationFormService;
use App\Service\ReservationBulkService;
use Cake\Http\Response;

/**
 * 一括予約操作専用コントローラー。
 *
 * 週次一括登録・直前一括編集フォームおよびその送信を担当する。
 */
class ReservationBulkController extends ReservationBaseController
{
    private BulkReservationFormService $bulkFormService;
    private ReservationBulkService $bulkService;

    public function initialize(): void
    {
        parent::initialize();

        $this->bulkFormService = new BulkReservationFormService();
        $this->bulkService     = new ReservationBulkService();

        $this->FormProtection->setConfig('unlockedActions', [
            'bulkAddForm',
            'bulkChangeEditForm',
            'bulkChangeEditSubmit',
            'bulkAddSubmit',
            'getReservationSnapshots',
        ]);
    }

    /**
     * 部屋別利用者一覧（一括登録用）取得API。
     *
     * @param int $roomId
     * @return Response|null
     */
    public function getUsersByRoomForBulk($roomId): ?Response
    {
        if ($denied = $this->authorizeReservation('getUsersByRoomForBulk', ['i_id_room' => (int)$roomId], true)) {
            return $denied;
        }

        $date  = $this->request->getQuery('date');
        $page  = (int)$this->request->getQuery('page', 1);
        $limit = (int)$this->request->getQuery('limit', 100);
        if ($page < 1) {
            $page = 1;
        }
        $limit = max(1, min(500, $limit));

        $payload = $this->queryService->getUsersByRoomForBulk(
            $this->MUserGroup,
            $this->TIndividualReservationInfo,
            (int)$roomId,
            $date,
            $page,
            $limit
        );

        return $this->apiResponseService->success($this->response, $payload);
    }

    /**
     * 予約スナップショット取得API（楽観的ロック用）。
     *
     * @return Response|null
     */
    public function getReservationSnapshots(): ?Response
    {
        $roomId = (int)($this->request->getData('room_id') ?? 0);
        if ($denied = $this->authorizeReservation('getReservationSnapshots', ['i_id_room' => $roomId], true)) {
            return $denied;
        }

        $data   = $this->request->getData();
        $roomId = (int)($data['room_id'] ?? 0);
        $dates  = isset($data['dates']) && is_array($data['dates']) ? $data['dates'] : [];
        $dates  = array_values(array_filter($dates, static fn($d) => is_string($d) && $d !== ''));

        if (!$roomId || empty($dates)) {
            return $this->apiResponseService->error($this->response, 'room_id または dates が不足しています。', 400);
        }

        $map = $this->queryService->getReservationSnapshots(
            $this->TIndividualReservationInfo,
            $roomId,
            $dates
        );

        return $this->apiResponseService->success($this->response, ['snapshots' => $map], 'ok');
    }

    /**
     * 週次一括登録フォーム画面。
     *
     * @return Response|null
     */
    public function bulkAddForm(): ?Response
    {
        $this->authorizeReservation('bulkAddForm');

        if ($r = $this->rejectIfPlanBlocked($this->planGuard->allowsWeeklyBulk())) {
            return $r;
        }

        $selectedDate = $this->request->getQuery('date');
        if (!$selectedDate) {
            $this->Flash->error(__('日付が指定されていません。'));
            return $this->redirect(['controller' => 'TReservationInfo', 'action' => 'index']);
        }

        try {
            $startDate = new \DateTime($selectedDate);
            $startDate->modify('monday this week');
        } catch (\Exception $e) {
            $this->Flash->error(__('無効な日付が指定されました。'));
            return $this->redirect(['controller' => 'TReservationInfo', 'action' => 'index']);
        }

        $userId = $this->request->getAttribute('identity')->get('i_id_user');
        $rooms  = $this->bulkFormService->getRoomsForUser($this->MRoomInfo, (int)$userId);

        $selectedRoomId = $this->request->getQuery('room_id') ?? '';
        $baseWeekParam  = $this->request->getQuery('base_week');
        $formData       = $this->bulkFormService->buildBulkAddData((string)$selectedDate, $baseWeekParam);
        $canGroup       = (UserRole::isAdmin((int)$this->request->getAttribute('identity')->get('i_admin'))
            || (int)$this->request->getAttribute('identity')->get('i_user_level') === 0);
        $isAdmin        = UserRole::isAdmin((int)$this->request->getAttribute('identity')->get('i_admin'));
        $isBlockLeader  = UserRole::isBlockLeader((int)$this->request->getAttribute('identity')->get('i_admin'));
        $loginRoomIds   = array_keys($rooms);
        $user           = $this->request->getAttribute('identity');

        $this->set(compact('rooms', 'selectedDate', 'selectedRoomId', 'canGroup', 'isAdmin', 'isBlockLeader', 'loginRoomIds', 'user') + $formData);

        $this->viewBuilder()->setTemplatePath('TReservationInfo');
        return null;
    }

    /**
     * 直前一括編集フォーム画面。
     *
     * @return Response|null
     */
    public function bulkChangeEditForm(): ?Response
    {
        $this->authorizeReservation('bulkChangeEditForm');

        if ($r = $this->rejectIfPlanBlocked($this->planGuard->allowsWeeklyBulk())) {
            return $r;
        }

        $selectedDate = $this->request->getQuery('date');
        if (!$selectedDate) {
            $this->Flash->error(__('日付が指定されていません。'));
            return $this->redirect(['controller' => 'TReservationInfo', 'action' => 'index']);
        }

        $userId = $this->request->getAttribute('identity')->get('i_id_user');
        $rooms  = $this->bulkFormService->getRoomsForUser($this->MRoomInfo, (int)$userId);

        $selectedRoomId = $this->request->getQuery('room_id') ?? '';
        $baseWeekParam  = $this->request->getQuery('base_week');
        $formData       = $this->bulkFormService->buildBulkChangeEditData((string)$selectedDate, $baseWeekParam);
        $isAdmin        = UserRole::isAdmin((int)$this->request->getAttribute('identity')->get('i_admin'));
        $isBlockLeader  = UserRole::isBlockLeader((int)$this->request->getAttribute('identity')->get('i_admin'));
        $loginRoomIds   = array_keys($rooms);
        $user           = $this->request->getAttribute('identity');

        $this->set(compact('rooms', 'selectedDate', 'selectedRoomId', 'isAdmin', 'isBlockLeader', 'loginRoomIds', 'user') + $formData);

        $this->viewBuilder()->setTemplatePath('TReservationInfo');
        return null;
    }

    /**
     * 直前一括編集送信API。
     *
     * @return Response|null
     */
    public function bulkChangeEditSubmit(): ?Response
    {
        if ($denied = $this->authorizeReservation('bulkChangeEditSubmit', [], true)) {
            return $denied;
        }

        $data     = $this->request->getData();
        $dayUsers = isset($data['day_users']) && is_array($data['day_users']) ? $data['day_users'] : [];
        $snapshots = isset($data['reservation_snapshot']) && is_array($data['reservation_snapshot'])
            ? $data['reservation_snapshot']
            : [];
        $roomId   = $data['i_id_room'] ?? null;

        if (!$roomId || empty($dayUsers)) {
            return $this->apiResponseService->error(
                $this->response,
                '部屋または予約内容が指定されていません。',
                400
            );
        }

        $loginUser = $this->request->getAttribute('identity');
        if (!$loginUser) {
            return $this->apiResponseService->forbidden($this->response);
        }
        $loginName = $loginUser->get('c_user_name') ?? 'system';

        $result = $this->bulkService->processBulkChangeEdit(
            $dayUsers,
            (int)$roomId,
            (string)$loginName,
            $this->TIndividualReservationInfo,
            $this->MUserInfo,
            $snapshots,
            (int)($loginUser?->get('i_id_user') ?? 0),
            UserRole::isAdmin((int)($loginUser?->get('i_admin') ?? 0)),
            (int)($loginUser?->get('i_user_level') ?? 0),
            UserRole::isBlockLeader((int)($loginUser?->get('i_admin') ?? 0))
        );

        if (!$result['ok']) {
            $conflictDate = $result['conflict_date'] ?? null;
            return $this->apiResponseService->error(
                $this->response,
                $result['message'] ?? '直前編集の保存中にエラーが発生しました。',
                $conflictDate ? 409 : 422,
                ['conflict_date' => $conflictDate]
            );
        }

        return $this->apiResponseService->success(
            $this->response,
            [
                'updated'  => $result['updated'] ?? 0,
                'created'  => $result['created'] ?? 0,
                'redirect' => './',
            ],
            '直前編集を保存しました。'
        );
    }

    /**
     * 週次一括登録送信API。
     *
     * @return Response|null
     */
    public function bulkAddSubmit(): ?Response
    {
        if ($denied = $this->authorizeReservation('bulkAddSubmit', [], true)) {
            return $denied;
        }

        $data = $this->request->getData();

        try {
            $userName = $this->request->getAttribute('identity')->get('c_user_name');
            $userId   = $this->request->getAttribute('identity')->get('i_id_user');

            $result = $this->bulkService->processBulkAdd(
                $data,
                (int)$userId,
                (string)$userName,
                $this->TIndividualReservationInfo,
                $this->MUserInfo,
                $this->MRoomInfo,
                UserRole::isAdmin((int)($this->request->getAttribute('identity')->get('i_admin') ?? 0)),
                (int)($this->request->getAttribute('identity')->get('i_user_level') ?? 0),
                UserRole::isBlockLeader((int)($this->request->getAttribute('identity')->get('i_admin') ?? 0))
            );

            if (!$result['ok']) {
                $conflictDate = $result['conflict_date'] ?? null;
                return $this->apiResponseService->error(
                    $this->response,
                    $result['message'] ?? 'エラーが発生しました。',
                    200,
                    ['conflict_date' => $conflictDate]
                );
            }

            return $this->apiResponseService->success(
                $this->response,
                ['redirect' => $result['redirect_url'] ?? './'],
                $result['message'] ?? 'すべての予約が正常に登録されました。'
            );
        } catch (\Throwable $e) {
            $this->log('Error occurred: ' . $e->getMessage(), 'error');
            return $this->apiResponseService->error(
                $this->response,
                '一括予約の登録中にエラーが発生しました。',
                500
            );
        }
    }
}
