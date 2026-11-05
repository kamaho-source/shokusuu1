<?php
declare(strict_types=1);

namespace App\Infrastructure\Query;

use App\Application\Query\UserQueryInterface;
use App\Domain\Entity\UserAccount;
use App\Domain\Repository\RoomMembershipRepositoryInterface;
use App\Domain\Repository\UserAccountRepositoryInterface;
use App\Domain\Service\DeletedMembershipRule;
use App\Domain\ValueObject\RoomMembershipStatus;
use Cake\Datasource\EntityInterface;
use Cake\ORM\TableRegistry;

/**
 * UserQueryInterface の実装。判定は Repository に1つだけあるので、それを呼ぶ。
 */
final class CakeUserQuery implements UserQueryInterface
{
    public function __construct(
        private readonly UserAccountRepositoryInterface $users,
        private readonly RoomMembershipRepositoryInterface $rooms,
    ) {}

    public function find(int $userId): ?UserAccount
    {
        return $this->users->find($userId);
    }

    public function exists(int $userId): bool
    {
        return $this->users->find($userId) !== null;
    }

    public function loginAccountExists(string $loginAccount): bool
    {
        return $this->users->loginAccountExists($loginAccount);
    }

    public function findByLoginAccount(string $loginAccount): ?UserAccount
    {
        return $this->users->findByLoginAccount($loginAccount);
    }

    public function isActive(int $userId): bool
    {
        $user = $this->users->find($userId);

        return $user !== null && !$user->isDeleted();
    }

    public function activeRoomNames(array $userIds): array
    {
        $names = array_fill_keys($userIds, []);
        if ($userIds === []) {
            return $names;
        }
        $users = TableRegistry::getTableLocator()->get('MUserInfo')->find()
            ->select(['i_id_user', 'i_del_flag', 'dt_update'])
            ->where(['i_id_user IN' => $userIds])
            ->contain(['MUserGroup' => ['MRoomInfo']])
            ->all();
        foreach ($users as $user) {
            /** @var \Cake\Datasource\EntityInterface $user */
            $names[(int)$user->get('i_id_user')] = $this->roomNamesOf($user);
        }

        return $names;
    }

    /**
     * @return list<string>
     */
    private function roomNamesOf(EntityInterface $user): array
    {
        $isDeletedUser = (int)$user->get('i_del_flag') === 1;
        /** @var list<\Cake\Datasource\EntityInterface> $groups */
        $groups   = $user->get('m_user_group') ?? [];
        $hasMarks = false;
        foreach ($groups as $group) {
            $hasMarks = $hasMarks || (int)($group->get('i_left_by_deletion') ?? 0) === 1;
        }

        $names = [];
        foreach ($groups as $group) {
            if ($isDeletedUser) {
                $show = DeletedMembershipRule::isLeftByDeletion(
                    $group->get('active_flag') === null ? null : (int)$group->get('active_flag'),
                    $group->get('i_left_by_deletion') === null ? null : (int)$group->get('i_left_by_deletion'),
                    $hasMarks,
                    $group->get('dt_update'),
                    $user->get('dt_update'),
                );
            } else {
                $show = RoomMembershipStatus::isActive($group->get('active_flag'));
            }
            $room = $group->get('m_room_info');
            if (!$show || !$room instanceof EntityInterface || (int)($room->get('i_del_flg') ?? 0) !== 0) {
                continue;
            }
            $names[] = $room->get('c_room_name');
        }

        return $names;
    }

    public function currentRoomIds(int $userId): array
    {
        $ids = [];
        foreach (TableRegistry::getTableLocator()->get('MUserGroup')->find()->select(['i_id_room', 'active_flag'])->where(['i_id_user' => $userId]) as $group) {
            /** @var \Cake\Datasource\EntityInterface $group */
            if (RoomMembershipStatus::isActive($group->get('active_flag'))) {
                $ids[] = (int)$group->get('i_id_room');
            }
        }

        return $ids;
    }

    public function selectableRoomsFor(int $actorId): array
    {
        return $this->rooms->selectableRooms($this->rooms->activeRoomIds($actorId));
    }

    public function passwordResetTargets(): array
    {
        return $this->users->activeUserNames();
    }
}
