<?php
declare(strict_types=1);

namespace App\Test\TestCase\Model\Table;

use App\Exception\ApprovedReservationException;
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
}
