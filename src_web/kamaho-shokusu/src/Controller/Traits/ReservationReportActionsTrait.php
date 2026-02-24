<?php
declare(strict_types=1);

namespace App\Controller\Traits;

use Cake\Http\Exception\NotFoundException;
use Cake\I18n\Date;
use Cake\I18n\DateTime;
use Cake\Log\Log;

trait ReservationReportActionsTrait
{
    protected function runGetMealCounts($date)
    {
        $reportService = $this->reportService;
        return $reportService->getMealCounts($this->TIndividualReservationInfo, $date);
    }

    protected function runGetUsersByRoomForEdit($roomId)
    {
        $date = $this->request->getQuery('date');
        $this->request->allowMethod(['get']);
        $this->autoRender = false;

        $reportService = $this->reportService;
        $completeUserInfo = $reportService->getUsersByRoomForEdit(
            $this->MUserGroup,
            $this->TIndividualReservationInfo,
            (int)$roomId,
            (string)$date
        );

        return $this->apiResponseService->success($this->response, ['usersByRoom' => $completeUserInfo]);
    }

    protected function runExportJson()
    {
        try {
            $from = $this->request->getQuery('from');
            $to   = $this->request->getQuery('to');

            if (!$from || !$to) {
                throw new \InvalidArgumentException(
                    '開始日・終了日を指定してください (例: from=2025-07-01&to=2025-07-15)'
                );
            }

            try {
                $startDate = new \DateTimeImmutable($from);
                $endDate   = new \DateTimeImmutable($to);
            } catch (\Exception $e) {
                throw new \InvalidArgumentException('日付の形式が正しくありません (YYYY-MM-DD)');
            }

            if ($startDate > $endDate) {
                throw new \InvalidArgumentException('開始日は終了日以前の日付を指定してください');
            }

            $reportService = $this->reportService;
            $result = $reportService->buildExportJson(
                $this->TIndividualReservationInfo,
                $startDate->format('Y-m-d'),
                $endDate->format('Y-m-d')
            );

            return $this->apiResponseService->success($this->response, $result);
        } catch (\Throwable $e) {
            Log::write('error', $e->getMessage());
            if ($e instanceof \InvalidArgumentException) {
                return $this->apiResponseService->error($this->response, $e->getMessage(), 400);
            }

            return $this->apiResponseService->error($this->response, 'エクスポート処理中にエラーが発生しました。', 500);
        }
    }

    protected function runExportJsonrank()
    {
        $this->autoRender = false;

        $from  = $this->request->getQuery('from');
        $to    = $this->request->getQuery('to');
        $month = $this->request->getQuery('month');

        $isDate  = static fn($d) => (bool)preg_match('/^\d{4}-\d{2}-\d{2}$/', $d);
        $isMonth = static fn($m) => (bool)preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $m);

        if ($from !== null || $to !== null) {
            if (!$from || !$to || !$isDate($from) || !$isDate($to) || strtotime($from) > strtotime($to)) {
                return $this->apiResponseService->error($this->response, '無効な期間が指定されました。', 400);
            }
            $startDate = $from;
            $endDate   = date('Y-m-d', strtotime($to . ' +1 day'));
            $emptyMsg  = '指定された期間にデータが見つかりませんでした。';
        } else {
            if (!$month || !$isMonth($month)) {
                return $this->apiResponseService->error($this->response, '無効な月が指定されました。', 400);
            }
            $startDate = $month . '-01';
            $endDate   = date('Y-m-d', strtotime($month . ' +1 month'));
            $emptyMsg  = '指定された月にデータが見つかりませんでした。';
        }

        $reportService = $this->reportService;
        $finalOutput = $reportService->buildExportJsonRank(
            $this->TIndividualReservationInfo,
            $startDate,
            $endDate,
            $emptyMsg
        );

