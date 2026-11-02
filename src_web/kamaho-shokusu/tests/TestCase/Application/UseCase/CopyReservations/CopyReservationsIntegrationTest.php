<?php
declare(strict_types=1);

namespace App\Test\TestCase\Application\UseCase\CopyReservations;

use App\Application\UseCase\CopyReservations\CopyReservationsInput;
use App\Application\UseCase\CopyReservations\CopyReservationsOutput;
use App\Application\UseCase\CopyReservations\CopyReservationsUseCase;
use App\Application\UseCase\CopyReservations\PreviewReservationCopyUseCase;
use App\Application\UseCase\CopyReservations\ReservationCopyPlanner;
use App\Domain\Exception\InvalidInputException;
use App\Domain\Exception\UnauthorizedException;
use App\Infrastructure\Persistence\CakeTransactionManager;
use App\Infrastructure\Repository\CakeReservationRepository;
use App\Infrastructure\Repository\CakeRoomMembershipRepository;
use App\Infrastructure\Repository\CakeUserAccountRepository;
use Cake\Chronos\ClockFactory;
use Cake\Datasource\ConnectionManager;
use Cake\I18n\Date;
use Cake\ORM\TableRegistry;
use Cake\TestSuite\TestCase;

/**
 * 予約のコピー（CopyReservationsUseCase）を、本物の Repository・トランザクションにつないで確かめる（旧 ReservationCopyServiceTest を引き継ぐ）。
 *
 * 入力の検証（CopyReservationsInput::fromRequest）も確かめる。
 */
class CopyReservationsIntegrationTest extends TestCase
{
    protected array $fixtures = [
        'app.TIndividualReservationInfo',
        'app.MUserInfo',
        'app.MRoomInfo',
        'app.MUserGroup',
    ];

    private static function planner(): ReservationCopyPlanner
    {
        $locator = TableRegistry::getTableLocator();

        return new ReservationCopyPlanner(
            new CakeReservationRepository($locator->get('TIndividualReservationInfo')),
            new CakeRoomMembershipRepository(),
            new CakeUserAccountRepository($locator->get('MUserInfo')),
            new ClockFactory('Asia/Tokyo'),
        );
    }

    private static function useCase(): CopyReservationsUseCase
    {
        return new CopyReservationsUseCase(
            self::planner(),
            new CakeReservationRepository(TableRegistry::getTableLocator()->get('TIndividualReservationInfo')),
            new CakeTransactionManager(ConnectionManager::get('test')),
        );
    }

    private static function previewUseCase(): PreviewReservationCopyUseCase
    {
        return new PreviewReservationCopyUseCase(self::planner());
    }

    /**
     * 送られる形の値から入力を作る（既定のログインは利用者1。部屋1に所属）。
     *
     * @param array<string, mixed> $data
     */
    private static function input(array $data, int $loginUserId = 1, ?string $actorName = null): CopyReservationsInput
    {
        return CopyReservationsInput::fromRequest($data, $loginUserId, $actorName);
    }

    /** 週コピー・月コピーの入力（部屋の指定なし＝所属部屋すべて） */
    private static function copyInput(string $mode, Date $src, Date $dst, bool $onlyChildren = false, ?int $roomId = null, ?string $actorName = null, int $loginUserId = 1): CopyReservationsInput
    {
        return self::input(['mode' => $mode, 'source' => $src->format('Y-m-d'), 'target' => $dst->format('Y-m-d'), 'only_children' => $onlyChildren, 'room_id' => $roomId], $loginUserId, $actorName);
    }

    /** @return array{total: int, copied: int, skipped: int, invalid_date: int} */
    private function copyWeek(Date $src, Date $dst, bool $onlyChildren = false, ?int $roomId = null): array
    {
        return self::counts(self::useCase()->execute(self::copyInput('week', $src, $dst, $onlyChildren, $roomId)));
    }

    /** @return array{total: int, copied: int, skipped: int, invalid_date: int} */
    private function copyMonth(Date $src, Date $dst, bool $onlyChildren = false, ?string $actorName = null): array
    {
        return self::counts(self::useCase()->execute(self::copyInput('month', $src, $dst, $onlyChildren, null, $actorName)));
    }

