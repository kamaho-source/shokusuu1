<?php
declare(strict_types=1);

namespace App\Policy;

use Authorization\IdentityInterface;

/**
 * お問い合わせ機能のアクセス制御ポリシー
 *
 * - index / adminDetail の送信：認証済みユーザー全員
 * - adminIndex / adminDetail：管理者（i_admin = 1）のみ
 * リソースは Controller のため、テナント境界チェックはクエリ層に委ねる。
 */
final class ContactsPolicy
{
    use PolicyTrait;

    /** お問い合わせフォーム（全認証ユーザー） */
    public function canIndex(?IdentityInterface $user, \App\Controller\ContactsController $resource): bool
    {
        return $this->isAuthenticated($user);
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
}
