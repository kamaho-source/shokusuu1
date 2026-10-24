<?php
declare(strict_types=1);

namespace App\Test\TestCase\Service;

use App\Service\ReservationReportService;
use Cake\Cache\Cache;
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
        Cache::clear('default');
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

    /** 直前期間で i_change_flag が NULL の行は、当初予定（eat_flag）で数える（カレンダー・承認と同じ） */
    public function testGetMealCounts_nullChangeFlagFallsBackToEatFlag(): void
    {
        $date = Date::today('Asia/Tokyo')->addDays(7)->format('Y-m-d');
        \Cake\Datasource\ConnectionManager::get('test')->insert('t_individual_reservation_info', [
            'i_id_user' => 1, 'i_id_room' => 1, 'd_reservation_date' => $date, 'i_reservation_type' => 3,
            'eat_flag' => 1, 'i_change_flag' => null, 'i_version' => 1,
            'dt_create' => '2026-01-01 00:00:00', 'c_create_user' => 't',
        ]);

        $counts = $this->service->getMealCounts(TableRegistry::getTableLocator()->get('TIndividualReservationInfo'), $date);

        $this->assertSame(1, array_column($counts, 'count', 'meal_type')[3] ?? null);
    }
}
