<?php
declare(strict_types=1);

namespace App\Test\TestCase\Service;

use App\Service\ReservationReportService;
use Cake\ORM\TableRegistry;
use Cake\TestSuite\TestCase;

/**
 * ReservationReportService テスト。
 *
 * getMealCounts・buildAllRoomsMealCounts・buildRoomMealCounts・buildExportJson・buildExportJsonRank の挙動を検証する。
 */
class ReservationReportServiceTest extends TestCase
{
    protected array $fixtures = [
        'app.MUserInfo',
        'app.MUserGroup',
        'app.MRoomInfo',
        'app.TIndividualReservationInfo',
        'app.TReservationInfo',
    ];

    private ReservationReportService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new ReservationReportService();
    }

    // ----------------------------------------------------------------
    // getMealCounts — DB使用
    // ----------------------------------------------------------------

    public function testGetMealCounts_returnsArray(): void
    {
        $reservationTable = TableRegistry::getTableLocator()->get('TIndividualReservationInfo');

        $result = $this->service->getMealCounts($reservationTable, '2099-12-31');

        $this->assertIsArray($result);
    }

    // ----------------------------------------------------------------
    // buildAllRoomsMealCounts — DB使用
    // ----------------------------------------------------------------

    public function testBuildAllRoomsMealCounts_returnsArray(): void
    {
        $reservationTable = TableRegistry::getTableLocator()->get('TIndividualReservationInfo');

        $result = $this->service->buildAllRoomsMealCounts($reservationTable, '2099-01-01', '2099-12-31');

        $this->assertIsArray($result);
    }

    public function testBuildAllRoomsMealCounts_emptyPeriod_returnsEmpty(): void
    {
        $reservationTable = TableRegistry::getTableLocator()->get('TIndividualReservationInfo');

        $result = $this->service->buildAllRoomsMealCounts($reservationTable, '2099-01-01', '2099-01-01');

        $this->assertSame([], $result);
    }

    // ----------------------------------------------------------------
    // buildRoomMealCounts — DB使用
    // ----------------------------------------------------------------

    public function testBuildRoomMealCounts_returnsArray(): void
    {
        $reservationTable = TableRegistry::getTableLocator()->get('TIndividualReservationInfo');

        $result = $this->service->buildRoomMealCounts($reservationTable, [1], '2099-01-01', '2099-12-31');

        $this->assertIsArray($result);
    }

    // ----------------------------------------------------------------
    // buildExportJson — DB使用
    // ----------------------------------------------------------------

    public function testBuildExportJson_returnsRequiredKeys(): void
    {
        $reservationTable = TableRegistry::getTableLocator()->get('TIndividualReservationInfo');

        $result = $this->service->buildExportJson($reservationTable, '2099-01-01', '2099-12-31');

        $this->assertArrayHasKey('overall', $result);
        $this->assertArrayHasKey('rooms', $result);
    }

    // ----------------------------------------------------------------
    // buildExportJsonRank — DB使用
    // ----------------------------------------------------------------

    public function testBuildExportJsonRank_returnsArray(): void
    {
        $reservationTable = TableRegistry::getTableLocator()->get('TIndividualReservationInfo');

        $result = $this->service->buildExportJsonRank($reservationTable, '2099-01-01', '2099-12-31', 'データなし');

        $this->assertIsArray($result);
    }
}