        return $this->apiResponseService->success($this->response, $finalOutput);
    }

    protected function runReportNoMeal()
    {
        $this->request->allowMethod(['post']);

        $loginUser = $this->request->getAttribute('identity');
        $userId = (int)($loginUser?->get('i_id_user') ?? 0);
        $userName = (string)($loginUser?->get('c_user_name') ?? $userId);
        if ($userId <= 0) {
            return $this->apiResponseService->error($this->response, 'Unauthorized', 401);
        }

        $today = Date::today('Asia/Tokyo')->format('Y-m-d');
        $roomRow = $this->MUserGroup->find()
            ->enableAutoFields(false)
            ->select(['i_id_room'])
            ->where(['i_id_user' => $userId, 'active_flag' => 0])
            ->orderAsc('i_id_room')
            ->first();
        if (!$roomRow) {
            return $this->apiResponseService->error($this->response, '所属部屋が設定されていません。管理者にお問い合わせください。', 400);
        }
        $roomId = (int)$roomRow->i_id_room;

        $mealTypes = [1, 2, 3, 4];
        foreach ($mealTypes as $mealType) {
            $affected = $this->TIndividualReservationInfo->updateAll(
                [
                    'eat_flag' => 0,
                    'i_change_flag' => 0,
                    'c_update_user' => $userName,
                    'dt_update' => DateTime::now('Asia/Tokyo'),
                ],
                [
                    'i_id_user' => $userId,
                    'd_reservation_date' => $today,
                    'i_reservation_type' => $mealType,
                    'i_id_room' => $roomId,
                ]
            );
            if ($affected === 0) {
                $entity = $this->TIndividualReservationInfo->newEmptyEntity();
                $entity->i_id_user = $userId;
                $entity->d_reservation_date = $today;
                $entity->i_reservation_type = $mealType;
                $entity->i_id_room = $roomId;
                $entity->eat_flag = 0;
                $entity->i_change_flag = 0;
                $entity->c_create_user = $userName;
                $entity->dt_create = DateTime::now('Asia/Tokyo');
                if (!$this->TIndividualReservationInfo->save($entity)) {
                    return $this->apiResponseService->error($this->response, '報告の保存に失敗しました。', 500);
                }
            }
        }

        \Cake\Cache\Cache::delete(sprintf('today_report:%d:%s', $userId, $today), 'default');
        \Cake\Cache\Cache::delete('meal_counts:' . $today, 'default');
        \Cake\Cache\Cache::delete(sprintf('users_by_room_edit:%d:%s', $roomId, $today), 'default');

        return $this->apiResponseService->success($this->response, [], '食べないで報告しました。');
    }

    protected function runReportEat()
    {
        $this->request->allowMethod(['post']);

        $loginUser = $this->request->getAttribute('identity');
        $userId = (int)($loginUser?->get('i_id_user') ?? 0);
        $userName = (string)($loginUser?->get('c_user_name') ?? $userId);
        if ($userId <= 0) {
            return $this->apiResponseService->error($this->response, 'Unauthorized', 401);
        }

        $today = Date::today('Asia/Tokyo')->format('Y-m-d');
        $roomRow = $this->MUserGroup->find()
            ->enableAutoFields(false)
            ->select(['i_id_room'])
            ->where(['i_id_user' => $userId, 'active_flag' => 0])
            ->orderAsc('i_id_room')
            ->first();
        if (!$roomRow) {
            return $this->apiResponseService->error($this->response, '所属部屋が設定されていません。管理者にお問い合わせください。', 400);
        }
        $roomId = (int)$roomRow->i_id_room;

        $mealTypes = [1, 2, 3, 4];
        foreach ($mealTypes as $mealType) {
            $affected = $this->TIndividualReservationInfo->updateAll(
                [
                    'eat_flag' => 1,
                    'i_change_flag' => 1,
                    'c_update_user' => $userName,
                    'dt_update' => DateTime::now('Asia/Tokyo'),
                ],
                [
                    'i_id_user' => $userId,
                    'd_reservation_date' => $today,
                    'i_reservation_type' => $mealType,
                    'i_id_room' => $roomId,
                ]
            );
            if ($affected === 0) {
                $entity = $this->TIndividualReservationInfo->newEmptyEntity();
                $entity->i_id_user = $userId;
                $entity->d_reservation_date = $today;
                $entity->i_reservation_type = $mealType;
                $entity->i_id_room = $roomId;
                $entity->eat_flag = 1;
                $entity->i_change_flag = 1;
                $entity->c_create_user = $userName;
                $entity->dt_create = DateTime::now('Asia/Tokyo');
                if (!$this->TIndividualReservationInfo->save($entity)) {
                    return $this->apiResponseService->error($this->response, '報告の保存に失敗しました。', 500);
                }
            }
        }

        \Cake\Cache\Cache::write(sprintf('today_report:%d:%s', $userId, $today), 1, 'default');
        \Cake\Cache\Cache::delete('meal_counts:' . $today, 'default');
        \Cake\Cache\Cache::delete(sprintf('users_by_room_edit:%d:%s', $roomId, $today), 'default');

        return $this->apiResponseService->success($this->response, [], '食べるで報告しました。');
    }

    protected function runGetAllRoomsMealCounts()
    {
        $this->request->allowMethod(['get']);
        $this->autoRender = false;

        $fromDate = $this->request->getQuery('from');
        $toDate = $this->request->getQuery('to');

        if (!$fromDate || !$toDate) {
            $fromDate = date('Y-m-01', strtotime('-1 month'));
            $toDate = date('Y-m-t', strtotime('+1 month'));
        }

        try {
            $reportService = $this->reportService;
            $result = $reportService->buildAllRoomsMealCounts(
                $this->TIndividualReservationInfo,
                $fromDate,
                $toDate
            );

            return $this->apiResponseService->success($this->response, ['result' => $result]);
        } catch (\Exception $e) {
            return $this->apiResponseService->error($this->response, 'データ取得に失敗しました。', 500);
        }
    }

    protected function runGetRoomMealCounts($roomId = null)
    {
        $this->request->allowMethod(['get']);
        $this->autoRender = false;

        $user = $this->request->getAttribute('identity');
        $userId = (int)($user?->get('i_id_user') ?? 0);
        if ($userId <= 0) {
            return $this->apiResponseService->error($this->response, 'ログインが必要です。', 401);
        }

        $userGroups = $this->MUserGroup->find()
            ->select(['i_id_room'])
            ->where(['i_id_user' => $userId])
            ->toArray();

        $userRoomIds = [];
        foreach ($userGroups as $group) {
            if ($group->i_id_room) {
                $userRoomIds[] = $group->i_id_room;
            }
        }

        if ($roomId) {
            $targetRoomIds = [(int)$roomId];
        } else {
            $targetRoomIds = $userRoomIds;
        }

        if (empty($targetRoomIds)) {
            return $this->apiResponseService->error($this->response, '所属部屋が見つかりません。', 400);
        }

        $fromDate = $this->request->getQuery('from');
        $toDate = $this->request->getQuery('to');

        if (!$fromDate || !$toDate) {
            $fromDate = date('Y-m-01', strtotime('-1 month'));
            $toDate = date('Y-m-t', strtotime('+1 month'));
        }

        try {
            $reportService = $this->reportService;
            $result = $reportService->buildRoomMealCounts(
                $this->TIndividualReservationInfo,
                $targetRoomIds,
                $fromDate,
                $toDate
            );

            return $this->apiResponseService->success($this->response, ['result' => $result]);
        } catch (\Exception $e) {
            return $this->apiResponseService->error($this->response, 'データ取得に失敗しました。', 500);
        }
    }

}
