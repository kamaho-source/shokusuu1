<?php
declare(strict_types=1);

namespace App\Application\UseCase\CreateMealPrice;

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
     * @throws InvalidInputException 入力が不正・同じ年がある・保存できない
     */
    public function execute(CreateMealPriceInput $input): CreateMealPriceOutput
    {
        $actor = $input->actor;
        $price = MealPriceInput::toPrice(null, $input->values);
        if ($this->prices->yearExists((int)$price->year)) {
            throw new InvalidInputException(MealPriceRule::duplicateYearMessage((int)$price->year), 400);
        }
        $id = $this->prices->add($price, $actor->name);
        if ($id === null) {
            throw new InvalidInputException('食事料金情報を保存できませんでした。もう一度お試しください。', 400);
        }

        $this->auditLog->record('master', 'meal_price_create', $actor, 'm_meal_price_info', (string)$id, null, true);

        return new CreateMealPriceOutput($id);
    }
}
