<?php
declare(strict_types=1);

namespace App\Infrastructure\Repository;

use App\Domain\Entity\UserAccount;
use App\Domain\Entity\UserCreationResult;
use App\Domain\Entity\UserUpdateResult;
use App\Domain\Repository\UserAccountRepositoryInterface;
use App\Domain\Service\DeletedMembershipRule;
use App\Domain\ValueObject\RoomMembershipStatus;
use App\Infrastructure\Persistence\Table\MUserGroupTable;
use Cake\Datasource\EntityInterface;
use Cake\I18n\DateTime;
use Cake\ORM\Table;
use Cake\ORM\TableRegistry;

/**
 * UserAccountRepositoryInterface の CakePHP 実装（m_user_info）。
 *
 * 保存はテーブルの save() を通す（従来どおり、ルール・イベントを通すため）。
 */
final class CakeUserAccountRepository implements UserAccountRepositoryInterface
{
    /** updateProfile で保存前後を返す項目。 */
    private const PROFILE_FIELDS = ['c_login_account', 'c_user_name', 'i_user_gender', 'i_user_rank', 'i_user_level', 'i_id_staff'];

    private readonly Table $groups;

    public function __construct(private readonly Table $users, ?Table $groups = null)
    {
        $this->groups = $groups ?? TableRegistry::getTableLocator()->get('MUserGroup');
    }

    public function loginAccountExists(string $loginAccount): bool
    {
        return (bool)$this->users->find()->where(['c_login_account' => $loginAccount])->first();
    }

    public function nextDisplayNo(): int
    {
        $row = $this->users->find()
            ->select(['max_no' => $this->users->find()->func()->max('i_disp_no')])
            ->first();

        return $row !== null && $row->get('max_no') !== null ? ((int)$row->get('max_no') + 1) : 1;
    }

    public function create(array $attributes, array $input, array $roomIds, string $createdBy): UserCreationResult
    {
        $user = $this->users->newEmptyEntity();
        foreach ($attributes as $field => $value) {
            $user->set($field, $value);
        }
        // 管理者以外も登録できるため、権限・状態系の項目は送られた値から受け取らない
        $user = $this->users->patchEntity($user, $input, [
            'fields' => ['c_login_account', 'c_login_passwd', 'c_user_name', 'c_create_user'],
        ]);
        if ($user->hasErrors()) {
            return UserCreationResult::invalid($user->getErrors());
        }
        if (!$this->users->save($user)) {
            return UserCreationResult::saveFailed();
        }

        $userId = (int)$user->get('i_id_user');
        $groups = [];
        foreach ($roomIds as $roomId) {
            $groups[] = $this->groups->newEntity([
                'i_id_user'     => $userId,
                'i_id_room'     => (int)$roomId,
                'active_flag'   => RoomMembershipStatus::ACTIVE,
                'dt_create'     => DateTime::now()->format('Y-m-d H:i:s'),
                'c_create_user' => $createdBy,
            ]);
        }
        if ($groups !== []) {
            $this->groups->saveMany($groups);
        }

        return UserCreationResult::created($userId);
    }

    public function find(int $userId): ?UserAccount
    {
        $row = $this->row($userId);

        return $row === null ? null : $this->toAccount($row);
    }

    public function findByLoginAccount(string $loginAccount): ?UserAccount
    {
        /** @var \Cake\Datasource\EntityInterface|null $row */
        $row = $this->users->find()->where(['c_login_account' => $loginAccount])->first();

        return $row === null ? null : $this->toAccount($row);
    }

    private function toAccount(EntityInterface $row): UserAccount
    {
        return new UserAccount(
            (int)$row->get('i_id_user'),
            $row->get('c_user_name') === null ? null : (string)$row->get('c_user_name'),
            $row->get('i_admin') === null ? null : (int)$row->get('i_admin'),
            $row->get('i_report_access') === null ? null : (int)$row->get('i_report_access'),
            $row->get('i_del_flag') === null ? null : (int)$row->get('i_del_flag'),
            $row->get('i_user_level') === null ? null : (int)$row->get('i_user_level'),
            $row->get('c_login_account') === null ? null : (string)$row->get('c_login_account'),
        );
    }

    public function updateRole(int $userId, int $role, string $updatedBy): bool
    {
        return $this->update($userId, ['i_admin' => $role], $updatedBy);
    }

    public function updateReportAccess(int $userId, int $reportAccess, string $updatedBy): bool
    {
        return $this->update($userId, ['i_report_access' => $reportAccess], $updatedBy);
    }

