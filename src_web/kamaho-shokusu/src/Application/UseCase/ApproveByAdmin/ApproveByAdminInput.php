<?php
declare(strict_types=1);

namespace App\Application\UseCase\ApproveByAdmin;

use App\Application\Port\AuditActor;

/**
 * 管理者の最終承認の Input DTO。承認者は $actor（id が承認者の利用者ID）。
 */
final class ApproveByAdminInput
{
    /**
     * @param array<array-key, array<array-key, int|string|null>|string|int|null> $keys 画面から受け取った対象のキー（形は UseCase で確かめる）
     */
    public function __construct(
        public readonly array $keys,
        public readonly AuditActor $actor,
    ) {}
}
