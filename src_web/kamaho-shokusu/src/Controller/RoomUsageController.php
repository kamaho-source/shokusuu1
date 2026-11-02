<?php
declare(strict_types=1);

namespace App\Controller;

use App\Application\Query\Dto\RoomUsage;
use App\Application\Query\RoomUsageQueryInterface;
use App\Domain\Service\DatePeriodRule;
use App\Domain\ValueObject\MealType;
use Authorization\Exception\ForbiddenException;
use Cake\Http\Response;
use Cake\Http\ServerRequest;
use Cake\I18n\Date;

/**
 * 部屋使用率コントローラー
 *
 * システム管理者専用。部屋ごとの使用率集計・低使用率部屋のピックアップを提供する。
 */
class RoomUsageController extends AppController
{
    use JsonResponseTrait;

    public function __construct(
        private RoomUsageQueryInterface $roomUsageQuery,
        ServerRequest $request
    ) {
        parent::__construct($request);
    }

    public function initialize(): void
    {
        parent::initialize();
        $this->viewBuilder()->setLayout('default');
    }

    /**
     * GET /RoomUsage — 部屋使用率一覧ページ（HTML）
     */
    public function index(): ?Response
    {
        try {
            $this->Authorization->authorize($this, 'index');
        } catch (ForbiddenException $e) {
            $this->Flash->error('この機能はシステム管理者のみ利用できます。');
            return $this->redirect(['controller' => 'Pages', 'action' => 'dashboard']);
        }

        [$dateFrom, $dateTo, $mealType, $threshold, $error] = $this->resolveFilters();
        $rooms = $lowRooms = [];
        if ($error !== null) {
            // 期間・食事・しきい値が読めないと、以前は 500 になるか、誤った表（期間が逆なら使用率 0%）を出していた
            $this->response = $this->response->withStatus(400);
            $this->Flash->error($error);
        } else {
            $usage    = $this->roomUsageQuery->roomUsage($dateFrom, $dateTo, $mealType);
            $rooms    = self::rows($usage);
            $lowRooms = self::rows(array_filter($usage, static fn(RoomUsage $r): bool => $r->isLow($threshold)));
        }

        $this->set(compact('rooms', 'lowRooms', 'dateFrom', 'dateTo', 'mealType', 'threshold'));
        return null;
    }

    /**
     * GET /RoomUsage/roomUsage
     *
     * 部屋ごとの使用率一覧を返す。
     *
     * クエリパラメータ:
     *   - date_from  (Y-m-d)
     *   - date_to    (Y-m-d)
     *   - meal_type  (1=朝 2=昼 3=夕 4=弁当)
     */
    public function roomUsage(): Response
    {
        try {
            $this->Authorization->authorize($this, 'roomUsage');
        } catch (ForbiddenException $e) {
            return $this->jsonError('この機能はシステム管理者のみ利用できます。', 403);
        }

        [$dateFrom, $dateTo, $mealType, , $error] = $this->resolveFilters();
        if ($error !== null) {
            return $this->jsonError($error, 400);
        }

        $rooms = self::rows($this->roomUsageQuery->roomUsage($dateFrom, $dateTo, $mealType));

        return $this->jsonResponse(['rooms' => $rooms]);
    }

    /**
     * GET /RoomUsage/lowUsageRooms
     *
     * 使用率が閾値以下の部屋を返す。
     *
     * クエリパラメータ:
     *   - threshold  (float, デフォルト 50.0)
     *   - date_from  (Y-m-d)
     *   - date_to    (Y-m-d)
     *   - meal_type  (1=朝 2=昼 3=夕 4=弁当)
     */
    public function lowUsageRooms(): Response
    {
        try {
            $this->Authorization->authorize($this, 'lowUsageRooms');
        } catch (ForbiddenException $e) {
            return $this->jsonError('この機能はシステム管理者のみ利用できます。', 403);
        }

        [$dateFrom, $dateTo, $mealType, $threshold, $error] = $this->resolveFilters();
        if ($error !== null) {
            return $this->jsonError($error, 400);
        }

        $rooms = self::rows(array_filter(
            $this->roomUsageQuery->roomUsage($dateFrom, $dateTo, $mealType),
            static fn(RoomUsage $r): bool => $r->isLow($threshold)
        ));

        return $this->jsonResponse([
            'threshold' => $threshold,
            'rooms'     => $rooms,
        ]);
    }

    /**
     * 絞り込みの値。期間の既定は今月1日〜今日、食事の既定は全食事、しきい値の既定は 50%。
     *
     * @return array{0: string, 1: string, 2: int|null, 3: float, 4: string|null} [開始日, 終了日, 食事, しきい値, 読めないときの理由]
     */
    private function resolveFilters(): array
    {
        $dateFrom  = $this->queryString('date_from') ?: Date::today()->startOfMonth()->format('Y-m-d');
        $dateTo    = $this->queryString('date_to') ?: Date::today()->format('Y-m-d');
        $mealRaw   = $this->queryString('meal_type');
        $threshRaw = $this->queryString('threshold');
        $mealType  = $mealRaw === '' ? null : MealType::tryFrom((int)$mealRaw)?->value;
        $threshold = $threshRaw === '' ? 50.0 : (float)$threshRaw;

        $error = DatePeriodRule::error($dateFrom, $dateTo);
        if ($error === null && $mealRaw !== '' && ($mealType === null || (string)$mealType !== $mealRaw)) {
            $error = '食事の種類が正しくありません。';
        }
        if ($error === null && $threshRaw !== '' && !is_numeric($threshRaw)) {
            $error = '使用率のしきい値は数値で指定してください。';
        }

        return [$dateFrom, $dateTo, $mealType, $threshold, $error];
    }

    /**
     * @param iterable<RoomUsage> $usage
     * @return list<array<string, int|float|string|list<array<string, int|float|string>>>>
     */
    private static function rows(iterable $usage): array
    {
        $rows = [];
        foreach ($usage as $r) {
            $rows[] = $r->toArray();
        }

        return $rows;
    }
}
