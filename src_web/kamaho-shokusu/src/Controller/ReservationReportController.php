<?php
declare(strict_types=1);

namespace App\Controller;

use App\Service\MealReportingService;
use App\Service\ReservationReportService;
use Cake\Http\Response;
use Cake\Log\Log;

/**
 * 食数レポート・エクスポート専用コントローラー。
 *
 * 食数取得API・JSONエクスポート・欠食/実食報告を担当する。
 */
class ReservationReportController extends ReservationBaseController
{
    private ReservationReportService $reportService;
    private MealReportingService $mealReportingService;

    public function initialize(): void
    {
        parent::initialize();

        $this->reportService        = new ReservationReportService();
        $this->mealReportingService = new MealReportingService();
    }

    /**
     * 日別食数取得API。
     *
     * @param string $date YYYY-MM-DD
     * @return Response|null
     */
    public function getMealCounts($date): ?Response
    {
        return $this->reportService->getMealCounts($this->TIndividualReservationInfo, $date);
    }

    /**
     * 部屋別利用者一覧（直前編集用）取得API。
     *
     * @param int $roomId
     * @return Response|null
     */
    public function getUsersByRoomForEdit($roomId): ?Response
    {
        if ($denied = $this->authorizeReservation('getUsersByRoomForEdit', ['i_id_room' => (int)$roomId], true)) {
            return $denied;
        }

        $date = $this->request->getQuery('date');
        $this->request->allowMethod(['get']);
        $this->autoRender = false;

        $completeUserInfo = $this->reportService->getUsersByRoomForEdit(
            $this->MUserGroup,
            $this->TIndividualReservationInfo,
            (int)$roomId,
            (string)$date
        );

        return $this->apiResponseService->success($this->response, ['usersByRoom' => $completeUserInfo]);
    }

    /**
     * 月次予約データJSONエクスポートAPI。
     *
     * @return Response
     */
    public function exportJson(): Response
    {
        if ($denied = $this->authorizeReservation('exportJson', [], true)) {
            return $denied;
        }

        try {
            $from = $this->request->getQuery('from');
            $to   = $this->request->getQuery('to');

            if (!$from || !$to) {
                throw new \InvalidArgumentException('開始日・終了日を指定してください (例: from=2025-07-01&to=2025-07-15)');
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

            $result = $this->reportService->buildExportJson(
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

    /**
     * ランク別食数JSONエクスポートAPI。
     *
     * @return Response
     */
    public function exportJsonrank(): Response
    {
        if ($denied = $this->authorizeReservation('exportJsonrank', [], true)) {
            return $denied;
        }

        $this->autoRender = false;

        $from  = $this->request->getQuery('from');
        $to    = $this->request->getQuery('to');
        $month = $this->request->getQuery('month');

        $isDate  = static fn($d) => (bool)preg_match('/^\d{4}-\d{2}-\d{2}$/', $d);
        $isMonth = static fn($m) => (bool)preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $m);

        if ($from !== null || $to !== null) {
            $tsFrom = $from ? strtotime($from) : false;
            $tsTo   = $to   ? strtotime($to)   : false;
            if (!$from || !$to || !$isDate($from) || !$isDate($to) || $tsFrom === false || $tsTo === false || $tsFrom > $tsTo) {
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

        $finalOutput = $this->reportService->buildExportJsonRank(
            $this->TIndividualReservationInfo,
            $startDate,
            $endDate,
            $emptyMsg
        );

        return $this->apiResponseService->success($this->response, $finalOutput);
    }

    /**
     * 欠食報告API。
     *
     * @return Response|null
     */
    public function reportNoMeal(): ?Response
    {
        if ($denied = $this->authorizeReservation('reportNoMeal', [], true)) {
            return $denied;
        }

        $this->request->allowMethod(['post']);

        $loginUser = $this->request->getAttribute('identity');
        $userId    = (int)($loginUser?->get('i_id_user') ?? 0);
        $userName  = (string)($loginUser?->get('c_user_name') ?? $userId);

        if ($userId <= 0) {
            return $this->apiResponseService->error($this->response, 'Unauthorized', 401);
        }

        $result = $this->mealReportingService->reportNoMeal(
            $this->TIndividualReservationInfo,
            $this->MUserGroup,
            $userId,
            $userName
        );

        if (!$result['ok']) {
            return $this->apiResponseService->error($this->response, $result['message'], 400);
        }

        return $this->apiResponseService->success($this->response, [], $result['message']);
    }

    /**
     * 実食報告API。
     *
     * @return Response|null
     */
    public function reportEat(): ?Response
    {
        if ($denied = $this->authorizeReservation('reportEat', [], true)) {
            return $denied;
        }

        $this->request->allowMethod(['post']);

        $loginUser = $this->request->getAttribute('identity');
        $userId    = (int)($loginUser?->get('i_id_user') ?? 0);
        $userName  = (string)($loginUser?->get('c_user_name') ?? $userId);

        if ($userId <= 0) {
            return $this->apiResponseService->error($this->response, 'Unauthorized', 401);
        }

        $result = $this->mealReportingService->reportEat(
            $this->TIndividualReservationInfo,
            $this->MUserGroup,
            $userId,
            $userName
        );

        if (!$result['ok']) {
            return $this->apiResponseService->error($this->response, $result['message'], 400);
        }

        return $this->apiResponseService->success($this->response, [], $result['message']);
    }

    /**
     * 全部屋食数取得API（管理者用）。
     *
     * @return Response|null
     */
    public function getAllRoomsMealCounts(): ?Response
    {
        if ($denied = $this->authorizeReservation('getAllRoomsMealCounts', [], true)) {
            return $denied;
        }

        $this->request->allowMethod(['get']);
        $this->autoRender = false;

        $fromDate = $this->request->getQuery('from');
        $toDate   = $this->request->getQuery('to');

        if (!$fromDate || !$toDate) {
            $fromDate = date('Y-m-01', strtotime('-1 month'));
            $toDate   = date('Y-m-t', strtotime('+1 month'));
        }

        try {
            $result = $this->reportService->buildAllRoomsMealCounts(
                $this->TIndividualReservationInfo,
                $fromDate,
                $toDate
            );
            return $this->apiResponseService->success($this->response, ['result' => $result]);
        } catch (\Exception $e) {
            return $this->apiResponseService->error($this->response, 'データ取得に失敗しました。', 500);
        }
    }

    /**
     * 部屋別食数取得API（職員用）。
     *
     * @param int|null $roomId
     * @return Response|null
     */
    public function getRoomMealCounts($roomId = null): ?Response
    {
        if ($denied = $this->authorizeReservation('getRoomMealCounts', ['i_id_room' => (int)$roomId], true)) {
            return $denied;
        }

        $this->request->allowMethod(['get']);
        $this->autoRender = false;

        $user   = $this->request->getAttribute('identity');
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

        $targetRoomIds = $roomId ? [(int)$roomId] : $userRoomIds;

        if (empty($targetRoomIds)) {
            return $this->apiResponseService->error($this->response, '所属部屋が見つかりません。', 400);
        }

        $fromDate = $this->request->getQuery('from');
        $toDate   = $this->request->getQuery('to');

        if (!$fromDate || !$toDate) {
            $fromDate = date('Y-m-01', strtotime('-1 month'));
            $toDate   = date('Y-m-t', strtotime('+1 month'));
        }

        try {
            $result = $this->reportService->buildRoomMealCounts(
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
