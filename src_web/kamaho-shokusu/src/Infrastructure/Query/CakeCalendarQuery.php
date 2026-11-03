<?php
declare(strict_types=1);

namespace App\Infrastructure\Query;

use App\Application\Query\CalendarQueryInterface;
use App\Application\Query\Dto\CalendarRoomScope;
use App\Application\Query\Dto\DayMealCounts;
use App\Application\Query\Dto\MyReservationDay;
use App\Domain\Service\ReservationDateRule;
use Cake\Chronos\ChronosDate;
use Cake\ORM\Table;
use Cake\ORM\TableRegistry;
use Psr\Clock\ClockInterface;

/**
 * CalendarQueryInterface の CakePHP 実装（m_room_info・t_individual_reservation_info の読み取り）。
 */
final class CakeCalendarQuery implements CalendarQueryInterface
{
    private const MEAL_KEYS = [1 => 'breakfast', 2 => 'lunch', 3 => 'dinner', 4 => 'bento'];

    public function __construct(private readonly ClockInterface $clock)
    {
    }

    public function getRoomsForUser(array $userRoomIds, bool $includeAllRooms, bool $isOfficeUser = false): array
    {
        $rooms = self::table('MRoomInfo');
        if ($includeAllRooms) {
            // 削除済みの部屋は選択肢に出さない
            return $rooms->find('list', keyField: 'i_id_room', valueField: 'c_room_name')
                ->where(['i_del_flg' => 0])
                ->orderBy(['i_disp_no' => 'ASC', 'i_id_room' => 'ASC'])
                ->toArray();
        }
        if ($userRoomIds === []) {
            return [];
        }
        if ($isOfficeUser) {
            return $rooms->find('list', keyField: 'i_id_room', valueField: 'c_room_name')
                ->where(['i_id_room IN' => $userRoomIds, 'c_room_name LIKE' => '%事務所%', 'i_del_flg' => 0])
                ->orderBy(['c_room_name' => 'ASC', 'i_id_room' => 'ASC'])
                ->toArray();
        }

        // 複数部屋に所属するユーザー（ブロック長・職員）には、所属している全部屋を返す
        return $rooms->find('list', keyField: 'i_id_room', valueField: 'c_room_name')
            ->where(['i_id_room IN' => $userRoomIds, 'i_del_flg' => 0])
            ->orderBy(['i_disp_no' => 'ASC', 'i_id_room' => 'ASC'])
            ->toArray();
    }

    public function getMealCountsByDate(?array $roomIds, ?string $startDate, ?string $endDate): array
    {
        $query = self::table('TIndividualReservationInfo')->find()
            ->select(['d_reservation_date', 'i_reservation_type', 'eat_flag', 'i_change_flag']);
        if ($roomIds) {
            $query->where(['i_id_room IN' => $roomIds]);
        }
        self::whereRange($query, $startDate, $endDate);

        $today  = $this->today();
        $counts = [];
        foreach ($query->all() as $r) {
            $date = $r->d_reservation_date->format('Y-m-d');
            // 食事区分（1〜4）以外の行は数えない（自分の予約と同じ。以前は存在しない区分で警告が出ていた）
            if (!isset(self::MEAL_KEYS[(int)$r->i_reservation_type]) || self::counted($r, $today) !== 1) {
                continue;
            }
            $counts[$date] ??= [1 => 0, 2 => 0, 3 => 0, 4 => 0];
            $counts[$date][(int)$r->i_reservation_type]++;
        }

        $days = [];
        foreach ($counts as $date => $byMeal) {
            $days[$date] = new DayMealCounts($date, $byMeal);
        }

        return $days;
    }

    public function getMyReservationDays(int $userId, ?string $startDate, ?string $endDate): array
    {
        $query = self::table('TIndividualReservationInfo')->find()
            ->select(['d_reservation_date', 'i_reservation_type', 'eat_flag', 'i_change_flag', 'i_id_room'])
            ->where(['i_id_user' => $userId]);
        self::whereRange($query, $startDate, $endDate);

        $today   = $this->today();
        $details = [];
        foreach ($query->all() as $r) {
            $key = self::MEAL_KEYS[$r->i_reservation_type] ?? null;
            if ($key === null) {
                continue;
            }
            $date = $r->d_reservation_date->format('Y-m-d');
            $details[$date] ??= array_fill_keys(['breakfast', 'breakfastRoom', 'lunch', 'lunchRoom', 'dinner', 'dinnerRoom', 'bento', 'bentoRoom'], null);
            $effective = self::counted($r, $today);
            // 部屋変更の後は、元の部屋に取消済みの行が残る。行の並び順で有効な予約を上書きしない
            if ($effective === 0 && $details[$date][$key] === 1) {
                continue;
            }
            $details[$date][$key]          = $effective;
            $details[$date][$key . 'Room'] = $effective === 1 ? (int)$r->i_id_room : null;
        }

        $days = [];
        foreach ($details as $date => $d) {
            $days[$date] = new MyReservationDay(...$d);
        }

        return $days;
    }

