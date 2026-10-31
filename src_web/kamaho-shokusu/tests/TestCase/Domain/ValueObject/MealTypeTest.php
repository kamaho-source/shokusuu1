<?php
declare(strict_types=1);

namespace App\Test\TestCase\Domain\ValueObject;

use App\Domain\ValueObject\MealType;
use PHPUnit\Framework\TestCase;

/**
 * 食事区分と、昼食⇔弁当の組み合わせルールのテスト。
 */
class MealTypeTest extends TestCase
{
    public function testExclusiveCounterpart_onlyLunchAndBento(): void
    {
        $this->assertSame(MealType::BENTO, MealType::exclusiveCounterpart(MealType::LUNCH));
        $this->assertSame(MealType::LUNCH, MealType::exclusiveCounterpart(MealType::BENTO));
        $this->assertNull(MealType::exclusiveCounterpart(MealType::BREAKFAST));
        $this->assertNull(MealType::exclusiveCounterpart(MealType::DINNER));
        $this->assertNull(MealType::exclusiveCounterpart(0));
    }

    public function testIsExclusiveMatchesExclusiveList(): void
    {
        foreach ([1, 2, 3, 4, 5] as $type) {
            $this->assertSame(in_array($type, MealType::EXCLUSIVE, true), MealType::isExclusive($type), (string)$type);
        }
    }

    public function testName(): void
    {
        $this->assertSame(['朝食', '昼食', '夕食', '弁当', ''], array_map([MealType::class, 'name'], [1, 2, 3, 4, 9]));
    }
}
