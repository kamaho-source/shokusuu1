<?php
declare(strict_types=1);

namespace App\Application\UseCase\UpdateMealPrice;

use App\Application\Port\AuditActor;
use App\Application\Port\AuditLogInterface;
use App\Application\UseCase\CreateMealPrice\MealPriceInput;
use App\Domain\Exception\InvalidInputException;
use App\Domain\Exception\NotFoundException;
use App\Domain\Repository\MealPriceRepositoryInterface;
use App\Domain\Service\MealPriceRule;

/**
 * 単価を更新する（管理者）。別の行と同じ年にはできない。
 */
final class UpdateMealPriceUseCase
{
    public function __construct(
        private readonly MealPriceRepositoryInterface $prices,
        private readonly AuditLogInterface $auditLog,
    ) {}

    /**
     * @param array<string, string> $values 'year'・'morning'・'lunch'・'dinner'・'bento' => 入力（送られなかった項目は今の値のまま）
     * @throws NotFoundException 単価が無い
     * @throws InvalidInputException 入力が不正・同じ年がある・保存できない
     */
    public function execute(int $id, array $values, AuditActor $actor): void
    {
        $current = $this->prices->find($id) ?? throw new NotFoundException('食事料金情報が見つかりません。');
        $values += [
            'year'    => (string)$current->year,
            'morning' => (string)$current->morning,
            'lunch'   => (string)$current->lunch,
            'dinner'  => (string)$current->dinner,
            'bento'   => (string)$current->bento,
        ];
        $price = MealPriceInput::toPrice($id, $values);
        if ($this->prices->yearExists((int)$price->year, $id)) {
            throw new InvalidInputException(MealPriceRule::duplicateYearMessage((int)$price->year), 400);
        }
        if (!$this->prices->update($price, $actor->name)) {
            throw new InvalidInputException('食事料金情報を更新できませんでした。もう一度お試しください。', 400);
        }

        $this->auditLog->record('master', 'meal_price_update', $actor, 'm_meal_price_info', (string)$id, null, true);
    }
}
