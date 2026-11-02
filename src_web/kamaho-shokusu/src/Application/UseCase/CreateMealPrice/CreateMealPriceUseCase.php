<?php
declare(strict_types=1);

namespace App\Application\UseCase\CreateMealPrice;

use App\Application\Port\AuditActor;
use App\Application\Port\AuditLogInterface;
use App\Domain\Entity\MealPrice;
use App\Domain\Exception\InvalidInputException;
use App\Domain\Repository\MealPriceRepositoryInterface;
use App\Domain\Service\MealPriceRule;

/**
 * 単価を登録する（管理者）。同じ年の単価は1行だけ。
 */
final class CreateMealPriceUseCase
{
    public function __construct(
        private readonly MealPriceRepositoryInterface $prices,
        private readonly AuditLogInterface $auditLog,
    ) {}

    /**
     * @param array<string, string> $values 'year'・'morning'・'lunch'・'dinner'・'bento' => 入力
     * @return int 登録した単価の ID
     * @throws InvalidInputException 入力が不正・同じ年がある・保存できない
     */
    public function execute(array $values, AuditActor $actor): int
    {
        $price = MealPriceInput::toPrice(null, $values);
        if ($this->prices->yearExists((int)$price->year)) {
            throw new InvalidInputException(MealPriceRule::duplicateYearMessage((int)$price->year), 400);
        }
        $id = $this->prices->add($price, $actor->name);
        if ($id === null) {
            throw new InvalidInputException('食事料金情報を保存できませんでした。もう一度お試しください。', 400);
        }

        $this->auditLog->record('master', 'meal_price_create', $actor, 'm_meal_price_info', (string)$id, null, true);

        return $id;
    }
}
