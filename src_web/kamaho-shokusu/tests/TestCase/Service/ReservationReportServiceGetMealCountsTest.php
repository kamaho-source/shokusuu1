<?php
declare(strict_types=1);

namespace App\Test\TestCase\Service;

use App\Service\ReservationReportService;
use Cake\I18n\Date;
use Cake\ORM\TableRegistry;
use Cake\TestSuite\TestCase;

/**
 * ReservationReportService::getMealCounts の effective flag 集計テスト。
 */
class ReservationReportServiceGetMealCountsTest extends TestCase
{
    protected array $fixtures = [
        'app.TIndividualReservationInfo',
    ];

    private ReservationReportService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new ReservationReportService();
    }

    public function testGetMealCounts_countsLastMinuteChangeFlagOnly(): void
    {
        $table = TableRegistry::getTableLocator()->get('TIndividualReservationInfo');
        $date = Date::today('Asia/Tokyo')->addDays(7)->format('Y-m-d');

        $table->saveOrFail($table->newEntity([
            'i_id_user' => 1,
            'i_id_room' => 1,
            'd_reservation_date' => $date,
            'i_reservation_type' => 2,
            'eat_flag' => 0,
            'i_change_flag' => 1,
            'c_create_user' => 'test',
            'dt_create' => new \Cake\I18n\DateTime(),
            'i_version' => 1,
        ]));

        $result = $this->service->getMealCounts($table, $date);

        $this->assertCount(1, $result);
        $this->assertSame(2, (int)$result[0]['meal_type']);
        $this->assertSame(1, (int)$result[0]['count']);
    }
}
