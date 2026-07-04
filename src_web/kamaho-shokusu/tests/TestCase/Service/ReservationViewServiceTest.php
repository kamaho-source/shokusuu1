<?php
declare(strict_types=1);

namespace App\Test\TestCase\Service;

use App\Service\ReservationQueryService;
use App\Service\ReservationViewService;
use Cake\ORM\TableRegistry;
use Cake\TestSuite\TestCase;

/**
 * ReservationViewService テスト。
 *
 * buildViewContext の必須キー・isAdmin 判定・日付フォールバックを検証する。
 */
class ReservationViewServiceTest extends TestCase
{
    protected array $fixtures = [
        'app.MUserInfo',
        'app.MUserGroup',
        'app.MRoomInfo',
        'app.TIndividualReservationInfo',
    ];

    private ReservationViewService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new ReservationViewService();
    }

    // ----------------------------------------------------------------
    // buildViewContext — DB使用
    // ----------------------------------------------------------------

    public function testBuildViewContext_nullUser_returnsRequiredKeys(): void
    {
        $roomTable        = TableRegistry::getTableLocator()->get('MRoomInfo');
        $userGroupTable   = TableRegistry::getTableLocator()->get('MUserGroup');
        $reservationTable = TableRegistry::getTableLocator()->get('TIndividualReservationInfo');
        $queryService     = new ReservationQueryService();

        $result = $this->service->buildViewContext(
            null,
            '2099-01-01',
            null,
            $roomTable,
            $userGroupTable,
            $reservationTable,
            $queryService
        );

        $this->assertArrayHasKey('mealDataArray', $result);
        $this->assertArrayHasKey('date', $result);
        $this->assertArrayHasKey('isAdmin', $result);
        $this->assertArrayHasKey('authorizedRooms', $result);
        $this->assertArrayHasKey('activeRoomId', $result);
        $this->assertArrayHasKey('activeRoomName', $result);
        $this->assertArrayHasKey('roomUsers', $result);
        $this->assertArrayHasKey('userMealMap', $result);
    }

    public function testBuildViewContext_nullUser_isAdminFalse(): void
    {
        $roomTable        = TableRegistry::getTableLocator()->get('MRoomInfo');
        $userGroupTable   = TableRegistry::getTableLocator()->get('MUserGroup');
        $reservationTable = TableRegistry::getTableLocator()->get('TIndividualReservationInfo');
        $queryService     = new ReservationQueryService();

        $result = $this->service->buildViewContext(
            null,
            '2099-01-01',
            null,
            $roomTable,
            $userGroupTable,
            $reservationTable,
            $queryService
        );

        $this->assertFalse($result['isAdmin']);
    }

    public function testBuildViewContext_nullDate_usesToday(): void
    {
        $roomTable        = TableRegistry::getTableLocator()->get('MRoomInfo');
        $userGroupTable   = TableRegistry::getTableLocator()->get('MUserGroup');
        $reservationTable = TableRegistry::getTableLocator()->get('TIndividualReservationInfo');
        $queryService     = new ReservationQueryService();

        $today = (new \DateTime('now', new \DateTimeZone('Asia/Tokyo')))->format('Y-m-d');

        $result = $this->service->buildViewContext(
            null,
            null,
            null,
            $roomTable,
            $userGroupTable,
            $reservationTable,
            $queryService
        );

        $this->assertSame($today, $result['date']);
    }

    public function testBuildViewContext_mealDataArrayIsArray(): void
    {
        $roomTable        = TableRegistry::getTableLocator()->get('MRoomInfo');
        $userGroupTable   = TableRegistry::getTableLocator()->get('MUserGroup');
        $reservationTable = TableRegistry::getTableLocator()->get('TIndividualReservationInfo');
        $queryService     = new ReservationQueryService();

        $result = $this->service->buildViewContext(
            null,
            '2099-01-01',
            null,
            $roomTable,
            $userGroupTable,
            $reservationTable,
            $queryService
        );

        $this->assertIsArray($result['mealDataArray']);
    }
}
