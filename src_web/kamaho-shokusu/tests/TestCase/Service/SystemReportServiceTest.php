<?php
declare(strict_types=1);

namespace App\Test\TestCase\Service;

use App\Service\SystemReportService;
use Cake\Datasource\ConnectionManager;
use Cake\I18n\Date;
use Cake\TestSuite\TestCase;

/**
 * SystemReportService のテスト。
 */
class SystemReportServiceTest extends TestCase
{
    protected array $fixtures = [
        'app.MUserInfo',
        'app.MUserGroup',
        'app.MRoomInfo',
        'app.TIndividualReservationInfo',
    ];

    /** 日別の集計は、予約日を Y-m-d で数える（文字列にした日付がロケール依存の形になり、0件になっていた） */
    public function testGetDailyStats_countsReservationsByDate(): void
    {
        $date = Date::today()->subDays(3)->format('Y-m-d');
        ConnectionManager::get('test')->insert('t_individual_reservation_info', [
            'i_id_user' => 3, 'd_reservation_date' => $date, 'i_reservation_type' => 1, 'i_id_room' => 1,
            'eat_flag' => 0, 'i_change_flag' => 1, 'i_version' => 1,
            'dt_create' => '2026-01-01 00:00:00', 'c_create_user' => 't',
        ]);

        $stats = (new SystemReportService())->getDailyStats([], $date, $date);

        $this->assertSame(1, $stats[0]['child_count'], '児童（user 3）の直前に追加した予約が数えられていない');
    }
}
