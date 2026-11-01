<?php
declare(strict_types=1);

namespace App\Test\TestCase\Service;

use App\Service\ActualMealManagementService;
use Cake\I18n\DateTime;
use Cake\ORM\TableRegistry;
use Cake\TestSuite\TestCase;

/**
 * ActualMealManagementService テスト。
 *
 * getAdminOldestAllowedMonday・getAdultUsers・buildWeekGrid・saveActualMeal・requestApproval の挙動を検証する。
 */
class ActualMealManagementServiceTest extends TestCase
{
    protected array $fixtures = [
        'app.MUserInfo',
        'app.MUserGroup',
        'app.MRoomInfo',
        'app.TIndividualReservationInfo',
        'app.TAuditLog',
    ];

    private ActualMealManagementService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new ActualMealManagementService();
    }

    // ----------------------------------------------------------------
    // getAdminOldestAllowedMonday — DB不要
    // ----------------------------------------------------------------

    public function testGetAdminOldestAllowedMonday_returnsDateTimeImmutable(): void
    {
        $result = $this->service->getAdminOldestAllowedMonday();
        $this->assertInstanceOf(\DateTimeImmutable::class, $result);
    }

    public function testGetAdminOldestAllowedMonday_returnsMonday(): void
    {
        $result = $this->service->getAdminOldestAllowedMonday();
        // ISO weekday: 1 = Monday
        $this->assertSame('1', $result->format('N'));
    }

    public function testGetAdminOldestAllowedMonday_isApproximatelyTwoMonthsAgo(): void
    {
        $result = $this->service->getAdminOldestAllowedMonday();
        $now    = new \DateTimeImmutable('now', new \DateTimeZone('Asia/Tokyo'));

        // 2ヶ月前 ±7日の範囲内であること
        $twoMonthsAgo = $now->modify('-2 months');
        $diff = abs((int)$result->diff($twoMonthsAgo)->days);
        $this->assertLessThanOrEqual(7, $diff);
    }

    // ----------------------------------------------------------------
    // getAdultUsers — DB使用
    // ----------------------------------------------------------------

    public function testGetAdultUsers_returnsArray(): void
    {
        $userGroupTable = TableRegistry::getTableLocator()->get('MUserGroup');
        $userTable      = TableRegistry::getTableLocator()->get('MUserInfo');

        $result = $this->service->getAdultUsers($userGroupTable, $userTable, 1);

        $this->assertIsArray($result);
    }

    public function testGetAdultUsers_returnsExpectedKeys(): void
    {
        $userGroupTable = TableRegistry::getTableLocator()->get('MUserGroup');
        $userTable      = TableRegistry::getTableLocator()->get('MUserInfo');

        $result = $this->service->getAdultUsers($userGroupTable, $userTable, 1);

        // フィクスチャのユーザーは i_id_staff が null なので staff_id 未設定ユーザーは除外され空配列が返る
        $this->assertSame([], $result);
    }

    public function testGetAdultUsers_nonExistentRoom_returnsEmpty(): void
    {
        $userGroupTable = TableRegistry::getTableLocator()->get('MUserGroup');
        $userTable      = TableRegistry::getTableLocator()->get('MUserInfo');

        $result = $this->service->getAdultUsers($userGroupTable, $userTable, 9999);

        $this->assertSame([], $result);
    }

    // ----------------------------------------------------------------
    // buildWeekGrid — DB使用
    // ----------------------------------------------------------------

    public function testBuildWeekGrid_returnsRequiredKeys(): void
    {
        $reservationTable = TableRegistry::getTableLocator()->get('TIndividualReservationInfo');
        $monday = (new \DateTimeImmutable('monday this week'))->format('Y-m-d');

        $result = $this->service->buildWeekGrid($reservationTable, [], $monday);

        $this->assertArrayHasKey('dates', $result);
        $this->assertArrayHasKey('meals', $result);
        $this->assertArrayHasKey('grid', $result);
        $this->assertArrayHasKey('versions', $result);
        $this->assertArrayHasKey('statuses', $result);
    }

    public function testBuildWeekGrid_emptyUsers_returnSevenDates(): void
    {
        $reservationTable = TableRegistry::getTableLocator()->get('TIndividualReservationInfo');
        $monday = (new \DateTimeImmutable('monday this week'))->format('Y-m-d');

        $result = $this->service->buildWeekGrid($reservationTable, [], $monday);

        $this->assertCount(7, $result['dates']);
        $this->assertSame([], $result['grid']);
    }

    public function testBuildWeekGrid_mealsHasThreeTypes(): void
    {
        $reservationTable = TableRegistry::getTableLocator()->get('TIndividualReservationInfo');
        $monday = (new \DateTimeImmutable('monday this week'))->format('Y-m-d');

        $result = $this->service->buildWeekGrid($reservationTable, [], $monday);

        $this->assertArrayHasKey(1, $result['meals']);
        $this->assertArrayHasKey(2, $result['meals']);
        $this->assertArrayHasKey(3, $result['meals']);
    }

    // ----------------------------------------------------------------
    // saveActualMeal — 無効な食事タイプのガード条件
    // ----------------------------------------------------------------

    public function testSaveActualMeal_invalidMealType_returnsError(): void
    {
        $reservationTable = TableRegistry::getTableLocator()->get('TIndividualReservationInfo');

        $result = $this->service->saveActualMeal(
            $reservationTable,
            userId: 1,
            roomId: 1,
            date: '2099-01-01',
            mealType: 4, // 4 は無効 (1,2,3 のみ有効)
            checked: true,
            expectedVersion: 1,
            actor: 'tester'
        );

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('無効な食事タイプ', $result['message']);
    }

    // ----------------------------------------------------------------
    // requestApproval — 空キーのガード条件
    // ----------------------------------------------------------------

    public function testRequestApproval_emptyKeys_returnsZero(): void
    {
        $reservationTable = TableRegistry::getTableLocator()->get('TIndividualReservationInfo');

        $result = $this->service->requestApproval($reservationTable, [], 'tester');

        $this->assertSame(0, $result);
    }

    // ----------------------------------------------------------------
    // 追加: 実データでの振る舞い
    // ----------------------------------------------------------------

    private function insertStaffUser(int $userId, string $name, ?string $staffId, int $roomId, int $activeFlag = 0, int $delFlag = 0): void
    {
        $db = \Cake\Datasource\ConnectionManager::get('test');
        $db->insert('m_user_info', [
            'i_id_user' => $userId, 'c_login_account' => 'acct' . $userId, 'c_login_passwd' => 'x',
            'c_user_name' => $name, 'i_admin' => 0, 'i_user_level' => 0, 'i_enable' => 0,
            'i_del_flag' => $delFlag, 'i_id_staff' => $staffId,
            'dt_create' => '2024-01-01 00:00:00', 'dt_update' => '2024-01-01 00:00:00',
        ]);
        $db->insert('m_user_group', [
            'i_id_user' => $userId, 'i_id_room' => $roomId, 'active_flag' => $activeFlag,
            'dt_create' => '2024-01-01 00:00:00', 'dt_update' => '2024-01-01 00:00:00',
        ]);
    }

    private function insertReservation(int $userId, string $date, int $type, int $eat, int $change, int $status = 0, int $version = 1, int $roomId = 1): void
    {
        \Cake\Datasource\ConnectionManager::get('test')->insert('t_individual_reservation_info', [
            'i_id_user' => $userId, 'd_reservation_date' => $date, 'i_reservation_type' => $type,
            'i_id_room' => $roomId, 'eat_flag' => $eat, 'i_change_flag' => $change,
            'i_approval_status' => $status, 'i_version' => $version,
            'dt_create' => '2024-01-01 00:00:00', 'c_create_user' => 't',
            'dt_update' => '2024-01-01 00:00:00', 'c_update_user' => 't',
        ]);
    }

    private function resTable(): \Cake\ORM\Table
    {
        return TableRegistry::getTableLocator()->get('TIndividualReservationInfo');
    }

    private function rowOf(int $userId, string $date, int $type): array
    {
        $row = \Cake\Datasource\ConnectionManager::get('test')->execute(
            'SELECT * FROM t_individual_reservation_info WHERE i_id_user = ? AND d_reservation_date = ? AND i_reservation_type = ?',
            [$userId, $date, $type]
        )->fetch('assoc');
        $this->assertIsArray($row);

        return $row;
    }

    public function testGetAdultUsers_returnsOnlyActiveStaffInRoomSortedByName(): void
    {
        $this->insertStaffUser(101, 'ブ職員', 'S2', 1);
        $this->insertStaffUser(102, 'ア職員', 'S1', 1);
        $this->insertStaffUser(103, '退出済み', 'S3', 1, 1);
        $this->insertStaffUser(104, '削除済み', 'S4', 1, 0, 1);
        $this->insertStaffUser(105, '空ID', '', 1);
        $this->insertStaffUser(106, '別部屋', 'S6', 2);

        $result = $this->service->getAdultUsers(
            TableRegistry::getTableLocator()->get('MUserGroup'),
            TableRegistry::getTableLocator()->get('MUserInfo'),
            1
        );

        $this->assertSame(
            [
                ['id' => 102, 'name' => 'ア職員', 'staff_id' => 'S1'],
                ['id' => 101, 'name' => 'ブ職員', 'staff_id' => 'S2'],
            ],
            $result
        );
    }

    public function testBuildWeekGrid_usesChangeFlagForPastAndEatFlagForFarFuture(): void
    {
        // 過去週(2000-01-03 月曜): i_change_flag が実効値。eat=1/change=0 → false
        $this->insertReservation(101, '2000-01-03', 1, 1, 0, 0, 5);
        $this->insertReservation(101, '2000-01-04', 2, 0, 1, 1, 3);
        $users = [['id' => 101, 'name' => 'a', 'staff_id' => 'S']];

        $past = $this->service->buildWeekGrid($this->resTable(), $users, '2000-01-03');

        $this->assertSame(['2000-01-03', '2000-01-04', '2000-01-05', '2000-01-06', '2000-01-07', '2000-01-08', '2000-01-09'], $past['dates']);
        $this->assertFalse($past['grid'][101]['2000-01-03'][1]);
        $this->assertSame(5, $past['versions'][101]['2000-01-03'][1]);
        $this->assertTrue($past['grid'][101]['2000-01-04'][2]);
        $this->assertSame(1, $past['statuses'][101]['2000-01-04'][2]);
        // 予約なしは false / version 1 / status 0
        $this->assertFalse($past['grid'][101]['2000-01-05'][3]);
        $this->assertSame(1, $past['versions'][101]['2000-01-05'][3]);
        $this->assertSame(0, $past['statuses'][101]['2000-01-05'][3]);

        // 遠い未来: eat_flag が実効値。eat=1/change=0 → true
        $this->insertReservation(101, '2099-01-05', 1, 1, 0);
        $future = $this->service->buildWeekGrid($this->resTable(), $users, '2099-01-05');
        $this->assertTrue($future['grid'][101]['2099-01-05'][1]);
    }

    public function testSaveActualMeal_createsNewRecordWhenMissing(): void
    {
        $result = $this->service->saveActualMeal($this->resTable(), 101, 1, '2000-01-03', 1, true, 1, 'tester');

        $this->assertTrue($result['ok']);
        $this->assertSame(1, $result['version']);
        $row = $this->rowOf(101, '2000-01-03', 1);
        $this->assertSame(1, (int)$row['eat_flag']);
        $this->assertSame(1, (int)$row['i_change_flag']);
        $this->assertSame(0, (int)$row['i_approval_status']);
    }

    public function testSaveActualMeal_updatesExistingWithOptimisticLock(): void
    {
        $this->insertReservation(101, '2000-01-03', 2, 1, 1, 0, 4);

        $result = $this->service->saveActualMeal($this->resTable(), 101, 1, '2000-01-03', 2, false, 4, 'tester', 'login1');

        $this->assertTrue($result['ok']);
        $this->assertSame(5, $result['version']);
        $row = $this->rowOf(101, '2000-01-03', 2);
        $this->assertSame(0, (int)$row['i_change_flag']);
        $this->assertSame(0, (int)$row['eat_flag']);
        $this->assertSame(5, (int)$row['i_version']);
        $this->assertSame('tester', $row['c_update_user']);
    }

    public function testSaveActualMeal_staleVersion_isRejectedWithoutChange(): void
    {
        $this->insertReservation(101, '2000-01-03', 2, 1, 1, 0, 4);

        $result = $this->service->saveActualMeal($this->resTable(), 101, 1, '2000-01-03', 2, false, 3, 'tester');

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('他のユーザーによって更新', $result['message']);
        $this->assertSame(1, (int)$this->rowOf(101, '2000-01-03', 2)['i_change_flag']);
    }

    public function testSaveActualMeal_approvedRecord_cannotBeChanged(): void
    {
        foreach ([1, 2] as $i => $status) {
            $this->insertReservation(101, '2000-01-0' . (3 + $i), 1, 1, 1, $status);
            $result = $this->service->saveActualMeal($this->resTable(), 101, 1, '2000-01-0' . (3 + $i), 1, false, 1, 'tester');

            $this->assertFalse($result['ok']);
            $this->assertStringContainsString('承認済み', $result['message']);
            $this->assertSame(1, (int)$this->rowOf(101, '2000-01-0' . (3 + $i), 1)['i_change_flag']);
        }
    }

    public function testSaveActualMeal_saveThrows_returnsReloadMessage(): void
    {
        $table = $this->getMockBuilder(\Cake\ORM\Table::class)
            ->onlyMethods(['save'])
            ->setConstructorArgs([['table' => 't_individual_reservation_info', 'alias' => 'TIndividualReservationInfo']])
            ->getMock();
        $table->method('save')->willThrowException(new \RuntimeException('duplicate'));

        $result = $this->service->saveActualMeal($table, 101, 1, '2000-01-03', 1, true, 1, 'tester');

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('再読み込み', $result['message']);
    }

    public function testSaveActualMeal_saveReturnsFalse_returnsFailure(): void
    {
        $table = $this->getMockBuilder(\Cake\ORM\Table::class)
            ->onlyMethods(['save'])
            ->setConstructorArgs([['table' => 't_individual_reservation_info', 'alias' => 'TIndividualReservationInfo']])
            ->getMock();
        $table->method('save')->willReturn(false);

        $result = $this->service->saveActualMeal($table, 101, 1, '2000-01-03', 1, true, 1, 'tester');

        $this->assertSame(['ok' => false, 'message' => '保存に失敗しました。'], $result);
    }

    public function testRequestApproval_resetsOnlyPendingOrRejected(): void
    {
        $this->insertReservation(101, '2000-01-03', 1, 1, 1, 3); // 差し戻し
        $this->insertReservation(101, '2000-01-03', 2, 1, 1, 1); // 承認済み
        $this->insertReservation(101, '2000-01-03', 3, 1, 1, 0); // 未承認
        $keys = [
            ['user_id' => 101, 'room_id' => 1, 'date' => '2000-01-03', 'meal_type' => 1],
            ['user_id' => 101, 'room_id' => 1, 'date' => '2000-01-03', 'meal_type' => 2],
            ['user_id' => 101, 'room_id' => 1, 'date' => '2000-01-03', 'meal_type' => 3],
        ];

        $affected = $this->service->requestApproval($this->resTable(), $keys, 'tester', 'login1');

        $this->assertSame(2, $affected);
        $this->assertSame(0, (int)$this->rowOf(101, '2000-01-03', 1)['i_approval_status']);
        $this->assertSame(1, (int)$this->rowOf(101, '2000-01-03', 2)['i_approval_status']);
    }

    public function testRequestApproval_noMatchingRows_returnsZero(): void
    {
        $keys = [['user_id' => 999, 'room_id' => 1, 'date' => '2000-01-03', 'meal_type' => 1]];

        $this->assertSame(0, $this->service->requestApproval($this->resTable(), $keys, 'tester'));
    }

    public function testNormalizeDateString_handlesVariousInputs(): void
    {
        $m = new \ReflectionMethod($this->service, 'normalizeDateString');
        $m->setAccessible(true);

        $this->assertSame('2026-03-02', $m->invoke($this->service, new \Cake\I18n\Date('2026-03-02')));
        $this->assertSame('2026-03-02', $m->invoke($this->service, new \DateTimeImmutable('2026-03-02 10:00:00')));
        $this->assertSame('2026-03-02', $m->invoke($this->service, '2026-03-02 10:00:00'));
        $this->assertNull($m->invoke($this->service, ''));
        $this->assertNull($m->invoke($this->service, 'not a date'));
        $this->assertNull($m->invoke($this->service, null));
    }

    /**
     * 管理者が遡れる最古の週は「2か月前の日を含む週の月曜」。月末でも翌月へ繰り上がらない（#919）
     */
    public function testGetAdminOldestAllowedMonday_doesNotOverflowOnMonthEnd(): void
    {
        $cases = [
            '2026-12-31' => '2026-10-26', // 2か月前 = 10/31(土) → その週の月曜
            '2026-04-30' => '2026-02-23', // 2か月前 = 2/28(土)。繰り上がると 3/2 の週になる
            '2026-11-02' => '2026-08-31', // 2か月前 = 9/2(水)
        ];
        try {
            foreach ($cases as $today => $expected) {
                DateTime::setTestNow(new DateTime($today . ' 09:00:00', 'Asia/Tokyo'));
                $monday = (new ActualMealManagementService())->getAdminOldestAllowedMonday();
                $this->assertSame($expected, $monday->format('Y-m-d'), $today);
            }
        } finally {
            DateTime::setTestNow(null);
        }
    }
}
