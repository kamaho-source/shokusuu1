<?php
declare(strict_types=1);

namespace App\Controller;

use App\Application\Query\Dto\DailyMealCount;
use App\Application\Query\Dto\MealTypeCount;
use App\Application\Query\Dto\RankMealCount;
use App\Application\Query\Dto\RoomUserMealStatus;
use App\Application\Query\MealReportQueryInterface;
use App\Infrastructure\Query\CakeMealReportQuery;
use App\Service\MealReportingService;
use Cake\Chronos\ClockFactory;
use Cake\Http\Response;
use Cake\I18n\Date;
use Cake\Log\Log;

/**
 * 食数レポート・エクスポート専用コントローラー。
 *
 * 食数取得API・JSONエクスポート・欠食/実食報告を担当する。
 */
class ReservationReportController extends ReservationBaseController
{
    private MealReportQueryInterface $mealReport;
    private MealReportingService $mealReportingService;

    public function initialize(): void
    {
        parent::initialize();

        $this->mealReport           = new CakeMealReportQuery(new ClockFactory('Asia/Tokyo'));
        $this->mealReportingService = new MealReportingService();

        // fetch から直接 POST される API のため FormProtection のフォームトークン検証対象外にする。
        // CSRF は X-CSRF-Token ヘッダで別途検証される。
        $this->FormProtection->setConfig('unlockedActions', [
            'reportNoMeal',
            'reportEat',
        ]);
    }

    /**
     * 日別食数取得API。
     *
     * @param string $date YYYY-MM-DD
     * @return Response|null
     */
    public function getMealCounts($date): ?Response
    {
        if ($denied = $this->authorizeReservation('getMealCounts', [], true)) {
            return $denied;
        }

        $this->request->allowMethod(['get']);
        $this->autoRender = false;

        $dateStr = (string)$date;
        // 形は合っていても存在しない日付（2026-13-45 など）は 500 になっていたため、ここで 400 にする
        if (!self::isValidDate($dateStr)) {
            return $this->apiResponseService->error($this->response, 'Invalid date format (Y-m-d).', 400);
        }

        $rows = array_map(static fn(MealTypeCount $c) => $c->toArray(), $this->mealReport->getMealCounts($dateStr));

        return $this->apiResponseService->success($this->response, ['mealCounts' => $rows]);
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

        // 日付が無い・不正だと SQL エラー(500)になっていたため、先に 400 で返す
        if (!is_string($date) || !self::isValidDate($date)) {
            return $this->apiResponseService->error($this->response, '日付（YYYY-MM-DD）を指定してください。', 400);
        }

        $completeUserInfo = array_map(
            static fn(RoomUserMealStatus $u) => $u->toArray(),
            $this->mealReport->getUsersByRoomForEdit((int)$roomId, (string)$date)
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

            $result = $this->mealReport->getExportJson($startDate->format('Y-m-d'), $endDate->format('Y-m-d'));

            return $this->apiResponseService->success($this->response, $result->toArray());
        } catch (\Throwable $e) {
            Log::write('error', (string)$e);
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
        } else {
            if (!$month || !$isMonth($month)) {
                return $this->apiResponseService->error($this->response, '無効な月が指定されました。', 400);
            }
            $startDate = $month . '-01';
            $endDate   = date('Y-m-d', strtotime($month . ' +1 month'));
        }

        try {
            $finalOutput = array_map(
                static fn(RankMealCount $r) => $r->toArray(),
                $this->mealReport->getExportRank($startDate, $endDate)
            );

            return $this->apiResponseService->success($this->response, $finalOutput);
        } catch (\Throwable $e) {
            Log::write('error', (string)$e);

            return $this->apiResponseService->error($this->response, 'エクスポート処理中にエラーが発生しました。', 500);
        }
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
            return $this->apiResponseService->error($this->response, $result['message'], $result['status'] ?? 400);
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
            return $this->apiResponseService->error($this->response, $result['message'], $result['status'] ?? 400);
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
            [$fromDate, $toDate] = self::defaultCountRange();
        }
        if ($error = $this->invalidRangeResponse($fromDate, $toDate)) {
            return $error;
        }

        try {
            $result = self::dailyRows($this->mealReport->getDailyMealCounts($fromDate, $toDate));
            return $this->apiResponseService->success($this->response, ['result' => $result]);
        } catch (\Throwable $e) {
            Log::write('error', (string)$e);

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

        // 部屋の指定が無いときは自分の所属部屋（異動で抜けた部屋・削除済みの部屋は含めない。他の画面と同じ基準）
        $targetRoomIds = $roomId ? [(int)$roomId] : $this->roomQuery->getUserRoomIds($userId);

        if (empty($targetRoomIds)) {
            return $this->apiResponseService->error($this->response, '所属部屋が見つかりません。', 400);
        }

        $fromDate = $this->request->getQuery('from');
        $toDate   = $this->request->getQuery('to');

        if (!$fromDate || !$toDate) {
            [$fromDate, $toDate] = self::defaultCountRange();
        }
        if ($error = $this->invalidRangeResponse($fromDate, $toDate)) {
            return $error;
        }

        try {
            $result = self::dailyRows($this->mealReport->getDailyMealCounts($fromDate, $toDate, $targetRoomIds));
            return $this->apiResponseService->success($this->response, ['result' => $result]);
        } catch (\Throwable $e) {
            Log::write('error', (string)$e);

            return $this->apiResponseService->error($this->response, 'データ取得に失敗しました。', 500);
        }
    }

    /**
     * 期間の指定が日付として読めないとき 400 を返す（以前は SQL エラーで 500 になっていた）。
     */
    private function invalidRangeResponse(array|string|null $fromDate, array|string|null $toDate): ?Response
    {
        if (is_string($fromDate) && is_string($toDate) && self::isValidDate($fromDate) && self::isValidDate($toDate)) {
            return null;
        }

        return $this->apiResponseService->error($this->response, '期間（from・to）を YYYY-MM-DD で指定してください。', 400);
    }

    /** YYYY-MM-DD の実在する日付か（2026-02-30 のような繰り越しも認めない）。 */
    private static function isValidDate(string $date): bool
    {
        $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $date);

        return $parsed !== false && $parsed->format('Y-m-d') === $date;
    }

    /**
     * @param list<DailyMealCount> $counts
     * @return list<array{date: string, morning: int, lunch: int, dinner: int, bento: int, total: int}>
     */
    private static function dailyRows(array $counts): array
    {
        return array_map(static fn(DailyMealCount $c) => $c->toArray(), $counts);
    }

    /**
     * 食数 API の既定の期間（先月1日〜来月末）。
     *
     * 月の足し引きは月末を繰り越さない（3/31 の「先月」は 3/1 ではなく 2/1、1/31 の「来月末」は 3/31 ではなく 2/28）。
     *
     * @return array{0: string, 1: string}
     */
    private static function defaultCountRange(): array
    {
        $today = Date::today('Asia/Tokyo');

        return [
            $today->subMonths(1)->startOfMonth()->format('Y-m-d'),
            $today->addMonths(1)->endOfMonth()->format('Y-m-d'),
        ];
    }
}
