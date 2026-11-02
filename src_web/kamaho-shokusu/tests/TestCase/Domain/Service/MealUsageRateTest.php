<?php
declare(strict_types=1);

namespace App\Test\TestCase\Domain\Service;

use App\Domain\Service\MealUsageRate;
use PHPUnit\Framework\TestCase;

/**
 * システムレポートの使用率（MealUsageRate）。
 */
class MealUsageRateTest extends TestCase
{
    public function testPercent(): void
    {
        $this->assertSame(12.5, MealUsageRate::percent(1, 1, 2));    // 1 / (1×2×4)
        $this->assertSame(6.3, MealUsageRate::percent(1, 2, 2));     // 1 / 16 = 6.25 → 6.3
        $this->assertSame(100.0, MealUsageRate::percent(8, 1, 2));
        $this->assertSame(0.0, MealUsageRate::percent(0, 3, 30));
        $this->assertSame(0.0, MealUsageRate::percent(5, 0, 30), '利用者がいなければ 0（0 除算しない）');
        $this->assertSame(0.0, MealUsageRate::percent(5, 3, 0), '日数が 0 でも 0');
    }
}
