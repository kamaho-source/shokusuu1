<?php
declare(strict_types=1);

namespace App\Application\UseCase\DeleteUser;

use App\Application\Port\AuditActor;

/**
 * 利用者を削除（論理削除）する依頼。
 */
final class DeleteUserInput
{
    public function __construct(
        public readonly int $userId,
        public readonly AuditActor $actor,
    ) {}
}
