<?php
declare(strict_types=1);

namespace App\Test\TestCase\Service;

use App\Service\ReservationCopyService;
use Cake\I18n\Date;
use Cake\TestSuite\TestCase;

/**
 * ReservationCopyService テスト。
 *
 * DB に依存しない normalizeCopyParams() のバリデーションロジックを検証する。
 */
class ReservationCopyServiceTest extends TestCase
{
    protected array $fixtures = [
        'app.TIndividualReservationInfo',
        'app.MUserInfo',
    ];

    private ReservationCopyService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new ReservationCopyService();
    }

    // ----------------------------------------------------------------
    // mode バリデーション
    // ----------------------------------------------------------------

    public function testNormalizeReturnErrorForInvalidMode(): void
    {
        $result = $this->service->normalizeCopyParams([
            'mode' => 'daily',
            'source' => '2026-06-01',
            'target' => '2026-07-01',
        ]);

        $this->assertFalse($result['ok']);
        $this->assertSame(422, $result['status']);
    }

    public function testNormalizeAcceptsWeekMode(): void
    {
        $result = $this->service->normalizeCopyParams([
            'mode' => 'week',
            'source' => '2026-06-02',
            'target' => '2026-06-09',
        ]);

        $this->assertTrue($result['ok']);
        $this->assertSame('week', $result['mode']);
    }

    public function testNormalizeAcceptsMonthMode(): void
    {
        $result = $this->service->normalizeCopyParams([
            'mode' => 'month',
            'source' => '2026-06-01',
            'target' => '2026-07-01',
        ]);

        $this->assertTrue($result['ok']);
        $this->assertSame('month', $result['mode']);
    }

    // ----------------------------------------------------------------
    // source / target バリデーション
    // ----------------------------------------------------------------

    public function testNormalizeReturnErrorForMissingSource(): void
    {
        $result = $this->service->normalizeCopyParams([
            'mode' => 'week',
            'target' => '2026-06-09',
        ]);

        $this->assertFalse($result['ok']);
        $this->assertSame(422, $result['status']);
    }

    public function testNormalizeReturnErrorForMissingTarget(): void
    {
        $result = $this->service->normalizeCopyParams([
            'mode' => 'week',
            'source' => '2026-06-02',
        ]);

        $this->assertFalse($result['ok']);
        $this->assertSame(422, $result['status']);
    }

    public function testNormalizeReturnErrorForInvalidDateFormat(): void
    {
        $result = $this->service->normalizeCopyParams([
            'mode' => 'week',
            'source' => 'not-a-date',
            'target' => '2026-06-09',
        ]);

        $this->assertFalse($result['ok']);
        $this->assertSame(422, $result['status']);
    }

    // ----------------------------------------------------------------
    // week モード: 月曜日への正規化
    // ----------------------------------------------------------------

    public function testNormalizeWeekNormalizesToMonday(): void
    {
        // 2026-06-04（木曜） → 月曜（2026-06-01）に正規化される
        $result = $this->service->normalizeCopyParams([
            'mode' => 'week',
            'source' => '2026-06-04',
            'target' => '2026-06-11',
        ]);

        $this->assertTrue($result['ok']);
        $this->assertSame('2026-06-01', $result['src']->format('Y-m-d'));
        $this->assertSame('2026-06-08', $result['dst']->format('Y-m-d'));
    }

    public function testNormalizeWeekKeepsMondayAsIs(): void
    {
        // 2026-06-01（月曜）はそのまま
        $result = $this->service->normalizeCopyParams([
            'mode' => 'week',
            'source' => '2026-06-01',
            'target' => '2026-06-08',
        ]);

        $this->assertTrue($result['ok']);
        $this->assertSame('2026-06-01', $result['src']->format('Y-m-d'));
    }

    // ----------------------------------------------------------------
    // month モード: 月初への正規化
    // ----------------------------------------------------------------

    public function testNormalizeMonthNormalizesToFirstDayOfMonth(): void
    {
        // 2026-06-15 → 2026-06-01 に正規化される
        $result = $this->service->normalizeCopyParams([
            'mode' => 'month',
            'source' => '2026-06-15',
            'target' => '2026-07-20',
        ]);

        $this->assertTrue($result['ok']);
        $this->assertSame('2026-06-01', $result['src']->format('Y-m-d'));
        $this->assertSame('2026-07-01', $result['dst']->format('Y-m-d'));
    }

    // ----------------------------------------------------------------
    // room_id と only_children
    // ----------------------------------------------------------------

    public function testNormalizeRoomIdParsedCorrectly(): void
    {
        $result = $this->service->normalizeCopyParams([
            'mode' => 'week',
            'source' => '2026-06-01',
            'target' => '2026-06-08',
            'room_id' => '5',
        ]);

        $this->assertTrue($result['ok']);
        $this->assertSame(5, $result['roomId']);
    }

    public function testNormalizeRoomIdIsNullWhenZero(): void
    {
        $result = $this->service->normalizeCopyParams([
            'mode' => 'week',
            'source' => '2026-06-01',
            'target' => '2026-06-08',
            'room_id' => '0',
        ]);

        $this->assertTrue($result['ok']);
        $this->assertNull($result['roomId']);
    }

    public function testNormalizeOnlyChildrenDefaultsFalse(): void
    {
        $result = $this->service->normalizeCopyParams([
            'mode' => 'week',
            'source' => '2026-06-01',
            'target' => '2026-06-08',
        ]);

        $this->assertTrue($result['ok']);
        $this->assertFalse($result['onlyChildren']);
    }

    public function testNormalizeOnlyChildrenParsedAsTrue(): void
    {
        $result = $this->service->normalizeCopyParams([
            'mode' => 'week',
            'source' => '2026-06-01',
            'target' => '2026-06-08',
            'only_children' => '1',
        ]);

        $this->assertTrue($result['ok']);
        $this->assertTrue($result['onlyChildren']);
    }

    // ----------------------------------------------------------------
    // source_start / target_start エイリアス
    // ----------------------------------------------------------------

    public function testNormalizeAcceptsSourceStartAlias(): void
    {
        $result = $this->service->normalizeCopyParams([
            'mode' => 'week',
            'source_start' => '2026-06-01',
            'target_start' => '2026-06-08',
        ]);

        $this->assertTrue($result['ok']);
        $this->assertSame('2026-06-01', $result['src']->format('Y-m-d'));
    }

    // ----------------------------------------------------------------
    // 過去日ガード（コピー先が過去日になる場合はスキップされる）
    // ----------------------------------------------------------------

    public function testCopyWeekSkipsRowsWhenTargetIsPast(): void
    {
        // フィクスチャの唯一のレコード（2024-09-07, 土曜）を含む週（2024-09-02 月曜始まり）をコピー元、
        // その1週間後（実行時点で確実に過去日）をコピー先にする。
        $result = $this->service->copyWeek(
            new Date('2024-09-02'),
            new Date('2024-09-09'),
            null,
            false,
            null,
            false
        );

        $this->assertSame(1, $result['total']);
        $this->assertSame(0, $result['copied']);
        $this->assertSame(1, $result['invalid_date']);
    }

    public function testPreviewWeekSkipsRowsWhenTargetIsPast(): void
    {
        $result = $this->service->previewWeek(
            new Date('2024-09-02'),
            new Date('2024-09-09'),
            null,
            false
        );

        $this->assertSame(1, $result['total']);
        $this->assertSame(0, $result['will_copy']);
        $this->assertSame(1, $result['will_skip']);
    }
}
