<?php
declare(strict_types=1);

namespace App\Policy;

use App\Domain\ValueObject\UserRole;

use Authorization\IdentityInterface;

/**
 * お問い合わせ機能のアクセス制御ポリシー
 *
 * - index / adminDetail の送信：認証済みユーザー全員
 * - adminIndex / adminDetail：管理者（i_admin = 1）のみ
 */
final class ContactsPolicy
{
    /** お問い合わせフォーム（全認証ユーザー） */
    public function canIndex(?IdentityInterface $user, mixed $resource): bool
    {
        return $this->isAuthenticated($user);
    }

    /** 管理者：問い合わせ一覧 */
    public function canAdminIndex(?IdentityInterface $user, mixed $resource): bool
    {
        return $this->isAdmin($user);
    }

    /** 管理者：問い合わせ詳細・返信 */
    public function canAdminDetail(?IdentityInterface $user, mixed $resource): bool
    {
        return $this->isAdmin($user);
    }

    private function isAuthenticated(?IdentityInterface $user): bool
    {
        return $user !== null;
    }

    private function isAdmin(?IdentityInterface $user): bool
    {
        if ($user === null) {
            return false;
        }
        $identity = $user->getOriginalData();

        if (is_object($identity) && method_exists($identity, 'get')) {
            return UserRole::isAdmin((int)$identity->get('i_admin'));
        }

        if (is_array($identity) || $identity instanceof \ArrayAccess) {
            return UserRole::isAdmin((int)($identity['i_admin'] ?? 0));
        }

        return false;
    }
}
