<?php
declare(strict_types=1);

namespace App\Test\TestCase\Domain\Service;

use App\Domain\Service\DatePeriodRule;
use PHPUnit\Framework\TestCase;

/**
 * 期間の決まり（DatePeriodRule）。旧 ReservationHistoryServiceTest の期間の部分を引き継ぐ。
 */
class DatePeriodRuleTest extends TestCase
{
    /**
     * @return array<string, array{string, string, ?string}>
     */
    public static function periodProvider(): array
    {
        return [
            '過去日' => ['2026-10-01', '2026-10-29', null],
            '1日だけ' => ['2026-10-29', '2026-10-29', null],
            '1年を超える期間' => ['2025-01-01', '2026-10-29', null],
            '開始が終了より後' => ['2026-10-20', '2026-10-10', '開始日が終了日より後になっています。'],
            '今日を含む' => ['2026-10-01', '2026-10-30', null],
            '未来日' => ['2030-11-01', '2030-12-31', null],
            '日付でない' => ['abc', '2026-10-29', '日付の形式が正しくありません。'],
            '終了が日付でない' => ['2026-10-01', '2026-1-29', '日付の形式が正しくありません。'],
            '存在しない日付' => ['2026-02-30', '2026-10-29', '日付の形式が正しくありません。'],
        ];
    }

    /**
     * @dataProvider periodProvider
     */
    public function testError(string $from, string $to, ?string $expected): void
    {
        $this->assertSame($expected, DatePeriodRule::error($from, $to));
    }

    public function testDates_allDaysInclusive(): void
    {
        $this->assertSame(['2024-02-27', '2024-02-28', '2024-02-29', '2024-03-01'], DatePeriodRule::dates('2024-02-27', '2024-03-01'));
        $this->assertSame(['2024-09-05'], DatePeriodRule::dates('2024-09-05', '2024-09-05'));
        $this->assertCount(31, DatePeriodRule::dates('2026-10-01', '2026-10-31'));
    }
}
