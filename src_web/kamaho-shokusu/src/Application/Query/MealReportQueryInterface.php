<?php
declare(strict_types=1);

namespace App\Application\Query;

use App\Application\Query\Dto\DailyMealCount;
use App\Application\Query\Dto\MealExport;
use App\Application\Query\Dto\MealTypeCount;
use App\Application\Query\Dto\RankMealCount;
use App\Application\Query\Dto\RoomUserMealStatus;

/**
 * 食数・レポートの読み取り（その日の食数、直前編集の利用者一覧、月次エクスポート、年代別、日ごとの食数）。
 *
 * 「食べる」の判定は、境界日（今日+14日）以前は i_change_flag（NULL なら eat_flag）、それより先は eat_flag。
 * 集計はキャッシュする（予約が変わるとキャッシュを捨てる。直前編集の利用者一覧はキャッシュしない）。
 */
interface MealReportQueryInterface
{
    /**
     * その日の食事ごとの食べる人数（全部屋）。食べる人がいない食事は含まない。
     *
     * @return list<MealTypeCount>
     */
    public function getMealCounts(string $date): array;

    /**
     * 直前編集の画面に出す部屋の利用者（所属中で、削除されていない人。表示順）と、その日この部屋で食べる食事。
     *
     * @return list<RoomUserMealStatus>
     */
    public function getUsersByRoomForEdit(int $roomId, string $date): array;

    /** 部屋・日付・食事ごとの食べる人数（期間の終わりを含む）。 */
    public function getExportJson(string $from, string $to): MealExport;

    /**
     * 年代・性別・日付ごとの食べる人数（期間の終わりは含まない）。
     *
     * @return list<RankMealCount>
     */
    public function getExportRank(string $startDate, string $endDateExclusive): array;

    /**
     * 日ごとの食事ごとの食べる人数（期間の終わりを含む。日付順）。
     *
     * @param list<int>|null $roomIds 集計する部屋（null・空は全部屋）
     * @return list<DailyMealCount>
     */
    public function getDailyMealCounts(string $fromDate, string $toDate, ?array $roomIds = null): array;
}
