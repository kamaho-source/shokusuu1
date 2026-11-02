<?php
declare(strict_types=1);

namespace App\Test\TestCase\Domain\Service;

use App\Domain\Service\HistoryPeriodRule;
use PHPUnit\Framework\TestCase;

/**
 * 予約履歴の期間の決まり（HistoryPeriodRule）。旧 ReservationHistoryServiceTest の期間の部分を引き継ぐ。
 */
class HistoryPeriodRuleTest extends TestCase
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
        $this->assertSame($expected, HistoryPeriodRule::error($from, $to));
    }

    public function testDates_allDaysInclusive(): void
    {
        $this->assertSame(['2024-02-27', '2024-02-28', '2024-02-29', '2024-03-01'], HistoryPeriodRule::dates('2024-02-27', '2024-03-01'));
        $this->assertSame(['2024-09-05'], HistoryPeriodRule::dates('2024-09-05', '2024-09-05'));
        $this->assertCount(31, HistoryPeriodRule::dates('2026-10-01', '2026-10-31'));
    }
}
