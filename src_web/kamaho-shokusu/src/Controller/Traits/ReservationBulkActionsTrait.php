<?php
declare(strict_types=1);

namespace App\Controller\Traits;

trait ReservationBulkActionsTrait
{
    protected function runGetUsersByRoomForBulk($roomId)
    {
        $date = $this->request->getQuery('date');
        $page = (int)$this->request->getQuery('page', 1);
        $limit = (int)$this->request->getQuery('limit', 100);
        if ($page < 1) {
            $page = 1;
        }
        if ($limit < 1) {
            $limit = 100;
        }
        if ($limit > 500) {
            $limit = 500;
        }

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

    protected function runGetReservationSnapshots()
    {
        $data = $this->request->getData();
        $roomId = (int)($data['room_id'] ?? 0);
        $dates = isset($data['dates']) && is_array($data['dates']) ? $data['dates'] : [];
        $dates = array_values(array_filter($dates, static fn($d) => is_string($d) && $d !== ''));

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

    protected function runBulkAddForm()
    {
        $selectedDate = $this->request->getQuery('date');
        $bulkFormService = $this->bulkFormService;

        if (!$selectedDate) {
            $this->Flash->error(__('日付が指定されていません。'));
            return $this->redirect(['action' => 'index']);
        }

        try {
            $startDate = new \DateTime($selectedDate);
            $startDate->modify('monday this week');
        } catch (\Exception $e) {
            $this->Flash->error(__('無効な日付が指定されました。'));
            return $this->redirect(['action' => 'index']);
        }

        $userId = $this->request->getAttribute('identity')->get('i_id_user');
        $rooms = $bulkFormService->getRoomsForUser($this->MRoomInfo, (int)$userId);

        $selectedRoomId = $this->request->getQuery('room_id') ?? '';
        $baseWeekParam = $this->request->getQuery('base_week');
        $formData = $bulkFormService->buildBulkAddData((string)$selectedDate, $baseWeekParam);
        $canGroup = ((int)$this->request->getAttribute('identity')->get('i_admin') === 1
            || (int)$this->request->getAttribute('identity')->get('i_user_level') === 0);

        $this->set(compact(
            'rooms',
            'selectedDate',
            'selectedRoomId',
            'canGroup'
        ) + $formData);

        return null;
    }

    protected function runBulkChangeEditForm()
    {
        $selectedDate = $this->request->getQuery('date');
        $bulkFormService = $this->bulkFormService;
        if (!$selectedDate) {
            $this->Flash->error(__('日付が指定されていません。'));
            return $this->redirect(['action' => 'index']);
        }

        $userId = $this->request->getAttribute('identity')->get('i_id_user');
        $rooms = $bulkFormService->getRoomsForUser($this->MRoomInfo, (int)$userId);

        $selectedRoomId = $this->request->getQuery('room_id') ?? '';
        $baseWeekParam = $this->request->getQuery('base_week');
        $formData = $bulkFormService->buildBulkChangeEditData((string)$selectedDate, $baseWeekParam);

        $this->set(compact(
            'rooms',
            'selectedDate',
            'selectedRoomId'
        ) + $formData);

        return null;
    }

    protected function runBulkChangeEditSubmit()
    {
        if ($denied = $this->authorizeReservation('bulkChangeEditSubmit', [], true)) {
            return $denied;
        }

        $data = $this->request->getData();
        $dayUsers = isset($data['day_users']) && is_array($data['day_users']) ? $data['day_users'] : [];
        $snapshots = isset($data['reservation_snapshot']) && is_array($data['reservation_snapshot'])
            ? $data['reservation_snapshot']
            : [];
        $roomId = $data['i_id_room'] ?? null;

        if (!$roomId || empty($dayUsers)) {
            return $this->apiResponseService->error(
                $this->response,
                '部屋または予約内容が指定されていません。',
                400
            );
        }

        $loginUser = $this->request->getAttribute('identity');
        $loginName = $loginUser?->get('c_user_name') ?? 'system';

        $bulkService = $this->bulkService;
        $result = $bulkService->processBulkChangeEdit(
            $dayUsers,
            (int)$roomId,
            (string)$loginName,
            $this->TIndividualReservationInfo,
            $this->MUserInfo,
            $snapshots
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
                'updated' => $result['updated'] ?? 0,
                'created' => $result['created'] ?? 0,
                'redirect' => './',
            ],
            '直前編集を保存しました。'
        );
    }

    protected function runBulkAddSubmit()
    {
        if ($denied = $this->authorizeReservation('bulkAddSubmit', [], true)) {
            return $denied;
        }

        $data = $this->request->getData();

        try {
            $userName = $this->request->getAttribute('identity')->get('c_user_name');
            $userId = $this->request->getAttribute('identity')->get('i_id_user');

            $bulkService = $this->bulkService;
            $result = $bulkService->processBulkAdd(
                $data,
                (int)$userId,
                (string)$userName,
                $this->TIndividualReservationInfo,
                $this->MUserInfo,
                $this->MRoomInfo
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
                'エラーが発生しました: ' . $e->getMessage(),
                200
            );
        }
    }
}