    public function markDeleted(int $userId, string $updatedBy, ?string $at = null): bool
    {
        return $this->update($userId, ['i_del_flag' => 1, 'i_enable' => 1], $updatedBy, $at);
    }

    public function markRestored(int $userId, string $updatedBy): bool
    {
        // 削除時に立てた無効化（i_enable=1）も戻す（ログインできるようにする）
        return $this->update($userId, ['i_del_flag' => 0, 'i_enable' => 0], $updatedBy);
    }

    public function leaveAllRooms(int $userId, string $updatedBy): void
    {
        $rows = $this->groups->find()->where(['i_id_user' => $userId, ...MUserGroupTable::activeCondition('')])->all();
        foreach ($rows as $row) {
            $this->setUpdated($row, ['active_flag' => RoomMembershipStatus::LEFT], $updatedBy);
            $this->groups->save($row);
        }
    }

    public function leaveAllRoomsForDeletion(int $userId, string $updatedBy, string $at): bool
    {
        $rows = $this->groups->find()->where(['i_id_user' => $userId, ...MUserGroupTable::activeCondition('')])->all();
        foreach ($rows as $row) {
            $this->setUpdated($row, ['active_flag' => RoomMembershipStatus::LEFT, 'i_left_by_deletion' => 1], $updatedBy, $at);
            if (!$this->groups->save($row)) {
                return false;
            }
        }

        return true;
    }

    public function roomsLeftByDeletion(int $userId): array
    {
        $user = $this->row($userId);
        if ($user === null) {
            return [];
        }
        $rows     = $this->groups->find()->where(['i_id_user' => $userId])->orderByAsc('i_id_room')->all()->toList();
        $hasMarks = false;
        foreach ($rows as $row) {
            $hasMarks = $hasMarks || (int)$row->get('i_left_by_deletion') === 1;
        }

        $roomIds = [];
        foreach ($rows as $row) {
            if (DeletedMembershipRule::isLeftByDeletion(
                $row->get('active_flag') === null ? null : (int)$row->get('active_flag'),
                $row->get('i_left_by_deletion') === null ? null : (int)$row->get('i_left_by_deletion'),
                $hasMarks,
                $row->get('dt_update'),
                $user->get('dt_update'),
            )) {
                $roomIds[] = (int)$row->get('i_id_room');
            }
        }

        return $roomIds;
    }

    public function freezeLeftByDeletion(int $userId): void
    {
        $user = $this->row($userId);
        if ($user === null || (int)$user->get('i_del_flag') !== 1) {
            return;
        }
        if ($this->groups->exists(['i_id_user' => $userId, 'i_left_by_deletion' => 1])) {
            return;
        }
        $roomIds = $this->roomsLeftByDeletion($userId);
        if ($roomIds === []) {
            return;
        }
        // 時刻（dt_update）は変えずに印だけを付ける
        $this->groups->updateAll(['i_left_by_deletion' => 1], ['i_id_user' => $userId, 'i_id_room IN' => $roomIds]);
    }

    public function rejoinRooms(int $userId, array $roomIds, string $updatedBy): bool
    {
        if ($roomIds === []) {
            return true;
        }
        $rows = $this->groups->find()->where(['i_id_user' => $userId, 'i_id_room IN' => $roomIds])->all();
        foreach ($rows as $row) {
            $this->setUpdated($row, ['active_flag' => RoomMembershipStatus::ACTIVE, 'i_left_by_deletion' => null], $updatedBy);
            if (!$this->groups->save($row)) {
                return false;
            }
        }

        return true;
    }

    public function joinRoom(int $userId, int $roomId, string $createdBy): bool
    {
        $row = $this->groups->newEntity([
            'i_id_user'     => $userId,
            'i_id_room'     => $roomId,
            'active_flag'   => RoomMembershipStatus::ACTIVE,
            'dt_create'     => DateTime::now()->format('Y-m-d H:i:s'),
            'c_create_user' => $createdBy,
        ]);

        return (bool)$this->groups->save($row);
    }

    public function updateProfile(int $userId, array $data, array $fields): UserUpdateResult
    {
        $user = $this->row($userId);
        if ($user === null) {
            return UserUpdateResult::failed([]);
        }
        $before = $user->extract(self::PROFILE_FIELDS);
        $user = $this->users->patchEntity($user, $data, ['fields' => $fields]);
        if (!$this->users->save($user)) {
            return UserUpdateResult::failed($user->getErrors());
        }

        return UserUpdateResult::saved($before, $user->extract(self::PROFILE_FIELDS));
    }

