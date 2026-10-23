<?php
declare(strict_types=1);

namespace App\Test\TestCase\Model\Table;

use App\Exception\ApprovedReservationException;
use App\Exception\DuplicateMealReservationException;
use App\Model\Table\TIndividualReservationInfoTable;
use Cake\Datasource\ConnectionManager;
use Cake\I18n\Date;
use Cake\TestSuite\TestCase;

/**
 * App\Model\Table\TIndividualReservationInfoTable Test Case
 */
class TIndividualReservationInfoTableTest extends TestCase
{
    /**
     * Test subject
     *
     * @var \App\Model\Table\TIndividualReservationInfoTable
     */
    protected $TIndividualReservationInfo;

    /**
     * Fixtures
     *
     * @var list<string>
     */
    protected array $fixtures = [
        'app.TIndividualReservationInfo',
    ];

    /**
     * setUp method
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();
        $config = $this->getTableLocator()->exists('TIndividualReservationInfo') ? [] : ['className' => TIndividualReservationInfoTable::class];
        $this->TIndividualReservationInfo = $this->getTableLocator()->get('TIndividualReservationInfo', $config);
    }

    /**
     * tearDown method
     *
     * @return void
     */
    protected function tearDown(): void
    {
        unset($this->TIndividualReservationInfo);

        parent::tearDown();
    }

    /**
     * Test validationDefault method
     *
     * @return void
     * @uses \App\Model\Table\TIndividualReservationInfoTable::validationDefault()
     */
    public function testValidationDefault(): void
    {
        $valid = $this->TIndividualReservationInfo->newEntity([
            'i_id_user' => 1,
            'd_reservation_date' => '2024-09-07',
            'i_reservation_type' => 1,
            'i_id_room' => 1,
            'eat_flag' => 1,
        ]);
        $this->assertEmpty($valid->getErrors());

        $invalid = $this->TIndividualReservationInfo->newEntity([
            'd_reservation_date' => '2024-09-07',
            'i_reservation_type' => 1,
            'i_id_room' => 1,
        ]);
        $this->assertArrayHasKey('i_id_user', $invalid->getErrors());
    }

    // ----------------------------------------------------------------
    // 承認済み保護（バグ1: 共通ガード / バグ2: 昼⇔弁の相互排他）
    // ----------------------------------------------------------------

    /** 直前編集ウィンドウ外の未来日（通常予約扱い） */
    private function futureDate(): string
    {
        return Date::today('Asia/Tokyo')->addDays(30)->format('Y-m-d');
    }

    private function insertRow(array $override = []): void
    {
        $defaults = [
            'i_id_user'          => 1,
            'd_reservation_date' => $this->futureDate(),
            'i_reservation_type' => 2,
            'i_id_room'          => 1,
            'eat_flag'           => 1,
            'i_change_flag'      => 1,
            'i_approval_status'  => 0,
            'i_version'          => 1,
            'dt_create'          => '2024-09-07 16:00:30',
            'c_create_user'      => 'test',
        ];
        ConnectionManager::get('test')->insert(
            't_individual_reservation_info',
            array_merge($defaults, $override)
        );
    }

    private function fetchRow(int $mealType): ?object
    {
        return $this->TIndividualReservationInfo->find()
            ->where([
                'i_id_user'          => 1,
                'd_reservation_date' => $this->futureDate(),
                'i_id_room'          => 1,
                'i_reservation_type' => $mealType,
            ])
            ->first();
    }

    /**
     * バグ1: 共通ガード updateRowWithVersion は承認済み行の更新を拒否する。
     */
    public function testUpdateRowWithVersionRejectsApprovedRow(): void
    {
        $this->insertRow(['i_approval_status' => 2]);
        $row = $this->fetchRow(2);

        try {
            $this->TIndividualReservationInfo->updateRowWithVersion($row, ['eat_flag' => 0]);
            $this->fail('ApprovedReservationException が投げられていない');
        } catch (ApprovedReservationException $e) {
            $this->assertStringContainsString('承認済み', $e->getMessage());
        }

        $this->assertSame(1, (int)$this->fetchRow(2)->eat_flag, '承認済み行が書き換えられている');
    }

