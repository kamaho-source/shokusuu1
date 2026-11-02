<?php
declare(strict_types=1);

namespace App\Application\UseCase\CreateMealPrice;

use App\Domain\Entity\MealPrice;
use App\Domain\Exception\InvalidInputException;
use App\Domain\Service\MealPriceRule;

/**
 * 単価の入力（文字列）を確かめて MealPrice にする（登録・更新で共通）。
 */
final class MealPriceInput
{
    /**
     * @param array<string, string> $values 'year'・'morning'・'lunch'・'dinner'・'bento' => 入力
     * @throws InvalidInputException 入力が不正
     */
    public static function toPrice(?int $id, array $values): MealPrice
    {
        $error = MealPriceRule::error($values);
        if ($error !== null) {
            throw new InvalidInputException($error, 400);
        }

        return new MealPrice(
            $id,
            (int)$values['year'],
            (int)$values['morning'],
            (int)$values['lunch'],
            (int)$values['dinner'],
            (int)$values['bento'],
        );
    }

    private function __construct() {}
}
