<?php
declare(strict_types=1);

namespace App\Policy;

use App\Domain\ValueObject\UserRole;
use Authorization\IdentityInterface;

/**
 * システムレポート用認可ポリシー
 *
 * i_report_access === 1 のユーザーのみアクセスを許可する。
 */
class SystemReportPolicy
{
    public function canIndex(?IdentityInterface $user, \App\Controller\SystemReportController $resource): bool
    {
        return $this->hasReportAccess($user);
    }

    public function canData(?IdentityInterface $user, \App\Controller\SystemReportController $resource): bool
    {
        return $this->hasReportAccess($user);
    }

    public function canDailyChildren(?IdentityInterface $user, \App\Controller\SystemReportController $resource): bool
    {
        return $this->hasReportAccess($user);
    }

    public function canDailyChildrenData(?IdentityInterface $user, \App\Controller\SystemReportController $resource): bool
    {
        return $this->hasReportAccess($user);
    }

    public function canLoginReport(?IdentityInterface $user, \App\Controller\SystemReportController $resource): bool
    {
        return $this->hasReportAccess($user);
    }

    public function canLoginReportData(?IdentityInterface $user, \App\Controller\SystemReportController $resource): bool
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