    /** @return array{total: int, will_copy: int, will_skip: int, invalid_date?: int} */
    private function previewWeek(Date $src, Date $dst, bool $onlyChildren = false): array
    {
        return self::previewUseCase()->execute(self::copyInput('week', $src, $dst, $onlyChildren))->toArray();
    }

    /** @return array{total: int, will_copy: int, will_skip: int, invalid_date?: int} */
    private function previewMonth(Date $src, Date $dst, bool $onlyChildren = false, ?int $roomId = null): array
    {
        return self::previewUseCase()->execute(self::copyInput('month', $src, $dst, $onlyChildren, $roomId))->toArray();
    }

    /** @return array{total: int, copied: int, skipped: int, invalid_date: int} */
    private static function counts(CopyReservationsOutput $out): array
    {
        return ['total' => $out->total, 'copied' => $out->copied, 'skipped' => $out->skipped, 'invalid_date' => $out->invalidDate];
    }

    /**
     * 入力の誤りとして 422 で断られること。
     *
     * @param array<string, mixed> $data
     */
    private function assertRejected(array $data, string $message): void
    {
        try {
            self::input($data);
            $this->fail('入力の誤りとして断られるはず');
        } catch (InvalidInputException $e) {
            $this->assertSame(422, $e->getStatusCode());
            $this->assertSame($message, $e->getMessage());
        }
    }

    // ----------------------------------------------------------------
    // mode バリデーション
    // ----------------------------------------------------------------

    public function testNormalizeReturnErrorForInvalidMode(): void
    {
        $this->assertRejected([
            'mode' => 'daily',
            'source' => '2026-06-01',
            'target' => '2026-07-01',
        ], 'mode は "week" か "month" を指定してください。');
    }

    public function testNormalizeAcceptsWeekMode(): void
    {
        $result = self::input([
            'mode' => 'week',
            'source' => '2026-06-02',
            'target' => '2026-06-09',
        ]);

        $this->assertSame('week', $result->mode);
    }

    public function testNormalizeAcceptsMonthMode(): void
    {
        $result = self::input([
            'mode' => 'month',
            'source' => '2026-06-01',
            'target' => '2026-07-01',
        ]);

        $this->assertSame('month', $result->mode);
    }

    // ----------------------------------------------------------------
    // source / target バリデーション
    // ----------------------------------------------------------------

    public function testNormalizeReturnErrorForMissingSource(): void
    {
        $this->assertRejected([
            'mode' => 'week',
            'target' => '2026-06-09',
        ], 'source / target（または source_start / target_start）を YYYY-MM-DD 形式で指定してください。');
    }

    public function testNormalizeReturnErrorForMissingTarget(): void
    {
        $this->assertRejected([
            'mode' => 'week',
            'source' => '2026-06-02',
        ], 'source / target（または source_start / target_start）を YYYY-MM-DD 形式で指定してください。');
    }

    public function testNormalizeReturnErrorForInvalidDateFormat(): void
    {
        $this->assertRejected([
            'mode' => 'week',
            'source' => 'not-a-date',
            'target' => '2026-06-09',
        ], 'source / target は YYYY-MM-DD 形式で指定してください。');
    }

    // ----------------------------------------------------------------
    // week モード: 月曜日への正規化
    // ----------------------------------------------------------------

    public function testNormalizeWeekNormalizesToMonday(): void
    {
        // 2026-06-04（木曜） → 月曜（2026-06-01）に正規化される
        $result = self::input([
            'mode' => 'week',
            'source' => '2026-06-04',
            'target' => '2026-06-11',
        ]);

        $this->assertSame('2026-06-01', $result->src->format('Y-m-d'));
        $this->assertSame('2026-06-08', $result->dst->format('Y-m-d'));
    }

    public function testNormalizeWeekKeepsMondayAsIs(): void
    {
        // 2026-06-01（月曜）はそのまま
        $result = self::input([
            'mode' => 'week',
            'source' => '2026-06-01',
            'target' => '2026-06-08',
        ]);

        $this->assertSame('2026-06-01', $result->src->format('Y-m-d'));
    }

    // ----------------------------------------------------------------
    // month モード: 月初への正規化
    // ----------------------------------------------------------------

