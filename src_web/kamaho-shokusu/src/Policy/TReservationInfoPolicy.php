<?php
declare(strict_types=1);

namespace App\Policy;

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
        return $this->isStaffOrAdmin($user);
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
            return true;
        }

        $loginUserId = $this->getUserId($user);
        if ($requestedUserId === $loginUserId) {
            return true;
        }

        return $this->isStaffOrAdmin($user);
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
            return (int)$identity->get('i_admin') === 1;
        }

        if (is_array($identity)) {
            return (int)($identity['i_admin'] ?? 0) === 1;
        }

        if ($identity instanceof \ArrayAccess) {
            return (int)($identity['i_admin'] ?? 0) === 1;
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

    private function isStaffOrAdmin(?IdentityInterface $user): bool
    {
        return $this->isAdmin($user) || $this->isStaff($user);
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

        $roomIds = $this->roomAccessService->getUserRoomIds($userId);
        return in_array($requestedRoomId, $roomIds, true);
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
