<?php
declare(strict_types=1);

namespace App\Policy;

use Authorization\IdentityInterface;

/**
 * システムレポート用認可ポリシー
 *
 * i_report_access === 1 のユーザーのみアクセスを許可する。
 */
final class SystemReportPolicy
{
    /**
     * @param \App\Controller\SystemReportController $resource
     */
    public function canIndex(?IdentityInterface $user, mixed $resource): bool
    {
        return $this->hasReportAccess($user);
    }

    /**
     * @param \App\Controller\SystemReportController $resource
     */
    public function canData(?IdentityInterface $user, mixed $resource): bool
    {
        return $this->hasReportAccess($user);
    }

    /**
     * @param \App\Controller\SystemReportController $resource
     */
    public function canDailyChildren(?IdentityInterface $user, mixed $resource): bool
    {
        return $this->hasReportAccess($user);
    }

    /**
     * @param \App\Controller\SystemReportController $resource
     */
    public function canDailyChildrenData(?IdentityInterface $user, mixed $resource): bool
    {
        return $this->hasReportAccess($user);
    }

    /**
     * @param \App\Controller\SystemReportController $resource
     */
    public function canLoginReport(?IdentityInterface $user, mixed $resource): bool
    {
        return $this->hasReportAccess($user);
    }

    /**
     * @param \App\Controller\SystemReportController $resource
     */
    public function canLoginReportData(?IdentityInterface $user, mixed $resource): bool
    {
        return $this->hasReportAccess($user);
    }

    private function hasReportAccess(?IdentityInterface $user): bool
    {
        if ($user === null) {
            return false;
        }
        $identity = $user->getOriginalData();
        if ($identity === null) {
            return false;
        }

        if (is_object($identity) && method_exists($identity, 'get')) {
            return (int)$identity->get('i_report_access') === 1;
        }
        if (is_array($identity)) {
            return (int)($identity['i_report_access'] ?? 0) === 1;
        }
        if ($identity instanceof \ArrayAccess) {
            return (int)($identity['i_report_access'] ?? 0) === 1;
        }
        return false;
    }
}