    /**
     * 未承認行はこれまで通り更新できる（承認ガードの過剰適用でないことの確認）。
     */
    public function testUpdateRowWithVersionUpdatesUnapprovedRow(): void
    {
        $this->insertRow(['i_approval_status' => 0]);
        $row = $this->fetchRow(2);

        $this->assertTrue($this->TIndividualReservationInfo->updateRowWithVersion($row, ['eat_flag' => 0]));
        $updated = $this->fetchRow(2);
        $this->assertSame(0, (int)$updated->eat_flag);
        $this->assertSame(2, (int)$updated->i_version, 'i_version がインクリメントされていない');
    }

    /**
     * バグ1: toggleMeal は承認済みレコード自体の変更を拒否する。
     */
    public function testToggleMealRejectsApprovedRecord(): void
    {
        $this->insertRow(['i_approval_status' => 1]);

        $this->expectException(ApprovedReservationException::class);
        $this->TIndividualReservationInfo->toggleMeal(1, 1, $this->futureDate(), 2, false, 'tester');
    }

    /**
     * バグ2: 昼(2)⇔弁(4)の相互排他で、承認済みの相手を黙ってゼロクリアしない。
     */
    public function testToggleMealRejectsWhenOpponentIsApproved(): void
    {
        // 承認済みの昼食予約
        $this->insertRow(['i_reservation_type' => 2, 'i_approval_status' => 2, 'eat_flag' => 1]);
        // 未承認の弁当予約（これを ON にすると昼食が強制OFFされる）
        $this->insertRow(['i_reservation_type' => 4, 'i_approval_status' => 0, 'eat_flag' => 0, 'i_change_flag' => 0]);

        try {
            $this->TIndividualReservationInfo->toggleMeal(1, 1, $this->futureDate(), 4, true, 'tester');
            $this->fail('ApprovedReservationException が投げられていない');
        } catch (ApprovedReservationException $e) {
            $this->assertSame('同じ日の昼食予約が承認済みのため変更できません。', $e->getMessage());
        }

        // 承認済みの昼食予約がゼロクリアされていないこと
        $lunch = $this->fetchRow(2);
        $this->assertSame(1, (int)$lunch->eat_flag, '承認済みの昼食予約がクリアされている');
        $this->assertSame(1, (int)$lunch->i_change_flag, '承認済みの昼食予約がクリアされている');

        // 弁当側の更新もトランザクションごとロールバックされる
        $this->assertSame(0, (int)$this->fetchRow(4)->eat_flag, '弁当の更新がロールバックされていない');
    }

    // ----------------------------------------------------------------
    // 同じ食事を複数の部屋で同時に有効にしない（二重予約・食数の二重計上の防止）
    // ----------------------------------------------------------------

    private function insertMealRow(int $roomId, string $date, int $eat, ?int $chg): void
    {
        ConnectionManager::get('test')->insert('t_individual_reservation_info', [
            'i_id_user' => 9901, 'd_reservation_date' => $date, 'i_reservation_type' => 3, 'i_id_room' => $roomId,
            'eat_flag' => $eat, 'i_change_flag' => $chg, 'i_version' => 1,
            'dt_create' => '2026-01-01 00:00:00', 'c_create_user' => 't', 'dt_update' => '2026-01-01 00:00:00', 'c_update_user' => 't',
        ]);
    }

    private function newMealEntity(int $roomId, string $date): \Cake\Datasource\EntityInterface
    {
        $e = $this->TIndividualReservationInfo->newEmptyEntity();
        $e->i_id_user = 9901;
        $e->d_reservation_date = $date;
        $e->i_reservation_type = 3;
        $e->i_id_room = $roomId;
        $e->eat_flag = 1;
        $e->i_change_flag = 1;
        $e->i_version = 1;

        return $e;
    }

    private function farDate(): string
    {
        return Date::today()->addDays(40)->format('Y-m-d');
    }

    public function testSave_rejectsSameMealEffectiveInAnotherRoom(): void
    {
        $this->insertMealRow(1, $this->farDate(), 1, 1);

        $this->expectException(DuplicateMealReservationException::class);
        $this->TIndividualReservationInfo->save($this->newMealEntity(2, $this->farDate()));
    }

