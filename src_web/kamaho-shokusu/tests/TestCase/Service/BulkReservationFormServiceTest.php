<?php
declare(strict_types=1);

namespace App\Test\TestCase\Service;

use App\Service\BulkReservationFormService;
use App\Service\RoomAccessService;
use Cake\TestSuite\TestCase;

/**
 * BulkReservationFormService のテスト
 *
 * 週データ構築ロジックを検証する。DB 不要。
 */
class BulkReservationFormServiceTest extends TestCase
{
    private BulkReservationFormService $service;

    public function setUp(): void
    {
        parent::setUp();
        $mockRoom = $this->getMockBuilder(RoomAccessService::class)->getMock();
        $this->service = new BulkReservationFormService($mockRoom);
    }

    // ---------------------------------------------------------------------------
    // buildBulkAddData() 構造チェック
    // ---------------------------------------------------------------------------

    public function testBuildBulkAddDataReturnsRequiredKeys(): void
    {
        $result = $this->service->buildBulkAddData('2026-06-08', null);

        $this->assertArrayHasKey('baseWeek', $result);
        $this->assertArrayHasKey('weekStarts', $result);
        $this->assertArrayHasKey('activeWeekDate', $result);
        $this->assertArrayHasKey('startOfWeek', $result);
        $this->assertArrayHasKey('dayOfWeekList', $result);
        $this->assertArrayHasKey('days', $result);
    }

    public function testBuildBulkAddDataStartOfWeekIsMonday(): void
    {
        $result = $this->service->buildBulkAddData('2026-06-10', null); // 水曜日

        /** @var \DateTimeImmutable $startOfWeek */
        $startOfWeek = $result['startOfWeek'];
        $this->assertSame('1', $startOfWeek->format('N'), '週の開始日は月曜日でなければならない');
    }

    public function testBuildBulkAddDataDaysOnlyContainFutureDates(): void
    {
        // 十分未来の日付を使うことで、週全体が有効になる
        $result = $this->service->buildBulkAddData('2026-09-07', null);

        foreach ($result['days'] as $day) {
            $this->assertFalse($day['is_disabled'], '通常予約追加の日付は全て有効であるべき: ' . $day['date']);
        }
    }

    public function testBuildBulkAddDataWeekStartsHasFourElements(): void
    {
        $result = $this->service->buildBulkAddData('2026-06-08', null);

        $this->assertCount(4, $result['weekStarts']);
    }

    public function testBuildBulkAddDataDayOfWeekListHasSevenElements(): void
    {
        $result = $this->service->buildBulkAddData('2026-06-08', null);

        $this->assertCount(7, $result['dayOfWeekList']);
        $this->assertSame('月', $result['dayOfWeekList'][0]);
        $this->assertSame('日', $result['dayOfWeekList'][6]);
    }

    // ---------------------------------------------------------------------------
    // buildBulkChangeEditData() 構造チェック
    // ---------------------------------------------------------------------------

    public function testBuildBulkChangeEditDataReturnsRequiredKeys(): void
    {
        $result = $this->service->buildBulkChangeEditData('2026-06-08', null);

        $this->assertArrayHasKey('baseWeek', $result);
        $this->assertArrayHasKey('weekStarts', $result);
        $this->assertArrayHasKey('days', $result);
    }

    public function testBuildBulkChangeEditDataStartOfWeekIsMonday(): void
    {
        $result = $this->service->buildBulkChangeEditData('2026-06-12', null); // 金曜日

        /** @var \DateTimeImmutable $startOfWeek */
        $startOfWeek = $result['startOfWeek'];
        $this->assertSame('1', $startOfWeek->format('N'), '週の開始日は月曜日でなければならない');
    }

    public function testBuildBulkChangeEditDataHasSevenDays(): void
    {
        $result = $this->service->buildBulkChangeEditData('2026-06-08', null);

        // 変更編集モードは7日分返る
        $this->assertCount(7, $result['days']);
    }

    // ---------------------------------------------------------------------------
    // baseWeekParam が正しく週ナビに反映される
    // ---------------------------------------------------------------------------

    public function testBaseWeekParamChangesBaseWeek(): void
    {
        $resultDefault = $this->service->buildBulkAddData('2026-06-08', null);
        $resultWithParam = $this->service->buildBulkAddData('2026-06-08', '2026-07-06');

        /** @var \DateTimeImmutable $baseDefault */
        $baseDefault = $resultDefault['baseWeek'];
        /** @var \DateTimeImmutable $baseWithParam */
        $baseWithParam = $resultWithParam['baseWeek'];

        $this->assertNotSame($baseDefault->format('Y-m-d'), $baseWithParam->format('Y-m-d'));
    }

    // ---------------------------------------------------------------------------
    // 無効な日付文字列でも例外が発生しない
    // ---------------------------------------------------------------------------

    public function testBuildBulkAddDataWithInvalidDateFallsBackGracefully(): void
    {
        // 例外が投げられないことを確認
        $result = $this->service->buildBulkAddData('invalid-date', null);

        $this->assertArrayHasKey('days', $result);
    }
}