    public function testNormalizeMonthNormalizesToFirstDayOfMonth(): void
    {
        // 2026-06-15 → 2026-06-01 に正規化される
        $result = self::input([
            'mode' => 'month',
            'source' => '2026-06-15',
            'target' => '2026-07-20',
        ]);

        $this->assertSame('2026-06-01', $result->src->format('Y-m-d'));
        $this->assertSame('2026-07-01', $result->dst->format('Y-m-d'));
    }

    // ----------------------------------------------------------------
    // room_id と only_children
    // ----------------------------------------------------------------

    public function testNormalizeRoomIdParsedCorrectly(): void
    {
        $result = self::input([
            'mode' => 'week',
            'source' => '2026-06-01',
            'target' => '2026-06-08',
            'room_id' => '5',
        ]);

        $this->assertSame(5, $result->roomId);
    }

    public function testNormalizeRoomIdIsNullWhenZero(): void
    {
        $result = self::input([
            'mode' => 'week',
            'source' => '2026-06-01',
            'target' => '2026-06-08',
            'room_id' => '0',
        ]);

        $this->assertNull($result->roomId);
    }

    public function testNormalizeOnlyChildrenDefaultsFalse(): void
    {
        $result = self::input([
            'mode' => 'week',
            'source' => '2026-06-01',
            'target' => '2026-06-08',
        ]);

        $this->assertFalse($result->onlyChildren);
    }

    public function testNormalizeOnlyChildrenParsedAsTrue(): void
    {
        $result = self::input([
            'mode' => 'week',
            'source' => '2026-06-01',
            'target' => '2026-06-08',
            'only_children' => '1',
        ]);

        $this->assertTrue($result->onlyChildren);
    }

    // ----------------------------------------------------------------
    // source_start / target_start エイリアス
    // ----------------------------------------------------------------

    public function testNormalizeAcceptsSourceStartAlias(): void
    {
        $result = self::input([
            'mode' => 'week',
            'source_start' => '2026-06-01',
            'target_start' => '2026-06-08',
        ]);

        $this->assertSame('2026-06-01', $result->src->format('Y-m-d'));
    }

    // ----------------------------------------------------------------
    // 過去日ガード（コピー先が過去日になる場合はスキップされる）
    // ----------------------------------------------------------------

    public function testCopyWeekSkipsRowsWhenTargetIsPast(): void
    {
        // フィクスチャの唯一のレコード（2024-09-07, 土曜）を含む週（2024-09-02 月曜始まり）をコピー元、
        // その1週間後（実行時点で確実に過去日）をコピー先にする。
        $result = $this->copyWeek(new Date('2024-09-02'), new Date('2024-09-09'));

        $this->assertSame(1, $result['total']);
        $this->assertSame(0, $result['copied']);
        $this->assertSame(1, $result['invalid_date']);
    }

    public function testPreviewWeekSkipsRowsWhenTargetIsPast(): void
    {
        $result = $this->previewWeek(new Date('2024-09-02'), new Date('2024-09-09'));

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

        $preview = $this->previewWeek(new Date(self::SRC_MONDAY), new Date(self::DST_MONDAY));
        $this->assertSame(1, $preview['will_copy'], '上書きされる行がプレビューでスキップ扱いになっている');
        $this->assertSame(0, $preview['will_skip']);

        $copy = $this->copyWeek(new Date(self::SRC_MONDAY), new Date(self::DST_MONDAY));
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

        $preview = $this->previewWeek(new Date(self::SRC_MONDAY), new Date(self::DST_MONDAY));
        $copy    = $this->copyWeek(new Date(self::SRC_MONDAY), new Date(self::DST_MONDAY));

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

        $preview = $this->previewWeek(new Date(self::SRC_MONDAY), new Date(self::DST_MONDAY));
        $copy    = $this->copyWeek(new Date(self::SRC_MONDAY), new Date(self::DST_MONDAY));

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

        $preview = $this->previewWeek(new Date(self::SRC_MONDAY), new Date(self::DST_MONDAY));
        $copy    = $this->copyWeek(new Date(self::SRC_MONDAY), new Date(self::DST_MONDAY));

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

        $preview = $this->previewWeek(new Date(self::SRC_MONDAY), new Date(self::DST_MONDAY));
        $copy    = $this->copyWeek(new Date(self::SRC_MONDAY), new Date(self::DST_MONDAY));

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

        $copy = $this->copyWeek(new Date(self::SRC_MONDAY), new Date(self::DST_MONDAY));

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

        $copy = $this->copyWeek($srcMonday, $dstMonday);

        $this->assertSame(1, $copy['copied']);
        $row = $this->fetchRow($dst->format('Y-m-d'));
        $this->assertSame([1, 1], [(int)$row->eat_flag, (int)$row->i_change_flag]);
    }

