<?php
declare(strict_types=1);

namespace App\Application\UseCase\RestoreUser;

use App\Application\Port\AuditActor;

/**
 * 削除済みの利用者を復元する依頼。
 */
final class RestoreUserInput
{
    public function __construct(
        public readonly int $userId,
        public readonly AuditActor $actor,
    ) {}
}