    public function replaceActiveRooms(int $userId, array $roomIds, string $updatedBy): bool
    {
        // 部屋異動で外れた旧部屋の行(active_flag=1)まで消して作り直すと、保存のたびに旧部屋の所属が復活して食数が二重になる
        $conditions = MUserGroupTable::activeCondition('');
        if ($roomIds !== []) {
            $conditions = ['OR' => [...MUserGroupTable::activeCondition(''), 'i_id_room IN' => array_map('intval', $roomIds)]];
        }
        $this->groups->deleteAll(['i_id_user' => $userId] + $conditions);

        $now  = DateTime::now()->format('Y-m-d H:i:s');
        $rows = [];
        foreach ($roomIds as $roomId) {
            $rows[] = $this->groups->newEntity([
                'i_id_user'     => $userId,
                'i_id_room'     => (int)$roomId,
                'active_flag'   => RoomMembershipStatus::ACTIVE,
                'dt_create'     => $now,
                'dt_update'     => $now,
                'c_update_user' => $updatedBy,
            ]);
        }

        return $rows === [] || $this->groups->saveMany($rows) !== false;
    }

    public function activeUserNames(): array
    {
        return $this->users->find('list', keyField: 'i_id_user', valueField: 'c_user_name')
            ->where(['i_del_flag' => 0])
            ->toArray();
    }

    public function changePassword(int $userId, string $plainPassword): bool
    {
        $user = $this->row($userId);
        if ($user === null) {
            return false;
        }
        $user->set('c_login_passwd', $plainPassword);

        return (bool)$this->users->save($user);
    }

    public function importUser(array $data): UserCreationResult
    {
        $user = $this->users->newEntity($data);
        if ($user->hasErrors()) {
            return UserCreationResult::invalid($user->getErrors());
        }
        if (!$this->users->save($user)) {
            return UserCreationResult::saveFailed();
        }

        return UserCreationResult::created((int)$user->get('i_id_user'));
    }

    public function transferRoom(int $userId, ?int $fromRoomId, int $toRoomId, string $at): void
    {
        if ($fromRoomId !== null) {
            $this->groups->updateAll(
                ['active_flag' => RoomMembershipStatus::LEFT, 'dt_update' => $at, 'c_update_user' => 'batch'],
                ['i_id_user' => $userId, 'i_id_room' => $fromRoomId, ...MUserGroupTable::activeCondition('')]
            );
        }
        $row = $this->groups->find()->where(['i_id_user' => $userId, 'i_id_room' => $toRoomId])->first();
        if ($row !== null) {
            $row->set('active_flag', RoomMembershipStatus::ACTIVE);
            $row->set('dt_update', $at);
            $row->set('c_update_user', 'batch');
            $this->groups->saveOrFail($row);
            return;
        }
        $this->groups->saveOrFail($this->groups->newEntity([
            'i_id_user'     => $userId,
            'i_id_room'     => $toRoomId,
            'active_flag'   => RoomMembershipStatus::ACTIVE,
            'dt_create'     => $at,
            'c_create_user' => 'batch',
        ]));
    }

    public function childUserIds(): array
    {
        return array_map('intval', $this->users->find()
            ->select(['i_id_user'])
            ->where(['i_user_level' => 1, 'i_del_flag' => 0])
            ->enableHydration(false)
            ->all()
            ->extract('i_id_user')
            ->toList());
    }

    /**
     * @param array<string, mixed> $fields
     */
    private function setUpdated(EntityInterface $row, array $fields, string $updatedBy, ?string $at = null): void
    {
        foreach ($fields + [
            'dt_update'     => $at ?? DateTime::now()->format('Y-m-d H:i:s'),
            'c_update_user' => $updatedBy,
        ] as $field => $value) {
            $row->set($field, $value);
        }
    }

    /**
     * @param array<string, mixed> $fields
     */
    private function update(int $userId, array $fields, string $updatedBy, ?string $at = null): bool
    {
        // 削除済みの人の行を更新すると、時刻で見分けていた「削除で外した所属」が分からなくなるため、先に印を付ける
        $this->freezeLeftByDeletion($userId);
        $row = $this->row($userId);
        if ($row === null) {
            return false;
        }
        $this->setUpdated($row, $fields, $updatedBy, $at);

        return (bool)$this->users->save($row);
    }

    private function row(int $userId): ?EntityInterface
    {
        /** @var \Cake\Datasource\EntityInterface|null */
        return $this->users->find()->where(['i_id_user' => $userId])->first();
    }
}