    // ----------------------------------------------------------------
    // 追加: 対象の部屋 / 月コピー / 子供のみ / 競合
    // ----------------------------------------------------------------

    private function rowAt(int $userId, string $date, int $room = 1, int $type = 1): ?object
    {
        return TableRegistry::getTableLocator()->get('TIndividualReservationInfo')->find()
            ->where(['i_id_user' => $userId, 'd_reservation_date' => $date, 'i_reservation_type' => $type, 'i_id_room' => $room])
            ->first();
    }

    /** 部屋2を作り、利用者1も所属させる */
    private function addRoom2ForUser1(): void
    {
        $conn = ConnectionManager::get('test');
        $conn->insert('m_room_info', [
            'i_id_room' => 2, 'c_room_name' => '部屋2', 'i_disp_no' => 2, 'i_enable' => 1, 'i_del_flg' => 0,
            'dt_create' => '2024-07-29 09:11:06', 'c_create_user' => 't',
        ]);
        $conn->insert('m_user_group', ['i_id_user' => 1, 'i_id_room' => 2, 'active_flag' => 0, 'dt_create' => '2024-09-07 15:59:52', 'c_create_user' => 't']);
    }

    /** 部屋の指定なしは所属部屋すべて（所属していない部屋の行は数えない） */
    public function testTargetRooms_withoutRequestUsesAllAffiliatedRooms(): void
    {
        $this->insertRow('2030-01-08', ['i_id_room' => 1]);
        $this->insertRow('2030-01-09', ['i_id_room' => 2]); // 利用者1は部屋2に所属していない

        $this->assertSame(1, $this->previewWeek(new Date(self::SRC_MONDAY), new Date(self::DST_MONDAY))['total']);
    }

    public function testTargetRooms_requestedAffiliatedRoom(): void
    {
        $this->insertRow('2030-01-08');

        $preview = self::previewUseCase()->execute(self::copyInput('week', new Date(self::SRC_MONDAY), new Date(self::DST_MONDAY), false, 1, null, 2));

        $this->assertSame(1, $preview->total);
    }

    public function testTargetRooms_requestedRoomOutsideAffiliation_isUnauthorized(): void
    {
        $this->expectException(UnauthorizedException::class);
        $this->expectExceptionMessage('所属していない部屋はコピーできません。');

        self::useCase()->execute(self::copyInput('week', new Date(self::SRC_MONDAY), new Date(self::DST_MONDAY), false, 99));
    }

    public function testTargetRooms_noAffiliation_isUnauthorized(): void
    {
        $this->expectException(UnauthorizedException::class);
        $this->expectExceptionMessage('所属している部屋がないため、コピーできません。');

        self::previewUseCase()->execute(self::copyInput('week', new Date(self::SRC_MONDAY), new Date(self::DST_MONDAY), false, null, null, 999));
    }

    public function testNormalizeRoomIdBlankStringIsNull(): void
    {
        $result = self::input(['mode' => 'week', 'source' => '2026-06-02', 'target' => '2026-06-09', 'room_id' => '']);

        $this->assertNull($result->roomId);
    }

    public function testCopyMonth_copiesSameDayAndSkipsDaysMissingInTargetMonth(): void
    {
        $this->insertRow('2030-01-10');
        $this->insertRow('2030-01-31'); // 2月に31日は無い

        $preview = $this->previewMonth(new Date('2030-01-01'), new Date('2030-02-01'));
        $copy = $this->copyMonth(new Date('2030-01-01'), new Date('2030-02-01'));

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
        $actor = '操作者A';

        $this->copyMonth(new Date('2030-01-01'), new Date('2030-02-01'), false, $actor);

        $this->assertSame('操作者A', $this->fetchRow('2030-02-10')->c_create_user);
    }

