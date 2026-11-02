<?php
declare(strict_types=1);

namespace App\Application\UseCase\DeleteMealPrice;

use App\Application\Port\AuditActor;
use App\Application\Port\AuditLogInterface;
use App\Domain\Exception\NotFoundException;
use App\Domain\Repository\MealPriceRepositoryInterface;

/**
 * 単価を削除する（管理者）。消せなかったときも監査ログに残す。
 */
final class DeleteMealPriceUseCase
{
    public function __construct(
        private readonly MealPriceRepositoryInterface $prices,
        private readonly AuditLogInterface $auditLog,
    ) {}

    /**
     * @return bool 消せたか
     * @throws NotFoundException 単価が無い
     */
    public function execute(int $id, AuditActor $actor): bool
    {
        if ($this->prices->find($id) === null) {
            throw new NotFoundException('食事料金情報が見つかりません。');
        }
        $deleted = $this->prices->delete($id);
        $this->auditLog->record(
            'master',
            'meal_price_delete',
            $actor,
            'm_meal_price_info',
            (string)$id,
            null,
            $deleted,
            $deleted ? null : '単価マスタの削除に失敗しました（対象が存在しない、またはDBエラー）',
        );

        return $deleted;
    }
}
