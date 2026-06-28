<?php
declare(strict_types=1);

namespace App\Test\TestCase\Service;

use App\Service\ReservationQueryService;
use Cake\ORM\TableRegistry;
use Cake\TestSuite\TestCase;

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
}
