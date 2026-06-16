<?php
declare(strict_types=1);

namespace App\Policy;

use App\Domain\ValueObject\UserRole;

use App\Model\Entity\TReservationInfo;
use App\Service\RoomAccessService;
use Authorization\IdentityInterface;

class TReservationInfoPolicy
{
    private RoomAccessService $roomAccessService;

    public function __construct(?RoomAccessService $roomAccessService = null)
    {
        $this->roomAccessService = $roomAccessService ?? new RoomAccessService();
    }

    public function canAdd(?IdentityInterface $user, TReservationInfo $resource): bool
    {
        return $this->isStaffOrAdmin($user);
    }

    public function canCopy(?IdentityInterface $user, TReservationInfo $resource): bool
    {
        return $this->isAdmin($user);
    }

    public function canBulkAddSubmit(?IdentityInterface $user, TReservationInfo $resource): bool
    {
        return $this->isAdmin($user) || $this->isStaff($user);
    }

    public function canBulkChangeEditSubmit(?IdentityInterface $user, TReservationInfo $resource): bool
    {
        return $this->isAdmin($user) || $this->isStaff($user);
    }

    public function canChangeEdit(?IdentityInterface $user, TReservationInfo $resource): bool
    {
        return $this->isStaffOrAdmin($user) || $this->isRoomAffiliated($user);
    }

    public function canToggle(?IdentityInterface $user, TReservationInfo $resource): bool
    {
        if (!$this->isAuthenticated($user)) {
            return false;
        }

        if (!$this->canAccessRoom($user, $resource)) {
            return false;
        }

        $requestedUserId = (int)($resource->get('i_id_user') ?? 0);
        if ($requestedUserId <= 0) {
            // i_id_user が 0 の場合は全員向けトグルとして扱うが、管理者・職員のみに限定する（IDOR防止）。
            return $this->isStaffOrAdmin($user);
        }

        $loginUserId = $this->getUserId($user);

        // 自分自身の予約操作は常に許可。
        if ($requestedUserId === $loginUserId) {
            return true;
        }

        // 管理者は全員を編集可能
        if ($this->isAdmin($user)) {
            return true;
        }

        // 職員IDを持つユーザーは子供の予約編集を試みられる（サービス層で対象が子供かを検証）
        if ($this->hasStaffId($user)) {
            return true;
        }

        return false;
    }

    public function canEvents(?IdentityInterface $user, TReservationInfo $resource): bool
    {
        return $this->isStaffOrAdmin($user);
    }

    public function canCalendarEvents(?IdentityInterface $user, TReservationInfo $resource): bool
    {
        return $this->isStaffOrAdmin($user);
    }

    public function canCheckDuplicateReservation(?IdentityInterface $user, TReservationInfo $resource): bool
    {
        return $this->isStaffOrAdmin($user) && $this->canAccessRoom($user, $resource);
    }

    public function canRoomDetails(?IdentityInterface $user, TReservationInfo $resource): bool
    {
        return $this->isStaffOrAdmin($user) && $this->canAccessRoom($user, $resource);
    }

    public function canIndex(?IdentityInterface $user, TReservationInfo $resource): bool
    {
        return $this->isAuthenticated($user);
    }

    public function canView(?IdentityInterface $user, TReservationInfo $resource): bool
    {
        return $this->isAuthenticated($user);
    }

    public function canBulkAddForm(?IdentityInterface $user, TReservationInfo $resource): bool
    {
        return $this->isStaffOrAdmin($user);
    }

    public function canBulkChangeEditForm(?IdentityInterface $user, TReservationInfo $resource): bool
    {
        return $this->isStaffOrAdmin($user);
    }

    public function canGetUsersByRoom(?IdentityInterface $user, TReservationInfo $resource): bool
    {
        return $this->isStaffOrAdmin($user) && $this->canAccessRoom($user, $resource);
    }

    public function canGetUsersByRoomForBulk(?IdentityInterface $user, TReservationInfo $resource): bool
    {
        return $this->isStaffOrAdmin($user) && $this->canAccessRoom($user, $resource);
    }

    public function canGetUsersByRoomForEdit(?IdentityInterface $user, TReservationInfo $resource): bool
    {
        return $this->isStaffOrAdmin($user) && $this->canAccessRoom($user, $resource);
    }

    public function canGetPersonalReservation(?IdentityInterface $user, TReservationInfo $resource): bool
    {
        return $this->isAuthenticated($user);
    }

    public function canGetReservationSnapshots(?IdentityInterface $user, TReservationInfo $resource): bool
    {
        return $this->isStaffOrAdmin($user) && $this->canAccessRoom($user, $resource);
    }

    public function canExportJson(?IdentityInterface $user, TReservationInfo $resource): bool
    {
        return $this->isStaffOrAdmin($user);
    }

    public function canExportJsonrank(?IdentityInterface $user, TReservationInfo $resource): bool
    {
        return $this->isStaffOrAdmin($user);
    }

    public function canReportNoMeal(?IdentityInterface $user, TReservationInfo $resource): bool
    {
        return $this->isAuthenticated($user);
    }

    public function canReportEat(?IdentityInterface $user, TReservationInfo $resource): bool
    {
        return $this->isAuthenticated($user);
    }

    public function canGetAllRoomsMealCounts(?IdentityInterface $user, TReservationInfo $resource): bool
    {
        return $this->isAdmin($user);
    }

    public function canGetRoomMealCounts(?IdentityInterface $user, TReservationInfo $resource): bool
    {
        return $this->isStaffOrAdmin($user) && $this->canAccessRoom($user, $resource);
    }

