<?php
declare(strict_types=1);

namespace App\Infrastructure\Storage;

use App\Application\Tenant\TenantContext;

/**
 * テナント・施設単位でファイルパスを生成・検証するサービス。
 *
 * 保存ディレクトリ構造:
 *   files/tenants/{tenant_id}/facilities/{facility_id}/exports/
 *   files/tenants/{tenant_id}/facilities/{facility_id}/imports/
 *
 * ダウンロード時は validateFileAccess() でパスのテナント帰属を確認し、
 * 異なるテナントのファイルへのアクセスを防止する。
 */
final readonly class TenantAwareFilePathService
{
    private const BASE_PATH = 'files';

    /**
     * エクスポートファイルの保存ディレクトリを返す。
     */
    public function exportsDir(TenantContext $ctx): string
    {
        return $this->facilityDir($ctx) . '/exports';
    }

    /**
     * インポートファイルの保存ディレクトリを返す。
     */
    public function importsDir(TenantContext $ctx): string
    {
        return $this->facilityDir($ctx) . '/imports';
    }

    /**
     * ファイルパスがコンテキストのテナントに帰属するか検証する。
     *
     * ダウンロード・削除リクエスト時にこのメソッドで越境アクセスを防止する。
     * テナントIDが一致しないパスへのアクセスは false を返す。
     */
    public function validateFileAccess(string $filePath, TenantContext $ctx): bool
    {
        $expected = self::BASE_PATH . '/tenants/' . $ctx->tenantId() . '/';
        return str_starts_with(
            str_replace('\\', '/', $filePath),
            $expected
        );
    }

    /**
     * 施設単位のルートディレクトリを返す。
     */
    private function facilityDir(TenantContext $ctx): string
    {
        $facilityId = $ctx->facilityId() ?? 0;
        return implode('/', [
            self::BASE_PATH,
            'tenants',
            $ctx->tenantId(),
            'facilities',
            $facilityId,
        ]);
    }
}
