<?php
declare(strict_types=1);

namespace App\Policy;

use App\Domain\ValueObject\UserRole;
use Authorization\IdentityInterface;

/**
 * 予約履歴画面用認可ポリシー
 *
 * 管理者・システム管理者・ブロック長・職員（i_user_level 0/7）だけが開ける。
 * 誰の履歴を見られるかは ReservationHistoryService::selectableTargets() が決める。
 */
class ReservationHistoryPolicy
{
    /**
     * @param IdentityInterface|null $user
     * @param \App\Controller\ReservationHistoryController $resource
     * @return bool
     */
    public function canIndex(?IdentityInterface $user, \App\Controller\ReservationHistoryController $resource): bool
    {
        if ($user === null) {
            return false;
        }
        $identity = $user->getOriginalData();
        if (is_object($identity) && method_exists($identity, 'get')) {
            $admin = (int)$identity->get('i_admin');
            $level = $identity->get('i_user_level');
        } elseif (is_array($identity) || $identity instanceof \ArrayAccess) {
            $admin = (int)($identity['i_admin'] ?? 0);
            $level = $identity['i_user_level'] ?? null;
        } else {
            return false;
        }

        return UserRole::canViewReservationHistory($admin, $level === null ? null : (int)$level);
    }
}
