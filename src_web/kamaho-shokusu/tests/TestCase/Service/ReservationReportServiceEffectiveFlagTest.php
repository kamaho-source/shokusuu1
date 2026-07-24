<?php
declare(strict_types=1);

namespace App\Test\TestCase\Service;

use App\Service\ReservationReportService;
use Cake\I18n\Date;
use Cake\I18n\DateTime;
use Cake\ORM\TableRegistry;
use Cake\TestSuite\TestCase;

/**
 * ReservationReportService の effective flag 集計テスト。
 */
class ReservationReportServiceEffectiveFlagTest extends TestCase
{
    protected array $fixtures = [
        'app.MUserInfo',
        'app.MUserGroup',
        'app.TIndividualReservationInfo',
    ];

    private ReservationReportService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new ReservationReportService();
    }

    public function testBuildAllRoomsMealCounts_countsLastMinuteChangeFlagOnly(): void
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
            'dt_create' => new DateTime(),
            'i_version' => 1,
        ]));

        $result = $this->service->buildAllRoomsMealCounts($table, $date, $date);

        $this->assertCount(1, $result);
        $this->assertSame(1, $result[0]['lunch']);
        $this->assertSame(1, $result[0]['total']);
    }

    public function testGetUsersByRoomForEdit_reflectsEffectiveFlag(): void
    {
        $userGroupTable = TableRegistry::getTableLocator()->get('MUserGroup');
        $reservationTable = TableRegistry::getTableLocator()->get('TIndividualReservationInfo');
        $date = Date::today('Asia/Tokyo')->addDays(7)->format('Y-m-d');

        $reservationTable->saveOrFail($reservationTable->newEntity([
            'i_id_user' => 1,
            'i_id_room' => 1,
            'd_reservation_date' => $date,
            'i_reservation_type' => 2,
            'eat_flag' => 0,
            'i_change_flag' => 1,
            'c_create_user' => 'test',
            'dt_create' => new DateTime(),
            'i_version' => 1,
        ]));

        $users = $this->service->getUsersByRoomForEdit($userGroupTable, $reservationTable, 1, $date);
        $target = null;
        foreach ($users as $user) {
            if ((int)$user['id'] === 1) {
                $target = $user;
                break;
            }
        }

        $this->assertNotNull($target);
        $this->assertTrue($target['meals']['noon']);
    }
}