    public function canActualMealManagement(?IdentityInterface $user, TReservationInfo $resource): bool
    {
        return $this->isBlockLeaderOrAdmin($user);
    }

    public function canActualMealSave(?IdentityInterface $user, TReservationInfo $resource): bool
    {
        if (!$this->isAuthenticated($user)) {
            return false;
        }

        $requestedUserId = (int)($resource->get('i_id_user') ?? 0);
        $loginUserId = $this->getUserId($user);

        // 管理者は常に許可
        if ($this->isAdmin($user)) {
            return true;
        }

        // ブロック長は担当部屋のユーザーであれば許可
        if ($this->isBlockLeader($user)) {
            return $this->canAccessRoom($user, $resource);
        }

        // それ以外は本人のみ許可
        return $requestedUserId > 0 && $requestedUserId === $loginUserId;
    }

    public function canActualMealRequestApproval(?IdentityInterface $user, TReservationInfo $resource): bool
    {
        return $this->canActualMealSave($user, $resource);
    }

    public function canMyActualMeal(?IdentityInterface $user, TReservationInfo $resource): bool
    {
        if (!$this->isAuthenticated($user)) {
            return false;
        }

        $requestedUserId = (int)($resource->get('i_id_user') ?? 0);
        if ($requestedUserId <= 0) {
            return true;
        }

        return $requestedUserId === $this->getUserId($user);
    }

    public function canMealCountGrid(?IdentityInterface $user, TReservationInfo $resource): bool
    {
        return $this->isAuthenticated($user);
    }

    public function canWeeklyMealGrid(?IdentityInterface $user, TReservationInfo $resource): bool
    {
        return $this->isStaffOrAdmin($user);
    }

    private function isAuthenticated(?IdentityInterface $user): bool
    {
        return $this->getOriginalIdentity($user) !== null;
    }

    private function isAdmin(?IdentityInterface $user): bool
    {
        $identity = $this->getOriginalIdentity($user);
        if ($identity === null) {
            return false;
        }

        if (is_object($identity) && method_exists($identity, 'get')) {
            return UserRole::isAdmin((int)$identity->get('i_admin'));
        }

        if (is_array($identity)) {
            return UserRole::isAdmin((int)($identity['i_admin'] ?? 0));
        }

        if ($identity instanceof \ArrayAccess) {
            return UserRole::isAdmin((int)($identity['i_admin'] ?? 0));
        }

        return false;
    }

    private function isStaff(?IdentityInterface $user): bool
    {
        $identity = $this->getOriginalIdentity($user);
        if ($identity === null) {
            return false;
        }

        if (is_object($identity) && method_exists($identity, 'get')) {
            return (int)$identity->get('i_user_level') === 0;
        }

        if (is_array($identity)) {
            return (int)($identity['i_user_level'] ?? -1) === 0;
        }

        if ($identity instanceof \ArrayAccess) {
            return (int)($identity['i_user_level'] ?? -1) === 0;
        }

        return false;
    }

    public function isBlockLeader(?IdentityInterface $user): bool
    {
        $identity = $this->getOriginalIdentity($user);
        if ($identity === null) {
            return false;
        }

        if (is_object($identity) && method_exists($identity, 'get')) {
            return UserRole::isBlockLeader((int)$identity->get('i_admin'));
        }

        if (is_array($identity)) {
            return UserRole::isBlockLeader((int)($identity['i_admin'] ?? 0));
        }

        if ($identity instanceof \ArrayAccess) {
            return UserRole::isBlockLeader((int)($identity['i_admin'] ?? 0));
        }

        return false;
    }

    private function isStaffOrAdmin(?IdentityInterface $user): bool
    {
        return $this->isAdmin($user) || $this->isStaff($user);
    }

    private function hasStaffId(?IdentityInterface $user): bool
    {
        $identity = $this->getOriginalIdentity($user);
        if ($identity === null) {
            return false;
        }

        $staffId = null;
        if (is_object($identity) && method_exists($identity, 'get')) {
            $staffId = $identity->get('i_id_staff');
        } elseif (is_array($identity) || $identity instanceof \ArrayAccess) {
            $staffId = $identity['i_id_staff'] ?? null;
        }

        return $staffId !== null && $staffId !== '' && $staffId !== 0;
    }

    public function isBlockLeaderOrAdmin(?IdentityInterface $user): bool
    {
        return $this->isAdmin($user) || $this->isBlockLeader($user);
    }

    private function canAccessRoom(?IdentityInterface $user, TReservationInfo $resource): bool
    {
        $requestedRoomId = (int)($resource->get('i_id_room') ?? 0);
        if ($requestedRoomId <= 0) {
            return true;
        }

        if ($this->isAdmin($user)) {
            return true;
        }

        $userId = $this->getUserId($user);
        if ($userId <= 0) {
            return false;
        }

        return $this->roomAccessService->userCanAccessRoom($userId, $requestedRoomId);
    }

    private function getUserId(?IdentityInterface $user): int
    {
        $identity = $this->getOriginalIdentity($user);
        if ($identity === null) {
            return 0;
        }

        if (is_object($identity) && method_exists($identity, 'get')) {
            return (int)$identity->get('i_id_user');
        }

        if (is_array($identity)) {
            return (int)($identity['i_id_user'] ?? 0);
        }

        if ($identity instanceof \ArrayAccess) {
            return (int)($identity['i_id_user'] ?? 0);
        }

        return 0;
    }

    private function getOriginalIdentity(?IdentityInterface $user): mixed
    {
        if ($user === null) {
            return null;
        }

        return $user->getOriginalData();
    }
}