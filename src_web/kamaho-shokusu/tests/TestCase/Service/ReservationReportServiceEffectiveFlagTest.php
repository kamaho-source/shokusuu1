<?php
declare(strict_types=1);

namespace App\Test\TestCase\Service;

use App\Service\ReservationReportService;
use Cake\I18n\Date;
use Cake\I18n\DateTime;
use Cake\ORM\TableRegistry;
use App\Service\ReservationWriteService;
use Cake\TestSuite\TestCase;

/**
 * ReservationReportService の effective flag 集計テスト。
 */
class ReservationReportServiceEffectiveFlagTest extends TestCase
{
    protected array $fixtures = [
        'app.MUserInfo',
        'app.MUserGroup',
        'app.MRoomInfo',
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

    /**
     * 予約を更新したら、直前編集用の部屋別利用者一覧にすぐ反映される（#819）。
     * 以前はキャッシュを読む側と消す側のキーが食い違い、古い一覧が返り続けていた。
     */
    public function testGetUsersByRoomForEdit_reflectsToggleImmediately(): void
    {
        $locator = TableRegistry::getTableLocator();
        $userGroupTable   = $locator->get('MUserGroup');
        $reservationTable = $locator->get('TIndividualReservationInfo');
        $date = Date::today('Asia/Tokyo')->addDays(20)->format('Y-m-d');
        $before = $this->findUser($this->service->getUsersByRoomForEdit($userGroupTable, $reservationTable, 1, $date), 1);
        $this->assertFalse($before['meals']['morning']);

        $writer = new ReservationWriteService($reservationTable, $locator->get('MUserInfo'), $locator->get('MRoomInfo'), '/webroot/');
        $writer->processToggle(1, ['date' => $date, 'meal' => 1, 'value' => 1, 'userId' => 1], 1, '管理者ユーザー');

        $after = $this->findUser($this->service->getUsersByRoomForEdit($userGroupTable, $reservationTable, 1, $date), 1);
        $this->assertTrue($after['meals']['morning']);
    }

    /** 異動で抜けた所属・削除済みのユーザーは、部屋別利用者一覧に含めない（#819） */
    public function testGetUsersByRoomForEdit_excludesWithdrawnAndDeletedUsers(): void
    {
        $locator = TableRegistry::getTableLocator();
        $locator->get('MUserGroup')->updateAll(['active_flag' => 1], ['i_id_user' => 2, 'i_id_room' => 1]);
        $locator->get('MUserInfo')->updateAll(['i_del_flag' => 1], ['i_id_user' => 3]);
        $date = Date::today('Asia/Tokyo')->addDays(21)->format('Y-m-d');

        $ids = array_map(
            static fn (array $u) => (int)$u['id'],
            $this->service->getUsersByRoomForEdit($locator->get('MUserGroup'), $locator->get('TIndividualReservationInfo'), 1, $date)
        );

        $this->assertContains(1, $ids);
        $this->assertNotContains(2, $ids);
        $this->assertNotContains(3, $ids);
    }

    private function findUser(array $users, int $userId): array
    {
        foreach ($users as $user) {
            if ((int)$user['id'] === $userId) {
                return $user;
            }
        }
        $this->fail('user ' . $userId . ' not found');
    }
}