    public function getTotalCountsByDate(string $startDate, string $endDate, ?array $roomIds): array
    {
        $query = self::table('TIndividualReservationInfo')->find()
            ->select(['d_reservation_date', 'eat_flag', 'i_change_flag'])
            ->where(['d_reservation_date >=' => $startDate, 'd_reservation_date <' => $endDate]);
        if ($roomIds !== null) {
            $query->where(['i_id_room IN' => $roomIds ?: [0]]);
        }

        $today  = $this->today();
        $totals = [];
        foreach ($query->all() as $r) {
            if (self::counted($r, $today) === 1) {
                $date = $r->d_reservation_date->format('Y-m-d');
                $totals[$date] = ($totals[$date] ?? 0) + 1;
            }
        }

        return $totals;
    }

    public function getRoomNames(array $roomIds): array
    {
        if ($roomIds === []) {
            return [];
        }

        return self::table('MRoomInfo')->find('list', keyField: 'i_id_room', valueField: 'c_room_name')
            ->where(['i_id_room IN' => $roomIds])
            ->toArray();
    }

    public function roomScope(array $userRoomIds, bool $isOfficeUser, ?int $requestedCalRoomId): CalendarRoomScope
    {
        // 有効な所属部屋（退出済み・削除済みを除く）。既定の部屋もこの中から選ぶ。
        // 予約ポップアップで選べる部屋も同じ（管理者も所属外の部屋には予約できない。#821）
        $pickerRooms = $this->getRoomsForUser($userRoomIds, false);
        $validIds    = array_keys($pickerRooms);
        $userRoomId  = $validIds[0] ?? null;
        $rooms       = $isOfficeUser ? $this->getRoomsForUser($validIds, false, true) : $pickerRooms;

        // 選べない部屋（所属外など）を指定されたときは、指定なしと同じ既定の部屋にする（選択欄の表示と集計を揃える）
        $calRoomId = null;
        if ($requestedCalRoomId !== null && isset($rooms[$requestedCalRoomId])) {
            $calRoomId = $requestedCalRoomId;
        } elseif (!$isOfficeUser && $userRoomId !== null && isset($rooms[$userRoomId])) {
            $calRoomId = $userRoomId;
        }

        return new CalendarRoomScope(
            $validIds,
            $userRoomId,
            $rooms,
            $pickerRooms,
            $calRoomId,
            $calRoomId !== null ? [$calRoomId] : $this->countableRoomIds($isOfficeUser, $validIds),
        );
    }

    public function countableRoomIds(bool $isOfficeUser, array $userRoomIds): array
    {
        $roomIds = array_keys($isOfficeUser ? $this->getRoomsForUser([], true) : $this->getRoomsForUser($userRoomIds, false));

        return $roomIds ?: [0];
    }

    public function initialViewRange(): array
    {
        $firstOfMonth = $this->today()->startOfMonth();

        return [$firstOfMonth->subMonths(1)->format('Y-m-d'), $firstOfMonth->addMonths(3)->format('Y-m-d')];
    }

    private static function table(string $alias): Table
    {
        return TableRegistry::getTableLocator()->get($alias);
    }

    private function today(): ChronosDate
    {
        return new ChronosDate($this->clock->now());
    }

    /** 食数として数える値（1=食べる）。 */
    private static function counted(object $row, ChronosDate $today): int
    {
        return ReservationDateRule::countedValue(
            new ChronosDate($row->d_reservation_date->format('Y-m-d')),
            $row->eat_flag === null ? null : (int)$row->eat_flag,
            $row->i_change_flag === null ? null : (int)$row->i_change_flag,
            $today,
        );
    }

    /**
     * @param \Cake\ORM\Query\SelectQuery $query
     */
    private static function whereRange(object $query, ?string $startDate, ?string $endDate): void
    {
        if ($startDate !== null) {
            $query->where(['d_reservation_date >=' => $startDate]);
        }
        if ($endDate !== null) {
            $query->where(['d_reservation_date <' => $endDate]);
        }
    }
}
