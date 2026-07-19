<?php
declare(strict_types=1);

namespace App\Middleware;

use App\Application\Tenant\TenantContext;
use App\Application\Tenant\TenantContextHolder;
use App\Model\Table\TenantsTable;
use Cake\Http\Response;
use Cake\ORM\Locator\LocatorAwareTrait;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Resolves the current tenant from the request hostname (subdomain).
 *
 * Subdomain pattern: {tenant_code}.example.jp
 * Local bypass: plain "localhost" or IP addresses pass through without tenant resolution.
 *
 * On success, sets the following request attributes:
 *   - tenant         : Tenant entity
 *   - tenantId       : int
 *   - tenantContext  : TenantContext (facilityId is null until Phase 2)
 *
 * Returns 404 for unknown tenants, 403 for suspended/terminated tenants.
 */
class TenantResolutionMiddleware implements MiddlewareInterface
{
    use LocatorAwareTrait;

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $host = $request->getUri()->getHost();
        $tenantCode = $this->extractTenantCode($host);

        if ($tenantCode === null) {
            return $handler->handle($request);
        }

        /** @var TenantsTable $tenantsTable */
        $tenantsTable = $this->fetchTable('Tenants');
        $tenant = $tenantsTable->find()
            ->where(['tenant_code' => $tenantCode])
            ->first();

        if ($tenant === null) {
            return (new Response())->withStatus(404);
        }

        if (in_array($tenant->status, ['suspended', 'terminated'], true)) {
            return (new Response())->withStatus(403);
        }

        $context = new TenantContext(
            tenantId: $tenant->id,
            tenantCode: $tenant->tenant_code,
            tenantStatus: $tenant->status,
        );

        TenantContextHolder::set($context);

        $request = $request
            ->withAttribute('tenant', $tenant)
            ->withAttribute('tenantId', $tenant->id)
            ->withAttribute('tenantContext', $context);

        return $handler->handle($request);
    }

    /**
     * Extracts the tenant code from the hostname.
     *
     * - IP アドレス: null を返してテナント解決をスキップ（認証不要ページ等）
     * - localhost: DEV_TENANT_CODE 環境変数で指定されたテナントを使用（既定: 'default'）
     *   → ローカル開発でサブドメインなしにテナント機能を確認できる
     * - {code}.localhost: ローカル開発・E2Eテスト用に第1セグメントをテナントコードとして使用
     * - サブドメイン形式 (xxx.example.jp): 第1セグメントをテナントコードとして使用
     */
    private function extractTenantCode(string $host): ?string
    {
        // ポート番号を除去（例: ayase.localhost:8091 → ayase.localhost）
        $hostWithoutPort = explode(':', $host)[0];

        if (filter_var($hostWithoutPort, FILTER_VALIDATE_IP) !== false) {
            return null;
        }

        if ($hostWithoutPort === 'localhost') {
            return env('DEV_TENANT_CODE', 'default');
        }

        $parts = explode('.', $hostWithoutPort);

        // {code}.localhost 形式のローカル開発・E2Eテスト用サポート
        if (count($parts) === 2 && $parts[1] === 'localhost') {
            return $parts[0];
        }

        if (count($parts) < 3) {
            return null;
        }

        return $parts[0];
    }
}
