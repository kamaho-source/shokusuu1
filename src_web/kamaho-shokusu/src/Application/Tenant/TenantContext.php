<?php
declare(strict_types=1);

namespace App\Application\Tenant;

/**
 * Holds the resolved tenant and facility context for the current request.
 *
 * Set by TenantResolutionMiddleware; consumed by Services and Policies
 * to scope queries and authorization checks to the correct tenant/facility.
 *
 * facilityId is null in Phase 1 and will be populated in Phase 2
 * once m_user_info carries facility_id.
 */
final readonly class TenantContext
{
    public function __construct(
        private int $tenantId,
        private string $tenantCode,
        private string $tenantStatus,
        private ?int $facilityId = null,
    ) {}

    public function tenantId(): int
    {
        return $this->tenantId;
    }

    public function tenantCode(): string
    {
        return $this->tenantCode;
    }

    public function tenantStatus(): string
    {
        return $this->tenantStatus;
    }

    public function facilityId(): ?int
    {
        return $this->facilityId;
    }

    /**
     * Returns a new instance with the facility ID set.
     * Used in Phase 2 when auth resolves the user's facility.
     */
    public function withFacilityId(int $facilityId): self
    {
        return new self(
            tenantId: $this->tenantId,
            tenantCode: $this->tenantCode,
            tenantStatus: $this->tenantStatus,
            facilityId: $facilityId,
        );
    }
}