    public function testCopyMonth_toPastMonth_isInvalidDate(): void
    {
        $preview = $this->previewMonth(new Date('2024-09-01'), new Date('2024-10-01'));
        $copy = $this->copyMonth(new Date('2024-09-01'), new Date('2024-10-01'));

        $this->assertSame(1, $preview['invalid_date']);
        $this->assertSame(0, $copy['copied']);
        $this->assertSame(1, $copy['invalid_date']);
    }

    public function testCopyMonth_noSourceRows_returnsZeros(): void
    {
        $copy = $this->copyMonth(new Date('2031-05-01'), new Date('2031-06-01'));

        $this->assertSame(['total' => 0, 'copied' => 0, 'skipped' => 0, 'invalid_date' => 0], $copy);
    }

    public function testCopyMonth_existingRowsAreSkippedOrRevived(): void
    {
        $this->insertRow('2030-01-10');
        $this->insertRow('2030-01-11');
        $this->insertRow('2030-02-10', ['eat_flag' => 1, 'i_change_flag' => 1]); // 既に有効 → スキップ
        $this->insertRow('2030-02-11', ['eat_flag' => 0, 'i_change_flag' => 0]); // 無効 → 復活

        $preview = $this->previewMonth(new Date('2030-01-01'), new Date('2030-02-01'));
        $copy = $this->copyMonth(new Date('2030-01-01'), new Date('2030-02-01'));

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

        $previewW = $this->previewWeek(new Date(self::SRC_MONDAY), new Date(self::DST_MONDAY), true);
        $copyW = $this->copyWeek(new Date(self::SRC_MONDAY), new Date(self::DST_MONDAY), true);

        $this->assertSame(1, $previewW['total']);
        $this->assertSame(1, $previewW['will_copy']);
        $this->assertSame(1, $copyW['copied']);
        $this->assertNotNull($this->rowAt(3, '2030-01-15'));
        $this->assertNull($this->rowAt(1, '2030-01-15'));

        $this->insertRow('2030-01-10', ['i_id_user' => 3]);
        $this->insertRow('2030-01-10', ['i_id_user' => 1]);
        $previewM = $this->previewMonth(new Date('2030-01-01'), new Date('2030-02-01'), true);
        $copyM = $this->copyMonth(new Date('2030-01-01'), new Date('2030-02-01'), true);

        $this->assertSame(3, $previewM['total']); // 1/8・1/10・先に週コピーされた1/15 の児童分
        $this->assertSame(3, $copyM['copied']);
        $this->assertNull($this->rowAt(1, '2030-02-10'));
    }

    public function testOnlyChildren_withNoChildren_returnsZeros(): void
    {
        ConnectionManager::get('test')->update('m_user_info', ['i_user_level' => 0], ['i_user_level' => 1]);
        $this->insertRow('2030-01-08');

        $this->assertSame(['total' => 0, 'copied' => 0, 'skipped' => 0, 'invalid_date' => 0], $this->copyWeek(new Date(self::SRC_MONDAY), new Date(self::DST_MONDAY), true));
        $this->assertSame(['total' => 0, 'will_copy' => 0, 'will_skip' => 0], $this->previewWeek(new Date(self::SRC_MONDAY), new Date(self::DST_MONDAY), true));
        $this->assertSame(['total' => 0, 'copied' => 0, 'skipped' => 0, 'invalid_date' => 0], $this->copyMonth(new Date('2030-01-01'), new Date('2030-02-01'), true));
        $this->assertSame(['total' => 0, 'will_copy' => 0, 'will_skip' => 0, 'invalid_date' => 0], $this->previewMonth(new Date('2030-01-01'), new Date('2030-02-01'), true));
    }

    public function testCopyWeek_roomFilterLimitsSourceRows(): void
    {
        $this->addRoom2ForUser1();
        $this->insertRow('2030-01-08', ['i_id_room' => 1]);
        $this->insertRow('2030-01-09', ['i_id_room' => 2]);

        $copy = $this->copyWeek(new Date(self::SRC_MONDAY), new Date(self::DST_MONDAY), false, 1);
        $preview = $this->previewMonth(new Date('2030-01-01'), new Date('2030-02-01'), false, 2);

        $this->assertSame(1, $copy['total']);
        $this->assertNotNull($this->fetchRow('2030-01-15'));
        $this->assertNull($this->fetchRow('2030-01-16'));
        $this->assertSame(1, $preview['total']);
    }

