<?php
declare(strict_types=1);

namespace App\Policy;

use App\Domain\ValueObject\UserRole;

use Authorization\IdentityInterface;

/**
 * お問い合わせ機能のアクセス制御ポリシー
 *
 * - index：未ログインを含む全ユーザー（LPからの問い合わせ導線のため）
 * - adminIndex / adminDetail：管理者（i_admin = 1）のみ
 */
final class ContactsPolicy
{
    /** お問い合わせフォーム（未ログインを含む全ユーザー） */
    public function canIndex(?IdentityInterface $user, \App\Controller\ContactsController $resource): bool
    {
        return true;
    }

    /** 管理者：問い合わせ一覧 */
    public function canAdminIndex(?IdentityInterface $user, \App\Controller\ContactsController $resource): bool
    {
        return $this->isAdmin($user);
    }

    /** 管理者：問い合わせ詳細・返信 */
    public function canAdminDetail(?IdentityInterface $user, \App\Controller\ContactsController $resource): bool
    {
        return $this->isAdmin($user);
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
