<?php
declare(strict_types=1);

namespace App\Test\TestCase\Service;

use App\Service\ReservationQueryService;
use Cake\Datasource\ConnectionManager;
use Cake\I18n\Date;
use Cake\ORM\TableRegistry;
use Cake\TestSuite\TestCase;

/**
 * ReservationQueryService テスト。
 *
 * hasDuplicateReservation・getUsersByRoom・getReservationSnapshots・getUsersByRoomForBulk の挙動を検証する。
 */
class ReservationQueryServiceTest extends TestCase
{
    protected array $fixtures = [
        'app.MUserInfo',
        'app.MUserGroup',
        'app.MRoomInfo',
        'app.TIndividualReservationInfo',
    ];

    private ReservationQueryService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new ReservationQueryService();
    }

    // ----------------------------------------------------------------
    // hasDuplicateReservation — DB使用
    // ----------------------------------------------------------------

    public function testHasDuplicateReservation_nonExistent_returnsFalse(): void
    {
        $reservationTable = TableRegistry::getTableLocator()->get('TIndividualReservationInfo');

        $result = $this->service->hasDuplicateReservation(
            $reservationTable,
            '2099-12-31',
            9999,
            1
        );

        $this->assertFalse($result);
    }

    // ----------------------------------------------------------------
    // getUsersByRoom — DB使用
    // ----------------------------------------------------------------

    public function testGetUsersByRoom_returnsArray(): void
    {
        $userGroupTable   = TableRegistry::getTableLocator()->get('MUserGroup');
        $reservationTable = TableRegistry::getTableLocator()->get('TIndividualReservationInfo');

        $result = $this->service->getUsersByRoom($userGroupTable, $reservationTable, 1, null);

        $this->assertIsArray($result);
    }

    public function testGetUsersByRoom_eachEntryHasRequiredKeys(): void
    {
        $userGroupTable   = TableRegistry::getTableLocator()->get('MUserGroup');
        $reservationTable = TableRegistry::getTableLocator()->get('TIndividualReservationInfo');

        $result = $this->service->getUsersByRoom($userGroupTable, $reservationTable, 1, null);

        $this->assertNotEmpty($result);
        foreach ($result as $entry) {
            $this->assertArrayHasKey('id', $entry);
            $this->assertArrayHasKey('name', $entry);
            $this->assertArrayHasKey('morning', $entry);
            $this->assertArrayHasKey('noon', $entry);
            $this->assertArrayHasKey('night', $entry);
            $this->assertArrayHasKey('bento', $entry);
        }
    }

    public function testGetUsersByRoom_nonExistentRoom_returnsEmpty(): void
    {
        $userGroupTable   = TableRegistry::getTableLocator()->get('MUserGroup');
        $reservationTable = TableRegistry::getTableLocator()->get('TIndividualReservationInfo');

        $result = $this->service->getUsersByRoom($userGroupTable, $reservationTable, 9999, null);

        $this->assertSame([], $result);
    }

    // ----------------------------------------------------------------
    // getReservationSnapshots — DB使用
    // ----------------------------------------------------------------

    public function testGetReservationSnapshots_zeroRoomId_returnsEmpty(): void
    {
        $reservationTable = TableRegistry::getTableLocator()->get('TIndividualReservationInfo');

        $result = $this->service->getReservationSnapshots($reservationTable, 0, ['2099-01-01']);

        $this->assertSame([], $result);
    }

    public function testGetReservationSnapshots_emptyDates_returnsEmpty(): void
    {
        $reservationTable = TableRegistry::getTableLocator()->get('TIndividualReservationInfo');

        $result = $this->service->getReservationSnapshots($reservationTable, 1, []);

        $this->assertSame([], $result);
    }

    public function testGetReservationSnapshots_nonExistentData_returnsEmptyMap(): void
    {
        $reservationTable = TableRegistry::getTableLocator()->get('TIndividualReservationInfo');

        $result = $this->service->getReservationSnapshots($reservationTable, 1, ['2099-12-31']);

        $this->assertIsArray($result);
        $this->assertEmpty($result);
    }

    // ----------------------------------------------------------------
    // getUsersByRoomForBulk — DB使用
    // ----------------------------------------------------------------

    public function testGetUsersByRoomForBulk_returnsRequiredKeys(): void
    {
        $userGroupTable   = TableRegistry::getTableLocator()->get('MUserGroup');
        $reservationTable = TableRegistry::getTableLocator()->get('TIndividualReservationInfo');

        $result = $this->service->getUsersByRoomForBulk($userGroupTable, $reservationTable, 1, null);

        $this->assertArrayHasKey('users', $result);
        $this->assertArrayHasKey('reservations', $result);
        $this->assertArrayHasKey('other_room_reservations', $result);
        $this->assertArrayHasKey('total', $result);
        $this->assertArrayHasKey('page', $result);
        $this->assertArrayHasKey('limit', $result);
        $this->assertArrayHasKey('reservation_snapshot', $result);
    }

    public function testGetUsersByRoomForBulk_roomWithUsers_returnsUsers(): void
    {
        $userGroupTable   = TableRegistry::getTableLocator()->get('MUserGroup');
        $reservationTable = TableRegistry::getTableLocator()->get('TIndividualReservationInfo');

        $result = $this->service->getUsersByRoomForBulk($userGroupTable, $reservationTable, 1, null);

        // フィクスチャにroom1にuser1,2,3が存在する
        $this->assertGreaterThan(0, $result['total']);
    }

    // ----------------------------------------------------------------
    // 有効値判定の統一（バグ4）
    // ----------------------------------------------------------------

    /**
     * バグ4: getUsersByRoomForBulk の有効値判定を他サービスと統一する。
     *
     * 過去日の予約は isLastMinuteWindow（今日〜今日+14日）では対象外となり
     * eat_flag が使われていたが、他サービスは下限のない shouldUseChangeFlag を
     * 使うため i_change_flag が優先される。両者の結果が一致することを検証する。
     */
    public function testGetUsersByRoomForBulk_pastDate_usesChangeFlagLikeOtherServices(): void
    {
        $pastDate = Date::today('Asia/Tokyo')->subDays(3)->format('Y-m-d');

        // eat_flag=0（未予約）だが i_change_flag=1（直前で予約に変更）の過去日データ
        ConnectionManager::get('test')->insert('t_individual_reservation_info', [
            'i_id_user'          => 2,
            'd_reservation_date' => $pastDate,
            'i_reservation_type' => 1,
            'i_id_room'          => 1,
            'eat_flag'           => 0,
            'i_change_flag'      => 1,
            'i_approval_status'  => 0,
            'i_version'          => 1,
            'dt_create'          => '2026-01-01 00:00:00',
            'c_create_user'      => 'test',
        ]);

        $userGroupTable   = TableRegistry::getTableLocator()->get('MUserGroup');
        $reservationTable = TableRegistry::getTableLocator()->get('TIndividualReservationInfo');

        $bulk = $this->service->getUsersByRoomForBulk($userGroupTable, $reservationTable, 1, $pastDate);
        $this->assertTrue(
            $bulk['reservations'][2][1] ?? false,
            'i_change_flag=1 の過去日予約が一括編集画面で有効と判定されていない'
        );

        // getUsersByRoom（shouldUseChangeFlag 利用）と判定が一致する
        $byRoom = $this->service->getUsersByRoom($userGroupTable, $reservationTable, 1, $pastDate);
        $user2  = array_values(array_filter($byRoom, static fn(array $u): bool => $u['id'] === 2));
        $this->assertTrue($user2[0]['morning'], 'getUsersByRoom と判定が一致していない');
    }
}
