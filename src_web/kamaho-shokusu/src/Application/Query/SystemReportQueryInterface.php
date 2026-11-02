<?php
declare(strict_types=1);

namespace App\Application\Query;

use App\Application\Query\Dto\DailyChildCount;
use App\Application\Query\Dto\LoginReport;
use App\Application\Query\Dto\ReportUserOption;
use App\Application\Query\Dto\RoomUsageStat;

/**
 * システムレポート（レポート閲覧権限のある人向け）の読み取り。
 *
 * 子どもは i_user_level = 1、大人はそれ以外。予約数は食数として数える予約（ReservationDateRule::countedValue が 1）の件数。
 * Excel の出力は画面（ExcelJS）が行う。
 */
interface SystemReportQueryInterface
{
    /**
     * 部屋別の子ども・大人の利用者数・予約数・使用率（部屋名の順）。
     * 利用者は所属中・削除されていない人、部屋は削除されていない部屋。予約はその部屋に所属する人の分だけ数える。
     *
     * @param list<int> $excludeUserIds 集計から除く人
     * @return list<RoomUsageStat>
     */
    public function roomStats(array $excludeUserIds, string $dateFrom, string $dateTo): array;

    /**
     * 日別の子どもの予約数（期間のすべての日。削除されていない子どもの分）。
     *
     * @param list<int> $excludeUserIds 集計から除く人
     * @return list<DailyChildCount>
     */
    public function dailyChildCounts(array $excludeUserIds, string $dateFrom, string $dateTo): array;

    /** ログイン情報（監査ログの user_login・user_login_failed）。 */
    public function loginReport(string $dateFrom, string $dateTo): LoginReport;

    /**
     * 集計から除く人の候補（削除されていない全員。大人が先、それぞれ名前順）。
     *
     * @return list<ReportUserOption>
     */
    public function userOptions(): array;
}
