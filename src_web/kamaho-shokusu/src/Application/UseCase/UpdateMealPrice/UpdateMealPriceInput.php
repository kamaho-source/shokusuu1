<?php
declare(strict_types=1);

namespace App\Application\UseCase\UpdateMealPrice;

use App\Application\Port\AuditActor;

/**
 * 単価の更新の入力。
 */
final class UpdateMealPriceInput
{
    /**
     * @param array<string, string> $values 'year'・'morning'・'lunch'・'dinner'・'bento' => 入力（送られなかった項目は今の値のまま）
     */
    public function __construct(
        public readonly int $id,
        public readonly array $values,
        public readonly AuditActor $actor,
    ) {}
}
