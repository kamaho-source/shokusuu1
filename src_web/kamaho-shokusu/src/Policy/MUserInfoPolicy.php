<?php
declare(strict_types=1);

namespace App\Policy;

use App\Domain\ValueObject\UserRole;

use App\Model\Entity\MUserInfo;
use App\Service\RoomAccessService;
use Authorization\IdentityInterface;

class MUserInfoPolicy
{
    private RoomAccessService $roomAccessService;

    public function __construct(?RoomAccessService $roomAccessService = null)
    {
        $this->roomAccessService = $roomAccessService ?? new RoomAccessService();
    }

    public function canIndex(?IdentityInterface $user, MUserInfo $resource): bool
    {
        return $this->getOriginalIdentity($user) !== null;
    }

    public function canView(?IdentityInterface $user, MUserInfo $resource): bool
    {
        return $this->isAdmin($user) || $this->isOwner($user, $resource) || $this->canManageChild($user, $resource);
    }

    public function canAdd(?IdentityInterface $user, MUserInfo $resource): bool
    {
        return $this->canRegisterUsers($user);
    }

    public function canImport(?IdentityInterface $user, MUserInfo $resource): bool
    {
        return $this->canRegisterUsers($user);
    }

    public function canImportForm(?IdentityInterface $user, MUserInfo $resource): bool
    {
        return $this->canRegisterUsers($user);
    }

    public function canImportJson(?IdentityInterface $user, MUserInfo $resource): bool
    {
        return $this->canRegisterUsers($user);
    }

    public function canAdminChangePassword(?IdentityInterface $user, MUserInfo $resource): bool
    {
        return $this->isAdmin($user);
    }

    public function canAddUserRooms(?IdentityInterface $user, MUserInfo $resource): bool
    {
        return $this->isAdmin($user);
    }

    public function canGeneralPasswordReset(?IdentityInterface $user, MUserInfo $resource): bool
    {
        return $this->isOwner($user, $resource) || $this->isAdmin($user);
    }

    public function canEdit(?IdentityInterface $user, MUserInfo $resource): bool
    {
        return $this->isAdmin($user) || $this->isOwner($user, $resource) || $this->canManageChild($user, $resource);
    }

    /**
     * 役職(i_user_level)を変更できるか。管理者が他のユーザーの役職を変えるときだけ。
     * 自分自身の役職は変更できない（権限の取り違え・昇格を防ぐ）。
     */
    public function canChangeRole(?IdentityInterface $user, MUserInfo $resource): bool
    {
        return $this->isAdmin($user) && !$this->isOwner($user, $resource);
    }

    /**
     * 職員(i_user_level=0)またはブロック長が、同じ部屋に現在所属している児童を編集できるか。
     * 管理者以外は児童のみ登録できる、というユーザー登録の考え方に合わせる。
     */
    private function canManageChild(?IdentityInterface $user, MUserInfo $resource): bool
    {
        $identity = $this->getOriginalIdentity($user);
        // 対象は、権限が付いていない一般の児童（削除済みを除く）だけ
        if ($identity === null
            || (int)$resource->i_user_level !== 1
            || (int)($resource->i_admin ?? 0) !== 0
            || (int)($resource->i_del_flag ?? 0) !== 0
        ) {
            return false;
        }

        $get = static function (string $key) use ($identity): int {
            $v = match (true) {
                is_object($identity) && method_exists($identity, 'get') => $identity->get($key),
                is_array($identity), $identity instanceof \ArrayAccess => $identity[$key] ?? null,
                default => null,
            };
            return $v === null ? -1 : (int)$v;
        };

        $actorId = $get('i_id_user');
        if ($actorId <= 0 || $actorId === (int)$resource->i_id_user) {
            return false;
        }
        // 職員は i_user_level 0 と 7（予約まわりの職員判定と同じ）
        if (!UserRole::isBlockLeader($get('i_admin')) && !in_array($get('i_user_level'), [0, 7], true)) {
            return false;
        }

        // 双方が現在所属している（退出済み・削除済みの部屋を除く）部屋が1つでもあること
        $shared = array_intersect(
            $this->roomAccessService->getUserRoomIds($actorId),
            $this->roomAccessService->getUserRoomIds((int)$resource->i_id_user)
        );
        foreach ($shared as $roomId) {
            if ($this->roomAccessService->isActiveMember($actorId, (int)$roomId)
                && $this->roomAccessService->isActiveMember((int)$resource->i_id_user, (int)$roomId)) {
                return true;
            }
        }

        return false;
    }

