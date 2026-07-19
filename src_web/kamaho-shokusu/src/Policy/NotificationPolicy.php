<?php
declare(strict_types=1);

namespace App\Policy;

use Authorization\IdentityInterface;

/**
 * 通知機能のアクセス制御ポリシー
 *
 * 認証済みユーザーは自分の通知のみ操作可能。
 * 実際のユーザー絞り込みは NotificationService の各メソッドで行う。
 */
final class NotificationPolicy
{
    /** 通知一覧の閲覧 */
    public function canIndex(?IdentityInterface $user, \App\Controller\NotificationsController $resource): bool
    {
        return $this->isAuthenticated($user);
    }

    /** 通知の既読化 */
    public function canMarkRead(?IdentityInterface $user, \App\Controller\NotificationsController $resource): bool
    {
        return $this->isAuthenticated($user);
    }

    /** 全通知の既読化 */
    public function canMarkAllRead(?IdentityInterface $user, \App\Controller\NotificationsController $resource): bool
    {
        return $this->isAuthenticated($user);
    }

    private function isAuthenticated(?IdentityInterface $user): bool
    {
        return $user !== null;
    }
}
