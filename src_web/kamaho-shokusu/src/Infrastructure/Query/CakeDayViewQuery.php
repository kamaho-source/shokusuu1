<?php
declare(strict_types=1);

namespace App\Infrastructure\Query;

use App\Application\Query\DayViewQueryInterface;
use App\Application\Query\Dto\DayView;
use App\Application\Query\Dto\DayViewUser;
use App\Application\Query\RoomQueryInterface;
use App\Domain\Service\ReservationDateRule;
use App\Domain\ValueObject\MealType;
use App\Infrastructure\Persistence\Table\MUserGroupTable;
use App\Service\RoomMemberOrderService;
use Cake\Chronos\ChronosDate;
use Cake\ORM\TableRegistry;
use Psr\Clock\ClockInterface;

/**
 * DayViewQueryInterface の CakePHP 実装（m_user_group・m_user_info・t_individual_reservation_info の読み取り）。
 */
final class CakeDayViewQuery implements DayViewQueryInterface
{
    public function __construct(
        private readonly RoomQueryInterface $rooms,
        private readonly ClockInterface $clock,
    ) {
    }

    public function getDayView(int $userId, ?string $date, ?int $requestedRoomId): DayView
    {
        $today = new ChronosDate($this->clock->now());
        if ($date === null || $date === '' || $date === ':date') {
            $date = $today->format('Y-m-d');
        }
        $targetDate = new ChronosDate($date);

        // 管理者・システム管理者も所属部屋のみ（#821）。所属部屋が無い（退出済み・削除済みのみ）なら、どの部屋も表示しない
        $authorizedRooms = $userId > 0 ? $this->rooms->getAccessibleRooms($userId) : [];
        $activeRoomId = (int)($requestedRoomId ?? 0);
        if (!isset($authorizedRooms[$activeRoomId])) {
            $activeRoomId = $authorizedRooms !== [] ? (int)array_key_first($authorizedRooms) : 0;
        }

        $users = [];
        $userMeals = [];
        $otherRoomMeals = [];
        if ($activeRoomId > 0) {
            $rows = TableRegistry::getTableLocator()->get('MUserGroup')->find()
                ->select(['MUserGroup.i_id_user', 'user_name' => 'MUserInfo.c_user_name', 'staff_id' => 'MUserInfo.i_id_staff'])
                ->enableAutoFields(false)
                ->innerJoin(['MUserInfo' => 'm_user_info'], ['MUserInfo.i_id_user = MUserGroup.i_id_user'])
                ->where(['MUserGroup.i_id_room' => $activeRoomId, ...MUserGroupTable::activeCondition(), 'MUserInfo.i_del_flag' => 0])
                ->enableHydration(false);
            foreach (RoomMemberOrderService::orderMembers($rows)->all() as $u) {
                $users[] = new DayViewUser((int)$u['i_id_user'], (string)$u['user_name'], (string)($u['staff_id'] ?? ''));
            }

            if ($users !== []) {
                // 別の部屋の名前は、削除済みの部屋も含めて引く
                $roomNames = TableRegistry::getTableLocator()->get('MRoomInfo')->find('list', keyField: 'i_id_room', valueField: 'c_room_name')->toArray();
                $reservations = TableRegistry::getTableLocator()->get('TIndividualReservationInfo')->find()
                    ->enableAutoFields(false)
                    ->select(['i_id_user', 'i_id_room', 'i_reservation_type', 'eat_flag', 'i_change_flag'])
                    ->where([
                        'i_id_user IN'          => array_map(static fn(DayViewUser $u) => $u->userId, $users),
                        'd_reservation_date'    => $targetDate->format('Y-m-d'),
                        'i_reservation_type IN' => MealType::values(),
                    ])
                    ->all();
                foreach ($reservations as $r) {
                    $counted = ReservationDateRule::countedValue(
                        $targetDate,
                        $r->eat_flag === null ? null : (int)$r->eat_flag,
                        $r->i_change_flag === null ? null : (int)$r->i_change_flag,
                        $today,
                    );
                    if ($counted !== 1) {
                        continue;
                    }
                    $uid = (int)$r->i_id_user;
                    $type = (int)$r->i_reservation_type;
                    $rid = (int)$r->i_id_room;
                    if ($rid === $activeRoomId) {
                        $userMeals[$uid][$type] = true;
                    } else {
                        $otherRoomMeals[$uid][$type] = $roomNames[$rid] ?? ('部屋ID:' . $rid);
                    }
                }
            }
        }

        return new DayView($date, $authorizedRooms, $activeRoomId, $authorizedRooms[$activeRoomId] ?? '', $users, $userMeals, $otherRoomMeals);
    }
}