    public function testSave_allowsWhenOtherRoomIsCancelled(): void
    {
        $this->insertMealRow(1, $this->farDate(), 0, 0);

        $this->assertNotFalse($this->TIndividualReservationInfo->save($this->newMealEntity(2, $this->farDate())));
    }

    public function testSave_skipsCheckForActualMealRecording(): void
    {
        $this->insertMealRow(1, $this->farDate(), 1, 1);

        $saved = $this->TIndividualReservationInfo->save($this->newMealEntity(2, $this->farDate()), ['checkDuplicateRoom' => false]);
        $this->assertNotFalse($saved);
    }

    public function testUpdateRowWithVersion_rejectsTurningOnWhileOtherRoomEffective(): void
    {
        $this->insertMealRow(1, $this->farDate(), 1, 1);
        $this->insertMealRow(2, $this->farDate(), 0, 0);
        $row = $this->TIndividualReservationInfo->find()->where(['i_id_user' => 9901, 'i_id_room' => 2])->firstOrFail();

        $this->expectException(DuplicateMealReservationException::class);
        $this->TIndividualReservationInfo->updateRowWithVersion($row, ['eat_flag' => 1, 'i_change_flag' => 1]);
    }

    public function testUpdateRowWithVersion_allowsMovingTheSameRowToAnotherRoom(): void
    {
        $this->insertMealRow(1, $this->farDate(), 1, 1);
        $row = $this->TIndividualReservationInfo->find()->where(['i_id_user' => 9901, 'i_id_room' => 1])->firstOrFail();

        $this->assertTrue($this->TIndividualReservationInfo->updateRowWithVersion($row, ['i_id_room' => 2]));
        $this->assertSame([2], $this->TIndividualReservationInfo->findEffectiveRoomIds(9901, $this->farDate(), 3));
    }

    private function nearDate(): string
    {
        return Date::today()->addDays(3)->format('Y-m-d');
    }

    public function testSave_lastMinuteUsesChangeFlag_rejectsWhenOtherRoomAddedLastMinute(): void
    {
        // 直前期間は i_change_flag が有効値。当初予定なし（eat0）でも直前に追加した部屋は有効
        $this->insertMealRow(1, $this->nearDate(), 0, 1);

        $this->expectException(DuplicateMealReservationException::class);
        $this->TIndividualReservationInfo->save($this->newMealEntity(2, $this->nearDate()));
    }

    public function testSave_lastMinuteUsesChangeFlag_allowsWhenOtherRoomCancelledLastMinute(): void
    {
        // 当初予定あり（eat1）でも、直前に取り消した部屋（chg0）は有効ではない
        $this->insertMealRow(1, $this->nearDate(), 1, 0);

        $this->assertNotFalse($this->TIndividualReservationInfo->save($this->newMealEntity(2, $this->nearDate())));
    }

    public function testUpdateRowWithVersion_allowsCancellingWhileAnotherRoomIsEffective(): void
    {
        // 以前からの二重予約は、片方を取り消して解消できる
        $this->insertMealRow(1, $this->farDate(), 1, 1);
        $this->insertMealRow(2, $this->farDate(), 1, 1);
        $row = $this->TIndividualReservationInfo->find()->where(['i_id_user' => 9901, 'i_id_room' => 2])->firstOrFail();

        $this->assertTrue($this->TIndividualReservationInfo->updateRowWithVersion($row, ['eat_flag' => 0, 'i_change_flag' => 0]));
    }

    public function testUpdateRowWithVersion_allowsUpdatingAnAlreadyEffectiveRowInPlace(): void
    {
        // 有効なままの更新（同じ部屋）は、既存の二重予約があっても止めない
        $this->insertMealRow(1, $this->farDate(), 1, 1);
        $this->insertMealRow(2, $this->farDate(), 1, 1);
        $row = $this->TIndividualReservationInfo->find()->where(['i_id_user' => 9901, 'i_id_room' => 2])->firstOrFail();

        $this->assertTrue($this->TIndividualReservationInfo->updateRowWithVersion($row, ['eat_flag' => 1, 'i_change_flag' => 1]));
    }

