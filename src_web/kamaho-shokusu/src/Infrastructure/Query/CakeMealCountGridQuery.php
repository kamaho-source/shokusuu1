<?php
declare(strict_types=1);

namespace App\Infrastructure\Query;

use App\Application\Query\Dto\MealCountGrid;
use App\Application\Query\Dto\MealGridRoom;
use App\Application\Query\Dto\MealGridUser;
use App\Application\Query\MealCountGridQueryInterface;
use App\Domain\Service\ReservationDateRule;
use App\Domain\ValueObject\MealType;
use App\Infrastructure\Persistence\Table\MUserGroupTable;
use App\Service\RoomMemberOrderService;
use Cake\Chronos\ChronosDate;
use Cake\ORM\Table;
use Cake\ORM\TableRegistry;
use Psr\Clock\ClockInterface;

/**
 * MealCountGridQueryInterface の CakePHP 実装（m_user_group・m_user_info・t_individual_reservation_info の読み取り）。
 */
final class CakeMealCountGridQuery implements MealCountGridQueryInterface
{
    public function __construct(private readonly ClockInterface $clock)
    {
    }

    private static function table(string $alias): Table
    {
        return TableRegistry::getTableLocator()->get($alias);
    }

    public function getRoomUsers(array $roomIds): array
    {
        $roomIds = array_values(array_unique(array_map('intval', $roomIds)));

        // 利用者のいない部屋も必ずキーを持つようにしておく
        $result = array_fill_keys($roomIds, []);
        if ($roomIds === []) {
            return $result;
        }

        $rows = RoomMemberOrderService::orderMembers(self::table('MUserGroup')->find())
            ->enableAutoFields(false)
            ->select([
                'i_id_room'    => 'MUserGroup.i_id_room',
                'i_id_user'    => 'MUserGroup.i_id_user',
                'user_name'    => 'MUserInfo.c_user_name',
                'i_user_level' => 'MUserInfo.i_user_level',
            ])
            ->innerJoin(
                ['MUserInfo' => self::table('MUserInfo')->getTable()],
                ['MUserInfo.i_id_user = MUserGroup.i_id_user']
            )
            ->where([
                'MUserGroup.i_id_room IN' => $roomIds,
                ...MUserGroupTable::activeCondition(),
                'MUserInfo.i_del_flag'    => 0,
            ])
            ->enableHydration(false)
            ->all();

        // 並び順（RoomMemberOrderService::orderMembers）は取得順のまま部屋ごとに振り分ける
        foreach ($rows as $row) {
            $result[(int)$row['i_id_room']][] = new MealGridUser(
                (int)$row['i_id_user'],
                (string)$row['user_name'],
                (int)($row['i_user_level'] ?? 0),
            );
        }

        return $result;
    }

    public function getGrid(array $rooms, array $roomUsers, array $dates): MealCountGrid
    {
        $allUserIds = [];
        foreach ($roomUsers as $users) {
            foreach ($users as $u) {
                $allUserIds[] = $u->id;
            }
        }
        $allUserIds = array_values(array_unique($allUserIds));

        $meals     = MealType::labels(MealType::LABEL_TINY);
        $mealTypes = array_keys($meals);
        $rows = [];
        if ($allUserIds !== [] && $dates !== []) {
            $rows = self::table('TIndividualReservationInfo')->find()
                ->enableAutoFields(false)
                ->select(['i_id_user', 'i_id_room', 'd_reservation_date', 'i_reservation_type', 'eat_flag', 'i_change_flag'])
                ->where([
                    'i_id_user IN'          => $allUserIds,
                    'd_reservation_date IN' => $dates,
                    'i_reservation_type IN' => $mealTypes,
                ])
                ->enableHydration(false)
                ->all()
                ->toArray();
        }

        $today = new ChronosDate($this->clock->now());
        // (利用者, 部屋, 日付, 食事) → 食べるか
        $map = [];
        // (利用者, 日付, 食事) → 食べる予約がある最初の部屋（他の部屋で選べなくする）
        $activeMealRooms = [];
        foreach ($rows as $row) {
            $uid  = (int)$row['i_id_user'];
            $rid  = (int)$row['i_id_room'];
            $date = $row['d_reservation_date']->format('Y-m-d');
            $type = (int)$row['i_reservation_type'];

            $eats = ReservationDateRule::countedValue(
                new ChronosDate($date),
                $row['eat_flag'] === null ? null : (int)$row['eat_flag'],
                $row['i_change_flag'] === null ? null : (int)$row['i_change_flag'],
                $today,
            ) === 1;

            $map[$uid][$rid][$date][$type] = $eats;
            if ($eats && !isset($activeMealRooms[$uid][$date][$type])) {
                $activeMealRooms[$uid][$date][$type] = $rid;
            }
        }

        $dailyTotals = [];
        foreach ($dates as $date) {
            $dailyTotals[$date] = array_fill_keys($mealTypes, 0);
        }

        $roomsData = [];
        foreach ($rooms as $roomId => $roomName) {
            $roomId = (int)$roomId;
            $users  = $roomUsers[$roomId] ?? [];

            $grid      = [];
            $otherRoom = [];
            foreach ($users as $u) {
                $uid = $u->id;
                $grid[$uid]      = [];
                $otherRoom[$uid] = [];
                foreach ($dates as $date) {
                    $grid[$uid][$date]      = [];
                    $otherRoom[$uid][$date] = [];
                    foreach ($mealTypes as $mealType) {
                        $on = $map[$uid][$roomId][$date][$mealType] ?? false;
                        $grid[$uid][$date][$mealType] = $on;
                        if ($on) {
                            $dailyTotals[$date][$mealType]++;
                        }

                        // この部屋では食べず、別の部屋で食べる予約があれば、この部屋では選べない
                        $activeRid = $activeMealRooms[$uid][$date][$mealType] ?? null;
                        if (!$on && $activeRid !== null && $activeRid !== $roomId) {
                            $otherRoom[$uid][$date][$mealType] = $activeRid;
                        }
                    }
                }
            }

            $roomsData[$roomId] = new MealGridRoom($roomId, (string)$roomName, $users, $grid, $otherRoom);
        }

        return new MealCountGrid($dates, $meals, $roomsData, $dailyTotals);
    }

    public function getSelectableUsers(): array
    {
        $users = [];
        $rows = self::table('MUserInfo')->find()
            ->select(['MUserInfo.i_id_user', 'MUserInfo.c_user_name'])
            ->where(['MUserInfo.i_del_flag' => 0])
            ->orderByAsc('MUserInfo.c_user_name')
            ->enableHydration(false)
            ->all();
        foreach ($rows as $u) {
            $users[(int)$u['i_id_user']] = (string)$u['c_user_name'];
        }

        return $users;
    }
}
