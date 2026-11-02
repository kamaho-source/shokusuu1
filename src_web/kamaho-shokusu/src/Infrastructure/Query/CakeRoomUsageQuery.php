<?php
declare(strict_types=1);

namespace App\Infrastructure\Query;

use App\Application\Query\Dto\RoomUsage;
use App\Application\Query\Dto\StaffUsage;
use App\Application\Query\RoomUsageQueryInterface;
use App\Domain\Service\MealUsageRate;
use App\Domain\Service\ReservationDateRule;
use App\Domain\ValueObject\UserRole;
use App\Infrastructure\Persistence\Table\MUserGroupTable;
use Cake\Chronos\ChronosDate;
use Cake\ORM\Table;
use Cake\ORM\TableRegistry;
use Psr\Clock\ClockInterface;

/**
 * RoomUsageQueryInterface の CakePHP 実装（m_user_group・m_user_info・m_room_info・t_individual_reservation_info の読み取り）。
 */
final class CakeRoomUsageQuery implements RoomUsageQueryInterface
{
    public function __construct(private readonly ClockInterface $clock)
    {
    }

    private static function table(string $alias): Table
    {
        return TableRegistry::getTableLocator()->get($alias);
    }

    public function roomUsage(string $dateFrom, string $dateTo, ?int $mealType = null, ?int $level = null): array
    {
        $days      = (int)(new \DateTimeImmutable($dateFrom))->diff(new \DateTimeImmutable($dateTo))->days + 1;
        $mealTypes = $mealType !== null ? 1 : MealUsageRate::MEAL_TYPES;

        $members    = [];  // [roomId][userId] = true（分母）
        $roomNames  = [];
        $staff      = [];  // [roomId][userId] = true（職員のみ）
        $staffNames = [];
        $memberships = self::table('MUserGroup')->find()
            ->contain(['MUserInfo', 'MRoomInfo'])
            ->where([
                ...MUserGroupTable::activeCondition(),
                // 削除済みの利用者・部屋は数えない（システムレポート・他の画面と同じ）
                'MUserInfo.i_del_flag' => 0,
                'MRoomInfo.i_del_flg'  => 0,
            ])
            ->all();
        foreach ($memberships as $row) {
            $roomId    = (int)$row->i_id_room;
            $userId    = (int)$row->i_id_user;
            $userLevel = (int)($row->m_user_info?->i_user_level ?? -1);
            // 区分を絞るときは、その区分の人だけを分母・分子に入れる
            if ($level !== null && $userLevel !== $level) {
                continue;
            }
            $members[$roomId][$userId] = true;
            $roomNames[$roomId] = (string)($row->m_room_info?->c_room_name ?? '');
            if (UserRole::isStaffLevel($userLevel)) {
                $staff[$roomId][$userId] = true;
                $staffNames[$userId] = (string)($row->m_user_info?->c_user_name ?? '');
            }
        }
        if ($members === []) {
            return [];
        }

        $where = ['d_reservation_date >=' => $dateFrom, 'd_reservation_date <=' => $dateTo];
        if ($mealType !== null) {
            $where['i_reservation_type'] = $mealType;
        }
        $today = new ChronosDate($this->clock->now());
        $eats = [];
        $staffEats = [];
        $rows = self::table('TIndividualReservationInfo')->find()
            ->select(['i_id_user', 'i_id_room', 'd_reservation_date', 'eat_flag', 'i_change_flag'])
            ->where($where)
            ->all();
        foreach ($rows as $row) {
            $roomId = (int)$row->i_id_room;
            $userId = (int)$row->i_id_user;
            if (!isset($members[$roomId][$userId])) {
                continue;
            }
            $counted = ReservationDateRule::countedValue(
                new ChronosDate($row->d_reservation_date->format('Y-m-d')),
                $row->eat_flag === null ? null : (int)$row->eat_flag,
                $row->i_change_flag === null ? null : (int)$row->i_change_flag,
                $today,
            );
            if ($counted !== 1) {
                continue;
            }
            $eats[$roomId] = ($eats[$roomId] ?? 0) + 1;
            if (isset($staff[$roomId][$userId])) {
                $staffEats[$roomId][$userId] = ($staffEats[$roomId][$userId] ?? 0) + 1;
            }
        }

        $result = [];
        foreach ($members as $roomId => $users) {
            $staffList = [];
            foreach (array_keys($staff[$roomId] ?? []) as $staffId) {
                $eat = $staffEats[$roomId][$staffId] ?? 0;
                $staffList[] = new StaffUsage(
                    $staffId,
                    $staffNames[$staffId],
                    MealUsageRate::capacity(1, $days, $mealTypes),
                    $eat,
                    MealUsageRate::percent($eat, 1, $days, $mealTypes),
                );
            }
            usort($staffList, static fn(StaffUsage $a, StaffUsage $b): int => strcmp($a->userName, $b->userName));

            $eat = $eats[$roomId] ?? 0;
            $result[] = new RoomUsage(
                $roomId,
                $roomNames[$roomId],
                count($users),
                MealUsageRate::capacity(count($users), $days, $mealTypes),
                $eat,
                MealUsageRate::percent($eat, count($users), $days, $mealTypes),
                $staffList,
            );
        }
        usort($result, static fn(RoomUsage $a, RoomUsage $b): int => strcmp($a->roomName, $b->roomName));

        return $result;
    }
}