    public function testDuplicateMessage_namesTheDateAndMeal(): void
    {
        $this->insertMealRow(1, $this->farDate(), 1, 1);

        try {
            $this->TIndividualReservationInfo->save($this->newMealEntity(2, $this->farDate()));
            $this->fail('DuplicateMealReservationException が出るはず');
        } catch (DuplicateMealReservationException $e) {
            $this->assertStringContainsString($this->farDate() . ' の夕食', $e->getMessage());
        }
    }

    /** 別の部屋に弁当が有効なまま、取消済みの昼食行を有効に戻すと拒否する（新規保存の buildRules と同じ扱い） */
    public function testUpdateRowWithVersion_rejectsLunchWhileBentoEffectiveInAnotherRoom(): void
    {
        $conn = ConnectionManager::get('test');
        foreach ([[1, 4, 1], [2, 2, 0]] as [$roomId, $meal, $flag]) {
            $conn->insert('t_individual_reservation_info', [
                'i_id_user' => 9901, 'd_reservation_date' => $this->farDate(), 'i_reservation_type' => $meal, 'i_id_room' => $roomId,
                'eat_flag' => $flag, 'i_change_flag' => $flag, 'i_version' => 1,
                'dt_create' => '2026-01-01 00:00:00', 'c_create_user' => 't',
            ]);
        }
        $row = $this->TIndividualReservationInfo->find()
            ->where(['i_id_user' => 9901, 'i_id_room' => 2, 'i_reservation_type' => 2])->firstOrFail();

        $this->expectException(DuplicateMealReservationException::class);
        $this->expectExceptionMessage('弁当が予約されているため昼食は予約できません');
        $this->TIndividualReservationInfo->updateRowWithVersion($row, ['eat_flag' => 1, 'i_change_flag' => 1]);
    }

    /** 同じ部屋でも、弁当が有効なまま昼食の行を有効に戻すと拒否する（toggleMeal 以外の経路は相手を取り消さないため） */
    public function testUpdateRowWithVersion_rejectsLunchWhileBentoEffectiveInSameRoom(): void
    {
        $conn = ConnectionManager::get('test');
        foreach ([[4, 1], [2, 0]] as [$meal, $flag]) {
            $conn->insert('t_individual_reservation_info', [
                'i_id_user' => 9901, 'd_reservation_date' => $this->farDate(), 'i_reservation_type' => $meal, 'i_id_room' => 1,
                'eat_flag' => $flag, 'i_change_flag' => $flag, 'i_version' => 1,
                'dt_create' => '2026-01-01 00:00:00', 'c_create_user' => 't',
            ]);
        }
        $row = $this->TIndividualReservationInfo->find()
            ->where(['i_id_user' => 9901, 'i_id_room' => 1, 'i_reservation_type' => 2])->firstOrFail();

        $this->expectException(DuplicateMealReservationException::class);
        $this->TIndividualReservationInfo->updateRowWithVersion($row, ['eat_flag' => 1, 'i_change_flag' => 1]);
    }

    /** toggleMeal は相手（弁当）を先に取り消すので、同じ部屋で弁当から昼食へ切り替えられる */
    public function testToggleMeal_switchesBentoToLunchInSameRoom(): void
    {
        $conn = ConnectionManager::get('test');
        foreach ([[4, 1], [2, 0]] as [$meal, $flag]) {
            $conn->insert('t_individual_reservation_info', [
                'i_id_user' => 9901, 'd_reservation_date' => $this->farDate(), 'i_reservation_type' => $meal, 'i_id_room' => 1,
                'eat_flag' => $flag, 'i_change_flag' => $flag, 'i_version' => 1,
                'dt_create' => '2026-01-01 00:00:00', 'c_create_user' => 't',
            ]);
        }

        $this->TIndividualReservationInfo->toggleMeal(9901, 1, $this->farDate(), 2, true, 't');

        $this->assertSame([1], $this->TIndividualReservationInfo->findEffectiveRoomIds(9901, $this->farDate(), 2));
        $this->assertSame([], $this->TIndividualReservationInfo->findEffectiveRoomIds(9901, $this->farDate(), 4));
    }
}
