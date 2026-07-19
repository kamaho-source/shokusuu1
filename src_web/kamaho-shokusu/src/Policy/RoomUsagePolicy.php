<?php
declare(strict_types=1);

namespace App\Policy;

use Authorization\IdentityInterface;

/**
 * 部屋使用率 API 用認可ポリシー
 *
 * システム管理者（i_admin === 3）のみアクセスを許可する。
 * リソースは Controller のため、テナント境界チェックはクエリ層に委ねる。
 */
class RoomUsagePolicy
{
    use PolicyTrait;

    public function canIndex(?IdentityInterface $user, \App\Controller\RoomUsageController $resource): bool
    {
        return $this->isSystemAdmin($user);
    }

    public function canRoomUsage(?IdentityInterface $user, \App\Controller\RoomUsageController $resource): bool
    {
        return $this->isSystemAdmin($user);
    }

    public function canLowUsageRooms(?IdentityInterface $user, \App\Controller\RoomUsageController $resource): bool
    {
        return $this->isSystemAdmin($user);
    }
}
