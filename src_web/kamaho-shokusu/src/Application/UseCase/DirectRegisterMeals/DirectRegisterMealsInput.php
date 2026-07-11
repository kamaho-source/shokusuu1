<?php
declare(strict_types=1);

namespace App\Application\UseCase\DirectRegisterMeals;

/**
 * カレンダークリック直接登録のInput DTO。
 *
 * @throws \InvalidArgumentException meals が空、または1-3以外の値を含む場合
 */
final class DirectRegisterMealsInput
{
    /** @param list<int> $mealIndices 登録する食事区分（1=朝 2=昼 3=夕）の配列 */
    public function __construct(
        public readonly string $date,
        public readonly int    $roomId,
        public readonly int    $loginUserId,
        public readonly string $loginUserName,
        public readonly int    $targetUserId,
        public readonly array  $mealIndices,
    ) {
        if (empty($mealIndices)) {
            throw new \InvalidArgumentException('mealIndices must not be empty.');
        }
        foreach ($mealIndices as $idx) {
            if (!in_array($idx, [1, 2, 3, 4], true)) {
                throw new \InvalidArgumentException("Invalid meal index: {$idx}. Must be 1, 2, 3, or 4.");
            }
        }
    }
}