    public function canDelete(?IdentityInterface $user, MUserInfo $resource): bool
    {
        return $this->isAdmin($user);
    }

    public function canUpdateAdminStatus(?IdentityInterface $user, MUserInfo $resource): bool
    {
        return $this->isAdmin($user) || $this->isSystemAdmin($user);
    }

    public function canUpdateSystemAdminStatus(?IdentityInterface $user, MUserInfo $resource): bool
    {
        return $this->isSystemAdmin($user);
    }

    /**
     * システムレポート閲覧権限の更新可否。
     *
     * @param \App\Model\Entity\MUserInfo $resource
     */
    public function canUpdateReportAccess(?IdentityInterface $user, MUserInfo $resource): bool
    {
        return $this->isSystemAdmin($user);
    }

    public function canRestore(?IdentityInterface $user, MUserInfo $resource): bool
    {
        return $this->isAdmin($user) || $this->isSystemAdmin($user);
    }

    /**
     * ユーザー登録（個別・一括）ができるか。管理者・システム管理者・ブロック長・職員(i_user_level=0 と 7)。
     */
    private function canRegisterUsers(?IdentityInterface $user): bool
    {
        $identity = $this->getOriginalIdentity($user);
        if ($identity === null) {
            return false;
        }

        // 未設定(NULL)は -1 として扱い、どの Identity 形式でも職員(0)とみなさない
        $get = static function (string $key) use ($identity): int {
            $v = match (true) {
                is_object($identity) && method_exists($identity, 'get') => $identity->get($key),
                is_array($identity), $identity instanceof \ArrayAccess => $identity[$key] ?? null,
                default => null,
            };
            return $v === null ? -1 : (int)$v;
        };

        $level = $get('i_user_level');
        return UserRole::canRegisterUsers($get('i_admin'), $level === -1 ? null : $level);
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

    private function isOwner(?IdentityInterface $user, MUserInfo $resource): bool
    {
        $identity = $this->getOriginalIdentity($user);
        if ($identity === null) {
            return false;
        }

        $identityId = null;
        if (is_object($identity) && method_exists($identity, 'get')) {
            $identityId = (int)$identity->get('i_id_user');
        } elseif (is_array($identity)) {
            $identityId = (int)($identity['i_id_user'] ?? 0);
        } elseif ($identity instanceof \ArrayAccess) {
            $identityId = (int)($identity['i_id_user'] ?? 0);
        }

        return $identityId !== null && $identityId > 0 && $identityId === (int)$resource->i_id_user;
    }

    private function isSystemAdmin(?IdentityInterface $user): bool
    {
        $identity = $this->getOriginalIdentity($user);
        if ($identity === null) {
            return false;
        }
        if (is_object($identity) && method_exists($identity, 'get')) {
            return UserRole::isSystemAdmin((int)$identity->get('i_admin'));
        }
        if (is_array($identity)) {
            return UserRole::isSystemAdmin((int)($identity['i_admin'] ?? 0));
        }
        if ($identity instanceof \ArrayAccess) {
            return UserRole::isSystemAdmin((int)($identity['i_admin'] ?? 0));
        }
        return false;
    }

    private function getOriginalIdentity(?IdentityInterface $user): object|array|null
    {
        if ($user === null) {
            return null;
        }

        return $user->getOriginalData();
    }
}
