<?php
declare(strict_types=1);

namespace App\Test\TestCase\Service;

use App\Service\ReservationCopyService;
use Cake\Datasource\ConnectionManager;
use Cake\I18n\Date;
use Cake\ORM\TableRegistry;
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

    // ----------------------------------------------------------------
    // プレビューと実行のスキップ判定一致（バグ5）
    // ----------------------------------------------------------------

    /** コピー元の月曜（未来日） */
    private const SRC_MONDAY = '2030-01-07';
    /** コピー先の月曜（コピー元の1週間後） */
    private const DST_MONDAY = '2030-01-14';

    private function insertRow(string $date, array $override = []): void
    {
        $defaults = [
            'i_id_user'          => 1,
            'd_reservation_date' => $date,
            'i_reservation_type' => 1,
            'i_id_room'          => 1,
            'eat_flag'           => 1,
            'i_change_flag'      => 1,
            'i_approval_status'  => 0,
            'i_version'          => 1,
            'dt_create'          => '2029-12-01 00:00:00',
            'c_create_user'      => 'test',
        ];
        ConnectionManager::get('test')->insert(
            't_individual_reservation_info',
            array_merge($defaults, $override)
        );
    }

    private function fetchRow(string $date): ?object
    {
        return TableRegistry::getTableLocator()->get('TIndividualReservationInfo')->find()
            ->where([
                'i_id_user'          => 1,
                'd_reservation_date' => $date,
                'i_reservation_type' => 1,
                'i_id_room'          => 1,
            ])
            ->first();
    }

    /**
     * バグ5: コピー先に eat_flag=0 の無効行がある場合、
     * プレビューは「スキップ」ではなく「コピー」と表示し、実行結果と一致する。
     */
    public function testPreviewMatchesCopyWhenExistingRowIsInactive(): void
    {
        $this->insertRow('2030-01-08');                      // コピー元
        $this->insertRow('2030-01-15', ['eat_flag' => 0, 'i_change_flag' => 0]); // コピー先（無効行）

        $preview = $this->service->previewWeek(new Date(self::SRC_MONDAY), new Date(self::DST_MONDAY), null, false);
        $this->assertSame(1, $preview['will_copy'], '上書きされる行がプレビューでスキップ扱いになっている');
        $this->assertSame(0, $preview['will_skip']);

        $copy = $this->service->copyWeek(new Date(self::SRC_MONDAY), new Date(self::DST_MONDAY), null, false, null, false);
        $this->assertSame($preview['will_copy'], $copy['copied'], 'プレビューと実行結果が一致していない');
        $this->assertSame($preview['will_skip'], $copy['skipped'], 'プレビューと実行結果が一致していない');
        $this->assertSame(1, (int)$this->fetchRow('2030-01-15')->eat_flag);
    }

    /**
     * バグ5: コピー先に有効行（eat_flag=1）がある場合はプレビュー・実行ともスキップ。
     */
    public function testPreviewMatchesCopyWhenExistingRowIsActive(): void
    {
        $this->insertRow('2030-01-08');
        $this->insertRow('2030-01-15', ['eat_flag' => 1]);

        $preview = $this->service->previewWeek(new Date(self::SRC_MONDAY), new Date(self::DST_MONDAY), null, false);
        $copy    = $this->service->copyWeek(new Date(self::SRC_MONDAY), new Date(self::DST_MONDAY), null, false, null, false);

        $this->assertSame(1, $preview['will_skip']);
        $this->assertSame($preview['will_copy'], $copy['copied']);
        $this->assertSame($preview['will_skip'], $copy['skipped']);
    }

    /**
     * バグ1+5: 承認済みのコピー先はプレビュー・実行ともスキップし、例外にしない。
     */
    public function testApprovedTargetRowIsSkippedByPreviewAndCopy(): void
    {
        $this->insertRow('2030-01-08');
        $this->insertRow('2030-01-15', ['eat_flag' => 0, 'i_change_flag' => 0, 'i_approval_status' => 2]);

        $preview = $this->service->previewWeek(new Date(self::SRC_MONDAY), new Date(self::DST_MONDAY), null, false);
        $copy    = $this->service->copyWeek(new Date(self::SRC_MONDAY), new Date(self::DST_MONDAY), null, false, null, false);

        $this->assertSame(1, $preview['will_skip'], '承認済みの行がプレビューでスキップされていない');
        $this->assertSame(0, $copy['copied']);
        $this->assertSame(1, $copy['skipped']);
        $this->assertSame(0, (int)$this->fetchRow('2030-01-15')->eat_flag, '承認済みの行が上書きされている');
    }
}
