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
        'app.MRoomInfo',
        'app.MUserGroup',
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

    /**
     * コピー先の日に別の部屋で同じ食事が有効なら、プレビューもスキップに数え、実行結果と一致する。
     */
    public function testPreviewSkipsWhenSameMealEffectiveInAnotherRoom(): void
    {
        $this->insertRow('2030-01-08');                          // コピー元（部屋1）
        $this->insertRow('2030-01-15', ['i_id_room' => 2]);      // コピー先の日、別の部屋で有効

        $preview = $this->service->previewWeek(new Date(self::SRC_MONDAY), new Date(self::DST_MONDAY), null, false);
        $copy    = $this->service->copyWeek(new Date(self::SRC_MONDAY), new Date(self::DST_MONDAY), null, false, null, false);

        $this->assertSame(1, $preview['will_skip'], '別の部屋で有効な食事がプレビューでスキップに数えられていない');
        $this->assertSame(0, $preview['will_copy']);
        $this->assertSame($preview['will_copy'], $copy['copied']);
        $this->assertSame($preview['will_skip'], $copy['skipped']);
        $this->assertNull($this->fetchRow('2030-01-15'), '部屋1にもコピーされて二重予約になっている');
    }

    /** コピー先で昼食と弁当がぶつかる行はスキップし、ほかの行はコピーする（1件の衝突でコピー全体を止めない） */
    public function testLunchBentoConflictIsSkippedAndOtherRowsAreCopied(): void
    {
        $this->insertRow('2030-01-08', ['i_reservation_type' => 2]);                     // コピー元: 昼食
        $this->insertRow('2030-01-09');                                                  // コピー元: 朝食（関係ない日）
        $this->insertRow('2030-01-15', ['i_reservation_type' => 4, 'i_id_room' => 2]);   // コピー先: 弁当が有効

        $preview = $this->service->previewWeek(new Date(self::SRC_MONDAY), new Date(self::DST_MONDAY), null, false);
        $copy    = $this->service->copyWeek(new Date(self::SRC_MONDAY), new Date(self::DST_MONDAY), null, false, null, false);

        $this->assertSame(1, $preview['will_skip']);
        $this->assertSame(1, $preview['will_copy']);
        $this->assertSame(1, $copy['copied']);
        $this->assertSame(1, $copy['skipped']);
        $this->assertSame([], TableRegistry::getTableLocator()->get('TIndividualReservationInfo')->findEffectiveRoomIds(1, '2030-01-15', 2));
        $this->assertNotNull($this->fetchRow('2030-01-16'), '関係ない日の朝食がコピーされていない');
    }

    /** コピー元で取消済みの行は写さない（フラグをそのまま写すと、直前期間に入ってから有効に変わるため） */
    public function testCancelledSourceRowIsNotCopied(): void
    {
        $this->insertRow('2030-01-08', ['eat_flag' => 1, 'i_change_flag' => 0]); // 遠い日の行は eat_flag で判定 → 有効
        $this->insertRow('2030-01-09', ['eat_flag' => 0, 'i_change_flag' => 0]); // 取消済み

        $copy = $this->service->copyWeek(new Date(self::SRC_MONDAY), new Date(self::DST_MONDAY), null, false, null, false);

        $this->assertSame(1, $copy['copied']);
        $this->assertSame(1, $copy['skipped']);
        $copied = $this->fetchRow('2030-01-15');
        $this->assertSame([1, 1], [(int)$copied->eat_flag, (int)$copied->i_change_flag], 'コピー先は「食べる」でそろえる');
        $this->assertNull($this->fetchRow('2030-01-16'));
    }

    /** コピー先が直前期間なら、取消済みの既存行を上書きするときに当初予定（eat_flag）を残す */
    public function testCopyIntoLastMinuteKeepsOriginalPlanOfExistingRow(): void
    {
        $dst = Date::today()->addDays(2);
        $src = $dst->subWeeks(1);
        $dstMonday = $dst->startOfWeek();
        $srcMonday = $dstMonday->subWeeks(1);
        $this->insertRow($src->format('Y-m-d'), ['eat_flag' => 0, 'i_change_flag' => 1]);
        $this->insertRow($dst->format('Y-m-d'), ['eat_flag' => 1, 'i_change_flag' => 0]);

        $copy = $this->service->copyWeek($srcMonday, $dstMonday, null, false, null, false);

        $this->assertSame(1, $copy['copied']);
        $row = $this->fetchRow($dst->format('Y-m-d'));
        $this->assertSame([1, 1], [(int)$row->eat_flag, (int)$row->i_change_flag]);
    }

    // ----------------------------------------------------------------
    // 追加: resolveTargetRoomIds / 月コピー / 子供のみ / 競合
    // ----------------------------------------------------------------

    private function rowAt(int $userId, string $date, int $room = 1, int $type = 1): ?object
    {
        return TableRegistry::getTableLocator()->get('TIndividualReservationInfo')->find()
            ->where(['i_id_user' => $userId, 'd_reservation_date' => $date, 'i_reservation_type' => $type, 'i_id_room' => $room])
            ->first();
    }

    public function testResolveTargetRoomIds_withoutRequestReturnsAllAffiliatedRooms(): void
    {
        $this->assertSame([1], $this->service->resolveTargetRoomIds(null, 1));
    }

    public function testResolveTargetRoomIds_requestedAffiliatedRoom(): void
    {
        $this->assertSame([1], $this->service->resolveTargetRoomIds(1, 2));
    }

    public function testResolveTargetRoomIds_requestedRoomOutsideAffiliation_returnsNull(): void
    {
        $this->assertNull($this->service->resolveTargetRoomIds(99, 1));
    }

    public function testResolveTargetRoomIds_noAffiliation_returnsNull(): void
    {
        $this->assertNull($this->service->resolveTargetRoomIds(null, 999));
    }

    public function testNormalizeRoomIdBlankStringIsNull(): void
    {
        $result = $this->service->normalizeCopyParams(['mode' => 'week', 'source' => '2026-06-02', 'target' => '2026-06-09', 'room_id' => '']);

        $this->assertNull($result['roomId']);
    }

    public function testCopyMonth_copiesSameDayAndSkipsDaysMissingInTargetMonth(): void
    {
        $this->insertRow('2030-01-10');
        $this->insertRow('2030-01-31'); // 2月に31日は無い

        $preview = $this->service->previewMonth(new Date('2030-01-01'), new Date('2030-02-01'), null, false);
        $copy = $this->service->copyMonth(new Date('2030-01-01'), new Date('2030-02-01'), null, false, null, false);

        $this->assertSame(['total' => 2, 'will_copy' => 1, 'will_skip' => 0, 'invalid_date' => 1], $preview);
        $this->assertSame(2, $copy['total']);
        $this->assertSame(1, $copy['copied']);
        $this->assertSame(1, $copy['invalid_date']);
        $row = $this->fetchRow('2030-02-10');
        $this->assertNotNull($row);
        $this->assertSame([1, 1], [(int)$row->eat_flag, (int)$row->i_change_flag]);
        $this->assertSame('system', $row->c_create_user);
    }

    public function testCopyMonth_recordsActorName(): void
    {
        $this->insertRow('2030-01-10');
        $actor = new class {
            public function get(string $key): ?string
            {
                return $key === 'c_user_name' ? '操作者A' : null;
            }
        };

        $this->service->copyMonth(new Date('2030-01-01'), new Date('2030-02-01'), null, false, $actor, false);

        $this->assertSame('操作者A', $this->fetchRow('2030-02-10')->c_create_user);
    }

    public function testCopyMonth_toPastMonth_isInvalidDate(): void
    {
        $preview = $this->service->previewMonth(new Date('2024-09-01'), new Date('2024-10-01'), null, false);
        $copy = $this->service->copyMonth(new Date('2024-09-01'), new Date('2024-10-01'), null, false, null, false);

        $this->assertSame(1, $preview['invalid_date']);
        $this->assertSame(0, $copy['copied']);
        $this->assertSame(1, $copy['invalid_date']);
    }

    public function testCopyMonth_noSourceRows_returnsZeros(): void
    {
        $copy = $this->service->copyMonth(new Date('2031-05-01'), new Date('2031-06-01'), null, false, null, false);

        $this->assertSame(['total' => 0, 'copied' => 0, 'skipped' => 0, 'invalid_date' => 0], $copy);
    }

    public function testCopyMonth_existingRowsAreSkippedOrRevived(): void
    {
        $this->insertRow('2030-01-10');
        $this->insertRow('2030-01-11');
        $this->insertRow('2030-02-10', ['eat_flag' => 1, 'i_change_flag' => 1]); // 既に有効 → スキップ
        $this->insertRow('2030-02-11', ['eat_flag' => 0, 'i_change_flag' => 0]); // 無効 → 復活

        $preview = $this->service->previewMonth(new Date('2030-01-01'), new Date('2030-02-01'), null, false);
        $copy = $this->service->copyMonth(new Date('2030-01-01'), new Date('2030-02-01'), null, false, null, false);

        $this->assertSame(1, $preview['will_copy']);
        $this->assertSame(1, $preview['will_skip']);
        $this->assertSame(1, $copy['copied']);
        $this->assertSame(1, $copy['skipped']);
        $this->assertSame(1, (int)$this->fetchRow('2030-02-11')->i_change_flag);
    }

    public function testCopyWeekAndMonth_onlyChildrenCopiesChildRowsOnly(): void
    {
        $this->insertRow('2030-01-08', ['i_id_user' => 1]); // 職員/管理者
        $this->insertRow('2030-01-08', ['i_id_user' => 3]); // 児童(i_user_level=1)

        $previewW = $this->service->previewWeek(new Date(self::SRC_MONDAY), new Date(self::DST_MONDAY), null, true);
        $copyW = $this->service->copyWeek(new Date(self::SRC_MONDAY), new Date(self::DST_MONDAY), null, false, null, true);

        $this->assertSame(1, $previewW['total']);
        $this->assertSame(1, $previewW['will_copy']);
        $this->assertSame(1, $copyW['copied']);
        $this->assertNotNull($this->rowAt(3, '2030-01-15'));
        $this->assertNull($this->rowAt(1, '2030-01-15'));

        $this->insertRow('2030-01-10', ['i_id_user' => 3]);
        $this->insertRow('2030-01-10', ['i_id_user' => 1]);
        $previewM = $this->service->previewMonth(new Date('2030-01-01'), new Date('2030-02-01'), null, true);
        $copyM = $this->service->copyMonth(new Date('2030-01-01'), new Date('2030-02-01'), null, false, null, true);

        $this->assertSame(3, $previewM['total']); // 1/8・1/10・先に週コピーされた1/15 の児童分
        $this->assertSame(3, $copyM['copied']);
        $this->assertNull($this->rowAt(1, '2030-02-10'));
    }

    public function testOnlyChildren_withNoChildren_returnsZeros(): void
    {
        ConnectionManager::get('test')->update('m_user_info', ['i_user_level' => 0], ['i_user_level' => 1]);
        $this->insertRow('2030-01-08');

        $this->assertSame(['total' => 0, 'copied' => 0, 'skipped' => 0], $this->service->copyWeek(new Date(self::SRC_MONDAY), new Date(self::DST_MONDAY), null, false, null, true));
        $this->assertSame(['total' => 0, 'will_copy' => 0, 'will_skip' => 0], $this->service->previewWeek(new Date(self::SRC_MONDAY), new Date(self::DST_MONDAY), null, true));
        $this->assertSame(['total' => 0, 'copied' => 0, 'skipped' => 0, 'invalid_date' => 0], $this->service->copyMonth(new Date('2030-01-01'), new Date('2030-02-01'), null, false, null, true));
        $this->assertSame(['total' => 0, 'will_copy' => 0, 'will_skip' => 0, 'invalid_date' => 0], $this->service->previewMonth(new Date('2030-01-01'), new Date('2030-02-01'), null, true));
    }

    public function testCopyWeek_roomFilterLimitsSourceRows(): void
    {
        $this->insertRow('2030-01-08', ['i_id_room' => 1]);
        $this->insertRow('2030-01-09', ['i_id_room' => 2]);

        $copy = $this->service->copyWeek(new Date(self::SRC_MONDAY), new Date(self::DST_MONDAY), [1], false, null, false);
        $preview = $this->service->previewMonth(new Date('2030-01-01'), new Date('2030-02-01'), 2, false);

        $this->assertSame(1, $copy['total']);
        $this->assertNotNull($this->fetchRow('2030-01-15'));
        $this->assertNull($this->fetchRow('2030-01-16'));
        $this->assertSame(1, $preview['total']);
    }

    public function testCopyWeek_toEarlierWeek(): void
    {
        $this->insertRow('2030-01-15');

        $copy = $this->service->copyWeek(new Date(self::DST_MONDAY), new Date(self::SRC_MONDAY), null, false, null, false);

        $this->assertSame(1, $copy['copied']);
        $this->assertNotNull($this->fetchRow('2030-01-08'));
    }

    public function testCopyWeek_versionConflict_throwsAndRollsBack(): void
    {
        $this->insertRow('2030-01-08');
        $this->insertRow('2030-01-15', ['eat_flag' => 0, 'i_change_flag' => 0]);
        $mock = $this->getMockBuilder(\App\Model\Table\TIndividualReservationInfoTable::class)
            ->onlyMethods(['updateRowWithVersion'])
            ->setConstructorArgs([['connection' => ConnectionManager::get('test'), 'alias' => 'TIndividualReservationInfo', 'table' => 't_individual_reservation_info']])
            ->getMock();
        $mock->method('updateRowWithVersion')->willReturn(false);
        TableRegistry::getTableLocator()->set('TIndividualReservationInfo', $mock);
        $service = new ReservationCopyService();

        try {
            $service->copyWeek(new Date(self::SRC_MONDAY), new Date(self::DST_MONDAY), null, false, null, false);
            $this->fail('例外が投げられるはず');
        } catch (\RuntimeException $e) {
            $this->assertSame('optimistic_conflict', $e->getMessage());
        }
        $this->assertSame(0, (int)$this->fetchRow('2030-01-15')->eat_flag);

    }

    public function testCopyMonth_versionConflict_throws(): void
    {
        $this->insertRow('2030-01-10');
        $this->insertRow('2030-02-10', ['eat_flag' => 0, 'i_change_flag' => 0]);
        $mock = $this->getMockBuilder(\App\Model\Table\TIndividualReservationInfoTable::class)
            ->onlyMethods(['updateRowWithVersion'])
            ->setConstructorArgs([['connection' => ConnectionManager::get('test'), 'alias' => 'TIndividualReservationInfo', 'table' => 't_individual_reservation_info']])
            ->getMock();
        $mock->method('updateRowWithVersion')->willReturn(false);
        TableRegistry::getTableLocator()->set('TIndividualReservationInfo', $mock);

        $this->expectExceptionMessage('optimistic_conflict');

        (new ReservationCopyService())->copyMonth(new Date('2030-01-01'), new Date('2030-02-01'), null, false, null, false);
    }

    public function testPreviewMonth_skipsCancelledSourceAndInvalidDates(): void
    {
        $this->insertRow('2030-01-10', ['eat_flag' => 0, 'i_change_flag' => 0]);

        $preview = $this->service->previewMonth(new Date('2030-01-01'), new Date('2030-02-01'), null, false);

        $this->assertSame(1, $preview['will_skip']);
        $this->assertSame(0, $preview['will_copy']);
    }
}
