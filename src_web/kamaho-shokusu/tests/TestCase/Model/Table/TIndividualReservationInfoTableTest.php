<?php
declare(strict_types=1);

namespace App\Test\TestCase\Model\Table;

use App\Model\Table\TIndividualReservationInfoTable;
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

    /* =====================================================================
     * 食事種別定数
     * ===================================================================== */

    public function testMealConstantValues(): void
    {
        $this->assertSame(1, TIndividualReservationInfoTable::MEAL_BREAKFAST);
        $this->assertSame(2, TIndividualReservationInfoTable::MEAL_LUNCH);
        $this->assertSame(3, TIndividualReservationInfoTable::MEAL_DINNER);
        $this->assertSame(4, TIndividualReservationInfoTable::MEAL_BENTO);
    }

    public function testMealConstantsAreDistinct(): void
    {
        $constants = [
            TIndividualReservationInfoTable::MEAL_BREAKFAST,
            TIndividualReservationInfoTable::MEAL_LUNCH,
            TIndividualReservationInfoTable::MEAL_DINNER,
            TIndividualReservationInfoTable::MEAL_BENTO,
        ];

        $this->assertSame(count($constants), count(array_unique($constants)));
    }

    /* =====================================================================
     * 昼↔弁当 排他ルール（buildRules）
     * ===================================================================== */

    public function testBuildRulesAllowsLunchAlone(): void
    {
        // 昼のみ: 弁当なし → バリデーション通過
        $entity = $this->TIndividualReservationInfo->newEntity([
            'i_id_user'          => 2,
            'd_reservation_date' => '2020-01-01', // 過去日: eat_flag ベース
            'i_id_room'          => 1,
            'i_reservation_type' => TIndividualReservationInfoTable::MEAL_LUNCH,
            'eat_flag'           => 1,
            'i_change_flag'      => 0,
            'i_version'          => 1,
        ]);

        $saved = $this->TIndividualReservationInfo->save($entity);
        $this->assertNotFalse($saved, '昼のみ保存に失敗: ' . json_encode($entity->getErrors()));
    }

    public function testBuildRulesAllowsBentoAlone(): void
    {
        $entity = $this->TIndividualReservationInfo->newEntity([
            'i_id_user'          => 3,
            'd_reservation_date' => '2020-01-01',
            'i_id_room'          => 1,
            'i_reservation_type' => TIndividualReservationInfoTable::MEAL_BENTO,
            'eat_flag'           => 1,
            'i_change_flag'      => 0,
            'i_version'          => 1,
        ]);

        $saved = $this->TIndividualReservationInfo->save($entity);
        $this->assertNotFalse($saved, '弁当のみ保存に失敗: ' . json_encode($entity->getErrors()));
    }

    public function testBuildRulesBlocksLunchAndBentoSimultaneously(): void
    {
        // 昼を先に保存 (eat_flag=1)
        $lunch = $this->TIndividualReservationInfo->newEntity([
            'i_id_user'          => 4,
            'd_reservation_date' => '2020-02-01',
            'i_id_room'          => 1,
            'i_reservation_type' => TIndividualReservationInfoTable::MEAL_LUNCH,
            'eat_flag'           => 1,
            'i_change_flag'      => 0,
            'i_version'          => 1,
        ]);
        $this->TIndividualReservationInfo->saveOrFail($lunch);

        // 同日に弁当 (eat_flag=1) を保存 → 排他エラー
        $bento = $this->TIndividualReservationInfo->newEntity([
            'i_id_user'          => 4,
            'd_reservation_date' => '2020-02-01',
            'i_id_room'          => 1,
            'i_reservation_type' => TIndividualReservationInfoTable::MEAL_BENTO,
            'eat_flag'           => 1,
            'i_change_flag'      => 0,
            'i_version'          => 1,
        ]);
        $saved = $this->TIndividualReservationInfo->save($bento);

        $this->assertFalse($saved, '昼と弁当が同時に保存されてしまった');
    }

    public function testBuildRulesAllowsBentoWhenLunchIsOff(): void
    {
        // 昼を保存 (eat_flag=0: 無効)
        $lunch = $this->TIndividualReservationInfo->newEntity([
            'i_id_user'          => 5,
            'd_reservation_date' => '2020-03-01',
            'i_id_room'          => 1,
            'i_reservation_type' => TIndividualReservationInfoTable::MEAL_LUNCH,
            'eat_flag'           => 0,
            'i_change_flag'      => 0,
            'i_version'          => 1,
        ]);
        $this->TIndividualReservationInfo->saveOrFail($lunch);

        // 同日に弁当 (eat_flag=1) → 昼は無効なので通過するはず
        $bento = $this->TIndividualReservationInfo->newEntity([
            'i_id_user'          => 5,
            'd_reservation_date' => '2020-03-01',
            'i_id_room'          => 1,
            'i_reservation_type' => TIndividualReservationInfoTable::MEAL_BENTO,
            'eat_flag'           => 1,
            'i_change_flag'      => 0,
            'i_version'          => 1,
        ]);
        $saved = $this->TIndividualReservationInfo->save($bento);

        $this->assertNotFalse($saved, '昼が無効なのに弁当が保存できなかった: ' . json_encode($bento->getErrors()));
    }
}