    public function testCopyWeek_toEarlierWeek(): void
    {
        $this->insertRow('2030-01-15');

        $copy = $this->copyWeek(new Date(self::DST_MONDAY), new Date(self::SRC_MONDAY));

        $this->assertSame(1, $copy['copied']);
        $this->assertNotNull($this->fetchRow('2030-01-08'));
    }

    public function testCopyWeek_versionConflict_throwsAndRollsBack(): void
    {
        $this->insertRow('2030-01-08');
        $this->insertRow('2030-01-15', ['eat_flag' => 0, 'i_change_flag' => 0]);
        $mock = $this->getMockBuilder(\App\Infrastructure\Persistence\Table\TIndividualReservationInfoTable::class)
            ->onlyMethods(['updateRowWithVersion'])
            ->setConstructorArgs([['connection' => ConnectionManager::get('test'), 'alias' => 'TIndividualReservationInfo', 'table' => 't_individual_reservation_info']])
            ->getMock();
        $mock->method('updateRowWithVersion')->willReturn(false);
        TableRegistry::getTableLocator()->set('TIndividualReservationInfo', $mock);

        try {
            $this->copyWeek(new Date(self::SRC_MONDAY), new Date(self::DST_MONDAY));
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
        $mock = $this->getMockBuilder(\App\Infrastructure\Persistence\Table\TIndividualReservationInfoTable::class)
            ->onlyMethods(['updateRowWithVersion'])
            ->setConstructorArgs([['connection' => ConnectionManager::get('test'), 'alias' => 'TIndividualReservationInfo', 'table' => 't_individual_reservation_info']])
            ->getMock();
        $mock->method('updateRowWithVersion')->willReturn(false);
        TableRegistry::getTableLocator()->set('TIndividualReservationInfo', $mock);

        $this->expectExceptionMessage('optimistic_conflict');

        $this->copyMonth(new Date('2030-01-01'), new Date('2030-02-01'));
    }

    public function testPreviewMonth_skipsCancelledSourceAndInvalidDates(): void
    {
        $this->insertRow('2030-01-10', ['eat_flag' => 0, 'i_change_flag' => 0]);

        $preview = $this->previewMonth(new Date('2030-01-01'), new Date('2030-02-01'));

        $this->assertSame(1, $preview['will_skip']);
        $this->assertSame(0, $preview['will_copy']);
    }

    /** コピー先が直前期間なら、新しい行は当初予定（eat_flag）を 0、既存の取消行は当初予定を変えずに i_change_flag だけを立てる */
    public function testCopyIntoLastMinute_newRowAndCancelledRowKeepOriginalPlanZero(): void
    {
        $dst = Date::today()->addDays(2);
        $src = $dst->subWeeks(1);
        $this->insertRow($src->format('Y-m-d'), ['eat_flag' => 0, 'i_change_flag' => 1]);
        $this->insertRow($src->format('Y-m-d'), ['eat_flag' => 0, 'i_change_flag' => 1, 'i_reservation_type' => 3]);
        $this->insertRow($dst->format('Y-m-d'), ['eat_flag' => 0, 'i_change_flag' => 0, 'i_reservation_type' => 3]);

        $copy = $this->copyWeek($src->startOfWeek(), $dst->startOfWeek());

        $this->assertSame(2, $copy['copied']);
        $new = $this->fetchRow($dst->format('Y-m-d'));
        $this->assertSame([0, 1], [(int)$new->eat_flag, (int)$new->i_change_flag], '新しい行');
        $updated = TableRegistry::getTableLocator()->get('TIndividualReservationInfo')->find()
            ->where(['i_id_user' => 1, 'd_reservation_date' => $dst->format('Y-m-d'), 'i_reservation_type' => 3, 'i_id_room' => 1])->firstOrFail();
        $this->assertSame([0, 1], [(int)$updated->eat_flag, (int)$updated->i_change_flag], '既存の取消行');
    }

    /** 削除済みの部屋はコピーできる部屋に含めない */
    public function testResolveTargetRoomIds_excludesDeletedRoom(): void
    {
        TableRegistry::getTableLocator()->get('MRoomInfo')->updateAll(['i_del_flg' => 1], ['i_id_room' => 1]);

        $this->expectException(UnauthorizedException::class);
        self::previewUseCase()->execute(self::copyInput('week', new Date(self::SRC_MONDAY), new Date(self::DST_MONDAY)));
    }

    /** 児童だけのコピーに、削除済みの児童は含めない */
    public function testOnlyChildren_excludesDeletedChild(): void
    {
        TableRegistry::getTableLocator()->get('MUserInfo')->updateAll(['i_del_flag' => 1], ['i_id_user' => 3]);
        $this->insertRow('2030-01-08', ['i_id_user' => 3]);

        $this->assertSame(['total' => 0, 'copied' => 0, 'skipped' => 0, 'invalid_date' => 0], $this->copyWeek(new Date(self::SRC_MONDAY), new Date(self::DST_MONDAY), true));
    }

    /** プレビューの件数と、実行したときの件数が一致する（写す・スキップ・無効な日付が混ざる月コピー） */
    public function testPreviewCountsMatchCopy_month(): void
    {
        $this->insertRow('2030-01-10');                                          // 写す
        $this->insertRow('2030-01-11');                                          // コピー先に有効な行 → スキップ
        $this->insertRow('2030-02-11', ['eat_flag' => 1, 'i_change_flag' => 1]);
        $this->insertRow('2030-01-12', ['eat_flag' => 0, 'i_change_flag' => 0]); // コピー元が取消済み → スキップ
        $this->insertRow('2030-01-30');                                          // 2月に30日は無い → 無効な日付

        $preview = self::previewUseCase()->execute(self::copyInput('month', new Date('2030-01-01'), new Date('2030-02-01')));
        $copy = self::useCase()->execute(self::copyInput('month', new Date('2030-01-01'), new Date('2030-02-01')));

        $this->assertSame(['total' => 4, 'will_copy' => 1, 'will_skip' => 2, 'invalid_date' => 1], $preview->toArray());
        $this->assertSame([$preview->total, $preview->willCopy, $preview->willSkip, $preview->invalidDate], [$copy->total, $copy->copied, $copy->skipped, $copy->invalidDate]);
        $this->assertSame(['mode' => 'month', 'total' => 4, 'copied' => 1, 'skipped' => 2, 'invalid_date' => 1, 'affected' => 1], $copy->toArray());
    }

    /** 週のプレビューは invalid_date を返さず、今日より前の日はスキップに数える（コピーは無効な日付に数える） */
    public function testPreviewCountsMatchCopy_weekPastTarget(): void
    {
        $preview = self::previewUseCase()->execute(self::copyInput('week', new Date('2024-09-02'), new Date('2024-09-09')));
        $copy = self::useCase()->execute(self::copyInput('week', new Date('2024-09-02'), new Date('2024-09-09')));

        $this->assertSame(['total' => 1, 'will_copy' => 0, 'will_skip' => 1], $preview->toArray());
        $this->assertSame($preview->willCopy, $copy->copied);
        $this->assertSame($preview->willSkip, $copy->skipped + $copy->invalidDate);
    }

    /** 週の入力は月曜日、月の入力は1日にそろえ、ログインしている人・操作者を持つ */
    public function testInputFromRequest_keepsLoginUserAndActor(): void
    {
        $input = CopyReservationsInput::fromRequest(['mode' => 'WEEK', 'source' => '2026-06-07', 'target' => '2026-06-14', 'room_id' => 3, 'only_children' => 'true'], 7, '操作者A');

        $this->assertSame(['week', '2026-06-01', '2026-06-08', 3, true, 7, '操作者A'], [
            $input->mode, $input->src->format('Y-m-d'), $input->dst->format('Y-m-d'), $input->roomId, $input->onlyChildren, $input->loginUserId, $input->actorName,
        ]);
    }

    public function testInputFromRequest_rejectsMissingSourceAndTarget(): void
    {
        $this->assertRejected(['mode' => 'month'], 'source / target（または source_start / target_start）を YYYY-MM-DD 形式で指定してください。');
    }
}
