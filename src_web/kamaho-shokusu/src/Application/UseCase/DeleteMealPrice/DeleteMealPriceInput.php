<?php
declare(strict_types=1);

namespace App\Application\UseCase\DeleteMealPrice;

use App\Application\Port\AuditActor;

/**
 * 単価の削除の入力。
 */
final class DeleteMealPriceInput
{
    public function __construct(
        public readonly int $id,
        public readonly AuditActor $actor,
    ) {}
}
