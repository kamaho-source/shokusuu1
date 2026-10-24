<?php
declare(strict_types=1);

namespace App\Policy;

use App\Domain\ValueObject\UserRole;
use Authorization\IdentityInterface;

/**
 * システムレポート用認可ポリシー
 *
 * 管理者（i_admin が 1 または 3）と、i_report_access === 1 のユーザーにアクセスを許可する。
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
        return UserRole::isAdmin($this->intField($user, 'i_admin'))
            || $this->intField($user, 'i_report_access') === 1;
    }

    private function intField(?IdentityInterface $user, string $key): int
    {
        if ($user === null) {
            return 0;
        }
        $identity = $user->getOriginalData();
        if (is_object($identity) && method_exists($identity, 'get')) {
            return (int)$identity->get($key);
        }
        if (is_array($identity) || $identity instanceof \ArrayAccess) {
            return (int)($identity[$key] ?? 0);
        }
        return 0;
    }
}
