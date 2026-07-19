<?php
declare(strict_types=1);

namespace App\Application\Tenant;

/**
 * Request-scoped static holder for the current TenantContext.
 *
 * Set once per request by TenantResolutionMiddleware.
 * Read by MUserInfoTable::findForAuthentication() and any other
 * component that needs the tenant context without direct request access.
 *
 * Safe for PHP's single-threaded-per-request model.
 */
final class TenantContextHolder
{
    private static ?TenantContext $context = null;

    public static function set(TenantContext $context): void
    {
        self::$context = $context;
    }

    public static function get(): ?TenantContext
    {
        return self::$context;
    }

    public static function clear(): void
    {
        self::$context = null;
    }
}
