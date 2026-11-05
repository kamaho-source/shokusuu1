<?php
declare(strict_types=1);

namespace App\Application\Query;

use App\Application\Query\Dto\CalendarRoomScope;
use App\Application\Query\Dto\DayMealCounts;
use App\Application\Query\Dto\MyReservationDay;

/**
 * 予約カレンダーの読み取り（部屋の選択肢、日ごとの食数、自分の予約、日ごとの合計）。
 *
 * 「食べる」の判定は、境界日（今日+14日）以前は i_change_flag（NULL なら eat_flag）、それより先は eat_flag。
 * 期間の終わり（$endDate）は含まない。
 */
interface CalendarQueryInterface
{
    /**
     * 部屋の選択肢（削除されていない部屋）。管理者・システム管理者も所属部屋のみ（#821）。
     *
     * @param list<int> $userRoomIds 所属部屋
     * @param bool $includeAllRooms 全部屋を返すか（事務所ユーザーの集計用）
     * @param bool $isOfficeUser 事務所ユーザーなら、所属部屋のうち「事務所」の部屋だけ（部屋名順）
     * @return array<int, string> 部屋ID => 部屋名
     */
    public function getRoomsForUser(array $userRoomIds, bool $includeAllRooms, bool $isOfficeUser = false): array;

    /**
     * 日ごとの食べる人数（食事ごと）。食べる人がいない日は含まない。
     *
     * @param list<int>|null $roomIds 集計する部屋（null・空は全部屋）
     * @return array<string, DayMealCounts> 日付 => 食数
     */
    public function getMealCountsByDate(?array $roomIds, ?string $startDate, ?string $endDate): array;

    /**
     * 自分の日ごとの予約（食事ごとに 1=食べる・0=食べない、食べる部屋）。予約行がある日だけ。
     *
     * @return array<string, MyReservationDay> 日付 => 予約
     */
    public function getMyReservationDays(int $userId, ?string $startDate, ?string $endDate): array;

    /**
     * 日ごとの食べる人数の合計（食事を問わない）。
     *
     * @param list<int>|null $roomIds 集計する部屋（null は全部屋、空は該当なし）
     * @return array<string, int> 日付 => 人数
     */
    public function getTotalCountsByDate(string $startDate, string $endDate, ?array $roomIds): array;

    /**
     * 部屋名（削除済みの部屋も含む）。
     *
     * @param list<int> $roomIds
     * @return array<int, string> 部屋ID => 部屋名
     */
    public function getRoomNames(array $roomIds): array;

    /**
     * 一覧画面の部屋の範囲。指定された部屋（cal_room_id）が選べないときは、指定なしと同じ既定の部屋にする。
     *
     * @param list<int> $userRoomIds 所属部屋（退出済み・削除済みを含んでよい）
     * @param bool $isOfficeUser 事務所ユーザーか
     * @param int|null $requestedCalRoomId 指定された部屋（指定なしは null）
     */
    public function roomScope(array $userRoomIds, bool $isOfficeUser, ?int $requestedCalRoomId): CalendarRoomScope;

    /**
     * 食数の集計対象の部屋。事務所ユーザーは有効な全部屋、それ以外は有効な所属部屋（どちらも削除済みを除く。#821）。
     * 空のときは該当なしの ID（0）を返し、全部屋の集計に落ちないようにする。
     *
     * @param list<int> $userRoomIds 所属部屋（退出済み・削除済みを含んでよい）
     * @return list<int>
     */
    public function countableRoomIds(bool $isOfficeUser, array $userRoomIds): array;

    /**
     * 一覧画面の初期表示の期間（前月の月初〜翌々月末。FullCalendar の表示範囲に合わせる）。終わりは含まない（翌々月末の翌日）。
     *
     * @return array{0: string, 1: string} [開始日, 終了日（含まない）] YYYY-MM-DD
     */
    public function initialViewRange(): array;
}
