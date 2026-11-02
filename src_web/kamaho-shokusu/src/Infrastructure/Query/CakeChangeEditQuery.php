<?php
declare(strict_types=1);

namespace App\Infrastructure\Query;

use App\Application\Query\ChangeEditQueryInterface;
use App\Application\Query\Dto\ChangeEditContext;
use App\Application\Query\Dto\ChangeEditMember;
use App\Domain\Exception\NotFoundException;
use App\Domain\Repository\RoomMembershipRepositoryInterface;
use App\Domain\Service\ChangeEditPermission;
use App\Domain\Service\ReservationDateRule;
use App\Domain\ValueObject\UserRole;
use App\Domain\ValueObject\MealType;
use App\Infrastructure\Persistence\Table\MUserGroupTable;
use App\Service\RoomMemberOrderService;
use Cake\Chronos\ChronosDate;
use Cake\ORM\Table;
use Cake\ORM\TableRegistry;
use Psr\Clock\ClockInterface;

/**
 * ChangeEditQueryInterface の CakePHP 実装（m_room_info・m_user_group・m_user_info・t_individual_reservation_info の読み取り）。
 */
final class CakeChangeEditQuery implements ChangeEditQueryInterface
{
    public function __construct(
        private readonly ClockInterface $clock,
        private readonly RoomMembershipRepositoryInterface $rooms,
    ) {
    }

    private static function table(string $alias): Table
    {
        return TableRegistry::getTableLocator()->get($alias);
    }

    public function defaultRoomId(array $rooms, string $date): ?int
    {
        if ($rooms === []) {
            return null;
        }
        if (count($rooms) === 1) {
            return (int)array_key_first($rooms);
        }

        $row = self::table('TIndividualReservationInfo')->find()
            ->select(['i_id_room'])
            ->where([
                'd_reservation_date'    => $date,
                'i_id_room IN'          => array_keys($rooms),
                'i_reservation_type IN' => MealType::values(),
            ])
            ->first();

        return $row ? (int)$row->i_id_room : (int)array_key_first($rooms);
    }

    public function roomName(int $roomId): ?string
    {
        $room = self::table('MRoomInfo')->find()
            ->select(['c_room_name'])
            ->where(['i_id_room' => $roomId])
            ->first();

        return $room === null ? null : (string)$room->c_room_name;
    }

    public function context(int $roomId, string $date, int $loginUserId, int $loginAdmin, int $loginLevel): ChangeEditContext
    {
        $roomName = $this->roomName($roomId) ?? throw new NotFoundException('部屋が見つかりません。');
        // その部屋に所属するブロック長は、部屋の全員の直前の予約を変えられる
        $canEditAll = ChangeEditPermission::canEditAll(
            $loginAdmin,
            UserRole::isBlockLeader($loginAdmin) && $this->rooms->isActiveMember($loginUserId, $roomId),
        );

        $memberIds = self::table('MUserGroup')->find()
            ->select(['i_id_user'])
            ->contain(['MUserInfo'])
            ->where([
                'MUserGroup.i_id_room' => $roomId,
                ...MUserGroupTable::activeCondition(),
                'MUserInfo.i_del_flag' => 0,
            ])
            ->enableHydration(false)->all()->extract('i_id_user')->toList();
        $memberIds = array_values(array_filter($memberIds, static fn($id) => $id !== null && $id > 0));

        $users = self::table('MUserInfo');
        $members = [];
        foreach (RoomMemberOrderService::orderMembers($users->find(), $users->getAlias())
            ->select(['i_id_user', 'c_user_name', 'i_user_level'])
            ->where(['i_id_user IN' => $memberIds ?: [-1], 'i_del_flag' => 0])
            ->all() as $u) {
            $id    = (int)$u->i_id_user;
            $level = (int)$u->i_user_level;
            $members[] = new ChangeEditMember($id, (string)$u->c_user_name, $level, ChangeEditPermission::canEdit($canEditAll, $loginUserId, $loginLevel, $id, $level));
        }

        $reservations = [];
        $ids = array_map(static fn(ChangeEditMember $m): int => $m->id, $members);
        foreach (self::table('TIndividualReservationInfo')->find()
            ->contain(['MRoomInfo'])
            ->where([
                'd_reservation_date'    => $date,
                'i_reservation_type IN' => MealType::values(),
                'i_id_user IN'          => $ids ?: [-1],
            ])->all() as $r) {
            $reservations[(int)$r->i_id_user][(int)$r->i_reservation_type] = [
                'room_id'       => (int)$r->i_id_room,
                'eat_flag'      => (int)$r->eat_flag,
                'room_name'     => (string)($r->m_room_info->c_room_name ?? '不明な部屋'),
                'i_change_flag' => $r->i_change_flag !== null ? (int)$r->i_change_flag : null,
            ];
        }

        return new ChangeEditContext($roomId, $roomName, $members, $reservations);
    }

    public function ownMeals(int $userId, string $date): array
    {
        $target = ReservationDateRule::parse($date);
        if ($target === null) {
            return [];
        }
        $today = new ChronosDate($this->clock->now());
        $meals = [];
        foreach (self::table('TIndividualReservationInfo')->find()
            ->select(['i_reservation_type', 'i_id_room', 'eat_flag', 'i_change_flag'])
            ->where(['i_id_user' => $userId, 'd_reservation_date' => $date])
            ->all() as $r) {
            $counted = ReservationDateRule::countedValue(
                $target,
                $r->eat_flag === null ? null : (int)$r->eat_flag,
                $r->i_change_flag === null ? null : (int)$r->i_change_flag,
                $today,
            );
            if ($counted === 1) {
                $meals[] = ['type' => (int)$r->i_reservation_type, 'room_id' => (int)$r->i_id_room];
            }
        }

        return $meals;
    }
}
