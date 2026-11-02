<?php
declare(strict_types=1);

namespace App\Test\TestCase\Domain\Service;

use App\Domain\Service\MealPriceRule;
use PHPUnit\Framework\TestCase;

/**
 * 単価マスタの入力の決まり（MealPriceRule）。
 */
class MealPriceRuleTest extends TestCase
{
    /** @return array<string, string> */
    private static function values(string $morning = '500'): array
    {
        return ['year' => '2026', 'morning' => $morning, 'lunch' => '600', 'dinner' => '700', 'bento' => '0'];
    }

    public function testError(): void
    {
        $this->assertNull(MealPriceRule::error(self::values()));
        $this->assertNull(MealPriceRule::error(self::values('99999')), '上限ちょうどは登録できる');
        $this->assertNull(MealPriceRule::error(self::values('0099999')), '先頭の 0 は桁数に数えない');
        $this->assertSame('年度は 4 桁の数字で入力してください。', MealPriceRule::error(['year' => '26'] + self::values()));
        $this->assertSame('朝食の単価は 0 以上の整数（円）で入力してください。', MealPriceRule::error(self::values('-1')));
        $this->assertSame('朝食の単価は 99,999 円以下で入力してください。', MealPriceRule::error(self::values('100000')));
        $this->assertSame('朝食の単価は 99,999 円以下で入力してください。', MealPriceRule::error(self::values('99999999999999999999')), 'int の範囲を超える桁数でも 500 にしない');
        $this->assertSame(99999, MealPriceRule::MAX_PRICE);
        $this->assertNull(MealPriceRule::error(self::values('0000000')), '0 だけが並んでも 0 円');
        $this->assertSame('朝食の単価は 99,999 円以下で入力してください。', MealPriceRule::error(self::values('100099999')), '先頭以外の 0 は桁数に数える');
        $this->assertSame('弁当の単価は 99,999 円以下で入力してください。', MealPriceRule::error(['bento' => '100000'] + self::values()), '朝食以外の単価も上限を見る');
    }
}
