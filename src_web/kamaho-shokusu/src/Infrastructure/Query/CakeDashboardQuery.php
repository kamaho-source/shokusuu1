<?php
declare(strict_types=1);

namespace App\Infrastructure\Query;

use App\Application\Query\DashboardQueryInterface;
use Cake\ORM\TableRegistry;

/**
 * DashboardQueryInterface の CakePHP 実装（m_user_info・t_individual_reservation_info）。
 */
final class CakeDashboardQuery implements DashboardQueryInterface
{
    public function adminLevel(int $userId): ?int
    {
        $user = TableRegistry::getTableLocator()->get('MUserInfo')->find()
            ->select(['i_admin'])
            ->where(['i_id_user' => $userId])
            ->first();

        return $user?->i_admin === null ? null : (int)$user->i_admin;
    }

    public function hasReservationOn(int $userId, string $date): bool
    {
        return TableRegistry::getTableLocator()->get('TIndividualReservationInfo')->exists([
            'i_id_user'          => $userId,
            'd_reservation_date' => $date,
        ]);
    }
}
