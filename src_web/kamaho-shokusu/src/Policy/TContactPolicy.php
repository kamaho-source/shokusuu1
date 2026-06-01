<?php
declare(strict_types=1);

namespace App\Policy;

use App\Model\Entity\TContact;
use Authorization\IdentityInterface;

/**
 * お問い合わせのアクセス制御ポリシー
 *
 * index: 全認証ユーザー
 * adminIndex / adminDetail: 管理者（i_admin = 1）のみ
 */
class TContactPolicy
{
    /**
     * お問い合わせフォーム（全認証ユーザー）
     */
    public function canIndex(?IdentityInterface $user, TContact $resource): bool
    {
        return $this->isAuthenticated($user);
    }

    /**
     * 管理者用：お問い合わせ一覧
     */
    public function canAdminIndex(?IdentityInterface $user, TContact $resource): bool
    {
        return $this->isAdmin($user);
    }

    /**
     * 管理者用：お問い合わせ詳細・返信
     */
    public function canAdminDetail(?IdentityInterface $user, TContact $resource): bool
    {
        return $this->isAdmin($user);
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
            return in_array((int)$identity->get('i_admin'), [1, 3]);
        }

        if (is_array($identity)) {
            return in_array((int)($identity['i_admin'] ?? 0), [1, 3]);
        }

        if ($identity instanceof \ArrayAccess) {
            return in_array((int)($identity['i_admin'] ?? 0), [1, 3]);
        }

        return false;
    }

    private function getOriginalIdentity(?IdentityInterface $user): mixed
    {
        if ($user === null) {
            return null;
        }

        return $user->getOriginalData();
    }
}
