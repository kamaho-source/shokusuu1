<?php
declare(strict_types=1);

namespace App\Application\UseCase\SaveActualMeal;

use App\Domain\ValueObject\ApprovalKey;

/**
 * 実食の保存の Input DTO（どの予約を、食べたか、画面が持っている版数、操作者）。
 */
final class SaveActualMealInput
{
    public function __construct(
        public readonly ApprovalKey $key,
        public readonly bool $checked,
        public readonly int $expectedVersion,
        public readonly string $actorName,
        public readonly string $actorLoginId = '',
    ) {}
}
