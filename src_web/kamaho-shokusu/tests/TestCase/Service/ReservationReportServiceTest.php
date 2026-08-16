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

    // ----------------------------------------------------------------
    // buildExportJson — #647回帰テスト
    // enableHydration(false)でもd_reservation_dateはCake\I18n\Date型で返るため、
    // fixtureの日付(2024-09-07)を含む範囲で呼び出し、normalizeDateString()の
    // 引数型不一致によるTypeErrorが再発しないことを確認する。
    // ----------------------------------------------------------------

    public function testBuildExportJson_withCakeI18nDateColumn_doesNotThrow(): void
    {
        $reservationTable = TableRegistry::getTableLocator()->get('TIndividualReservationInfo');

        $result = $this->service->buildExportJson($reservationTable, '2024-01-01', '2024-12-31');

        $this->assertArrayHasKey('overall', $result);
        $this->assertNotEmpty($result['overall'], 'fixtureの2024-09-07データが集計結果に含まれること');
    }
}
