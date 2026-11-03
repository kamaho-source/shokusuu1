<?php
declare(strict_types=1);

namespace App\Application\Query;

/**
 * 実食入力（大人限定）の読み取り。実食の判定は、境界日以前は i_change_flag（NULL なら eat_flag）、それより先は eat_flag。
 *
 * @phpstan-type ActualMealUser array{id: int, name: string, staff_id: string}
 * @phpstan-type ActualMealWeekGrid array{
 *     dates: list<string>,
 *     meals: array<int, string>,
 *     grid: array<int, array<string, array<int, bool>>>,
 *     versions: array<int, array<string, array<int, int>>>,
 *     statuses: array<int, array<string, array<int, int>>>
 * }
 */
interface ActualMealQueryInterface
{
    /**
     * 部屋の大人（職員番号のある、所属中で削除されていない人）。並びは全画面共通の表示順。
     *
     * @return list<ActualMealUser>
     */
    public function getAdultUsers(int $roomId): array;

    /**
     * 月曜から 7 日分の表（利用者 => 日付 => 食事区分）。予約が無い枠は false・版数 1・未承認。
     *
     * @param list<ActualMealUser> $users
     * @param string $weekMonday 週の月曜日（YYYY-MM-DD）
     * @return ActualMealWeekGrid
     */
    public function getWeekGrid(array $users, string $weekMonday): array;

    /**
     * 期間内で一番新しい差し戻し（理由・差し戻した人・日時 Y-m-d H:i）。無ければ null。
     *
     * @return array{reason: string, approver: string, date: string}|null
     */
    public function findLatestRejection(int $userId, string $dateFrom, string $dateTo): ?array;
}
