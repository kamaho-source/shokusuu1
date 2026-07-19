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
    use PolicyTrait;

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
}
