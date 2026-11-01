<?php
declare(strict_types=1);

namespace App\Test\TestCase\Domain\ValueObject;

use App\Domain\ValueObject\MealType;
use PHPUnit\Framework\TestCase;
use ValueError;

/**
 * 食事区分と、昼食⇔弁当の組み合わせルールのテスト。
 */
class MealTypeTest extends TestCase
{
    public function testOnlyOneToFourAreMealTypes(): void
    {
        $this->assertSame(MealType::Bento, MealType::from(4));
        $this->assertNull(MealType::tryFrom(0));
        $this->assertNull(MealType::tryFrom(5));

        $this->expectException(ValueError::class);
        MealType::from(5);
    }

    public function testExclusiveCounterpart_onlyLunchAndBento(): void
    {
        $this->assertSame(MealType::Bento, MealType::Lunch->exclusiveCounterpart());
        $this->assertSame(MealType::Lunch, MealType::Bento->exclusiveCounterpart());
        $this->assertNull(MealType::Breakfast->exclusiveCounterpart());
        $this->assertNull(MealType::Dinner->exclusiveCounterpart());
    }

    public function testValueHelpers_treatInvalidValuesAsNoCounterpart(): void
    {
        $this->assertSame(4, MealType::counterpartValue(2));
        $this->assertSame(2, MealType::counterpartValue(4));
        $this->assertNull(MealType::counterpartValue(1));
        $this->assertNull(MealType::counterpartValue(9));

        foreach ([0, 1, 2, 3, 4, 5] as $value) {
            $this->assertSame(in_array($value, MealType::EXCLUSIVE_VALUES, true), MealType::isExclusiveValue($value), (string)$value);
        }
    }

    public function testLabels(): void
    {
        $this->assertSame(['朝食', '昼食', '夕食', '弁当'], array_map(static fn(MealType $t): string => $t->label(), MealType::cases()));
        $this->assertSame('', MealType::labelOf(9));
    }
}
