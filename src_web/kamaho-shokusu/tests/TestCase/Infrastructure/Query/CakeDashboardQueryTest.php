<?php
declare(strict_types=1);

namespace App\Test\TestCase\Infrastructure\Query;

use App\Infrastructure\Query\CakeDashboardQuery;
use Cake\Datasource\ConnectionManager;
use Cake\TestSuite\TestCase;

/**
 * ダッシュボードの読み取り（CakeDashboardQuery）。
 */
class CakeDashboardQueryTest extends TestCase
{
    protected array $fixtures = ['app.MUserInfo', 'app.TIndividualReservationInfo'];

    public function testAdminLevel(): void
    {
        $q = new CakeDashboardQuery();
        $this->assertSame(1, $q->adminLevel(1));
        $this->assertSame(0, $q->adminLevel(2));
        $this->assertNull($q->adminLevel(9999));
        ConnectionManager::get('test')->update('m_user_info', ['i_admin' => null], ['i_id_user' => 2]);
        $this->assertNull($q->adminLevel(2), 'NULL は呼び出し側でログイン時の値を使う');
    }

    /** 食べる・食べないのどちらでも、その日の行があれば報告済み */
    public function testHasReservationOn(): void
    {
        ConnectionManager::get('test')->insert('t_individual_reservation_info', [
            'i_id_user' => 3, 'd_reservation_date' => '2030-05-05', 'i_reservation_type' => 1, 'i_id_room' => 1,
            'eat_flag' => 0, 'i_change_flag' => 0, 'i_version' => 1, 'dt_create' => '2026-01-01 00:00:00', 'c_create_user' => 't',
        ]);
        $q = new CakeDashboardQuery();

        $this->assertTrue($q->hasReservationOn(3, '2030-05-05'));
        $this->assertFalse($q->hasReservationOn(3, '2030-05-06'));
        $this->assertFalse($q->hasReservationOn(2, '2030-05-05'));
    }
}
