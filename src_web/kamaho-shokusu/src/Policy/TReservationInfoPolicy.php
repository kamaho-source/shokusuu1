<?php
declare(strict_types=1);

namespace App\Policy;

use App\Model\Entity\TReservationInfo;
use App\Service\RoomAccessService;
use Authorization\IdentityInterface;

class TReservationInfoPolicy
{
    use PolicyTrait;

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
        return $this->isAuthenticated($user);
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

        // 職員（level 0/7）は子供の予約編集を試みられる（サービス層で対象が子供かを検証）
        if ($this->isStaff($user)) {
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
        return $this->isAuthenticated($user) && $this->canAccessRoom($user, $resource);
    }

    public function canGetUsersByRoomForBulk(?IdentityInterface $user, TReservationInfo $resource): bool
    {
        return $this->isStaffOrAdmin($user) && $this->canAccessRoom($user, $resource);
    }

    public function canGetUsersByRoomForEdit(?IdentityInterface $user, TReservationInfo $resource): bool
    {
        return $this->isStaffOrAdmin($user) && $this->canAccessRoom($user, $resource);
    }

    /**
     * ログインユーザー自身の予約データ取得のみ許可。
     * コントローラーはリクエストから userId を受け取らず、identity から強制取得するため IDOR 不可。
     */
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

    /**
     * 実食なし報告はログインユーザー自身にのみ作用する。
     * Trait 実装が identity から userId を強制取得するため IDOR 不可。
     */
    public function canReportNoMeal(?IdentityInterface $user, TReservationInfo $resource): bool
    {
        return $this->isAuthenticated($user);
    }

    /**
     * 実食あり報告はログインユーザー自身にのみ作用する。
     * Trait 実装が identity から userId を強制取得するため IDOR 不可。
     */
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

    /**
     * ユーザーがいずれかの部屋に所属しているかを確認する（居住者・所属職員）。
     * canChangeEdit で職員・管理者以外の部屋所属ユーザーに変更権限を与えるために使用する。
     */
    private function isRoomAffiliated(?IdentityInterface $user): bool
    {
        $userId = $this->getUserId($user);
        if ($userId <= 0) {
            return false;
        }
        return $this->roomAccessService->hasAnyAffiliation($userId);
    }
}
