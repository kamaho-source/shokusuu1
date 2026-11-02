<?php
declare(strict_types=1);

namespace App\Application\UseCase\CreateMealPrice;

use App\Application\Port\AuditActor;

/**
 * 単価の登録の入力。
 */
final class CreateMealPriceInput
{
    /**
     * @param array<string, string> $values 'year'・'morning'・'lunch'・'dinner'・'bento' => 入力
     */
    public function __construct(
        public readonly array $values,
        public readonly AuditActor $actor,
    ) {}
}
