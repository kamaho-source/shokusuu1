<?php
declare(strict_types=1);

namespace App\Application\UseCase\DeleteNotice;

use App\Application\Port\AuditActor;

/**
 * お知らせの削除の入力。
 */
final class DeleteNoticeInput
{
    public function __construct(
        public readonly int $id,
        public readonly AuditActor $actor,
    ) {}
}
