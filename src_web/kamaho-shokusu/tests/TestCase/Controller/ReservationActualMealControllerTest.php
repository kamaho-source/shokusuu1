<?php
declare(strict_types=1);

namespace App\Test\TestCase\Controller;

use Cake\Core\Configure;
use Cake\Datasource\ConnectionManager;
use Cake\TestSuite\IntegrationTestTrait;
use Cake\TestSuite\TestCase;

/**
 * ReservationActualMealController の統合テスト（実食管理・個人の実食入力・保存/承認申請API・食数グリッド）。
 *
 * ユーザー（権限は DB の値で判定されるため DB 側を更新する）:
 *   1 = 管理者(i_admin=1) / 2 = 一般職員(i_admin=0) / 3 = 子ども(i_user_level=1) / 4 = システム管理者(所属部屋なし)
 * ユーザー1,2 は大人(i_id_staff あり)。ユーザー1,2,3 はブロック1に所属。
 */
class ReservationActualMealControllerTest extends TestCase
{
    use IntegrationTestTrait;

    protected array $fixtures = [
        'app.MUserInfo',
        'app.MUserGroup',
        'app.MRoomInfo',
        'app.TIndividualReservationInfo',
        'app.TApprovalLog',
        'app.TAuditLog',
    ];

    private mixed $originalFlag = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->originalFlag = Configure::read('Features.actualMeal');
        Configure::write('Features.actualMeal', true);

        $db = ConnectionManager::get('test');
        $db->update('m_user_info', ['i_id_staff' => 101], ['i_id_user' => 1]);
        $db->update('m_user_info', ['i_id_staff' => 102], ['i_id_user' => 2]);
    }

    protected function tearDown(): void
    {
        Configure::write('Features.actualMeal', $this->originalFlag);
        parent::tearDown();
    }

    private function login(int $userId, int $iAdmin = 0): void
    {
        $accounts = [1 => 'admin_user', 2 => 'staff_user', 3 => 'non_staff_user', 4 => 'system_admin_user'];
        $db = ConnectionManager::get('test');
        $db->update('m_user_info', ['i_admin' => $iAdmin], ['i_id_user' => $userId]);
        $this->session(['Auth' => [
            'i_id_user' => $userId,
            'c_login_account' => $accounts[$userId],
            'c_user_name' => 'ユーザー' . $userId,
            'i_admin' => $iAdmin,
            'i_user_level' => $userId === 3 ? 1 : 0,
        ]]);
        $this->enableCsrfToken();
    }

    private function thisMonday(): string
    {
        return (new \DateTimeImmutable('now', new \DateTimeZone('Asia/Tokyo')))->modify('monday this week')->format('Y-m-d');
    }

    private function rowVersion(int $userId, string $date, int $room, int $type): ?int
    {
        $row = $this->getTableLocator()->get('TIndividualReservationInfo')->find()
            ->where(['i_id_user' => $userId, 'd_reservation_date' => $date, 'i_id_room' => $room, 'i_reservation_type' => $type])
            ->first();

        return $row === null ? null : (int)$row->i_version;
    }

    /**
     * @return array<string, mixed>
     */
    private function json(): array
    {
        return (array)json_decode((string)$this->_response->getBody(), true);
    }

    // ------------------------------------------------- actualMealManagement

    public function testManagement_admin_showsCurrentWeekWithRoomAdultsAndBackNavigation(): void
    {
        $this->login(1, 1);

        $this->get('/TReservationInfo/actual-meal-management');

        $this->assertResponseOk();
        $this->assertSame($this->thisMonday(), $this->viewVariable('weekMondayStr'));
        $this->assertSame(1, $this->viewVariable('selectedRoomId'));
        $this->assertSame([1, 2], array_column($this->viewVariable('adultUsers'), 'id'));
        $this->assertTrue($this->viewVariable('canGoPrev'), '管理者は過去へ遡れる');
        $this->assertTrue($this->viewVariable('canGoNext'));
    }

    public function testManagement_admin_withoutStaffId_isStillListedAsRoomMember(): void
    {
        ConnectionManager::get('test')->update('m_user_info', ['i_id_staff' => null], ['i_id_user' => 1]);
        $this->login(1, 1);

        $this->get('/TReservationInfo/actual-meal-management');

        $this->assertResponseOk();
        $ids = array_column($this->viewVariable('adultUsers'), 'id');
        $this->assertSame(1, $ids[0], '自分が先頭に追加される');
        $this->assertContains(2, $ids);
    }

    public function testManagement_weekParameter_isClampedToAllowedRange(): void
    {
        $this->login(1, 1);

        $this->get('/TReservationInfo/actual-meal-management?week=2000-01-05');
        $this->assertResponseOk();
        $oldest = (new \App\Service\ActualMealManagementService())->getAdminOldestAllowedMonday()->format('Y-m-d');
        $this->assertSame($oldest, $this->viewVariable('weekMondayStr'));
        $this->assertFalse($this->viewVariable('canGoPrev'));

        $this->get('/TReservationInfo/actual-meal-management?week=2099-01-05');
        $this->assertResponseOk();
        $future = (new \DateTimeImmutable($this->thisMonday()))->modify('+4 weeks')->format('Y-m-d');
        $this->assertSame($future, $this->viewVariable('weekMondayStr'));
        $this->assertFalse($this->viewVariable('canGoNext'));

        // 週の途中の日付は、その週の月曜に丸められる
        $wednesday = (new \DateTimeImmutable($this->thisMonday()))->modify('+2 days')->format('Y-m-d');
        $this->get('/TReservationInfo/actual-meal-management?week=' . $wednesday);
        $this->assertSame($this->thisMonday(), $this->viewVariable('weekMondayStr'));
    }

    public function testManagement_invalidWeek_fallsBackToCurrentWeek(): void
    {
        $this->login(1, 1);

        $this->get('/TReservationInfo/actual-meal-management?week=not-a-date');

        $this->assertResponseOk();
        $this->assertSame($this->thisMonday(), $this->viewVariable('weekMondayStr'));
    }

    public function testManagement_blockLeader_cannotGoBackBeforeThisWeek(): void
    {
        $this->login(2, 2);

        $this->get('/TReservationInfo/actual-meal-management?week=2000-01-05');

        $this->assertResponseOk();
        $this->assertSame($this->thisMonday(), $this->viewVariable('weekMondayStr'));
        $this->assertFalse($this->viewVariable('canGoPrev'));
    }

    public function testManagement_roomParameterOutsideOwnRooms_fallsBackToFirstRoom(): void
    {
        $this->login(1, 1);

        $this->get('/TReservationInfo/actual-meal-management?room_id=999');

        $this->assertSame(1, $this->viewVariable('selectedRoomId'));
    }

    public function testManagement_adminWithoutRooms_hasNoRoomSelected(): void
    {
        $this->login(4, 3);

        $this->get('/TReservationInfo/actual-meal-management');

        $this->assertResponseOk();
        $this->assertNull($this->viewVariable('selectedRoomId'));
        $this->assertSame([], $this->viewVariable('adultUsers'));
    }

    public function testManagement_generalStaff_isForbidden(): void
    {
        $this->login(2, 0);
        $this->get('/TReservationInfo/actual-meal-management');
        $this->assertResponseCode(403);
    }

    // ------------------------------------------------------- myActualMeal

    public function testMyActualMeal_staff_seesOnlyOwnRow(): void
    {
        $this->login(2, 0);

        $this->get('/TReservationInfo/my-actual-meal');

        $this->assertResponseOk();
        $this->assertSame(2, $this->viewVariable('selectedUserId'));
        $this->assertSame([2], array_column($this->viewVariable('targetUsers'), 'id'));
        $this->assertSame([1 => 'Lorem ipsum dolor sit amet'], $this->viewVariable('rooms'));
        $this->assertFalse($this->viewVariable('canProxyActualMeal'));
        $this->assertFalse($this->viewVariable('canGoPrev'));
        $this->assertNull($this->viewVariable('rejectionBanner'));
    }

    public function testMyActualMeal_staffCannotSwitchToAnotherUser(): void
    {
        $this->login(2, 0);

        $this->get('/TReservationInfo/my-actual-meal?user_id=1');

        $this->assertSame(2, $this->viewVariable('selectedUserId'));
    }

    public function testMyActualMeal_admin_canProxyAndSwitchUsersInRoom(): void
    {
        $this->login(1, 1);

        $this->get('/TReservationInfo/my-actual-meal?user_id=2&room_id=1');

        $this->assertResponseOk();
        $this->assertTrue($this->viewVariable('canProxyActualMeal'));
        $this->assertSame([1, 2], array_column($this->viewVariable('targetUsers'), 'id'));
        $this->assertSame(2, $this->viewVariable('selectedUserId'));
        $this->assertSame(2, $this->viewVariable('selectedTargetUser')['id']);

        $this->get('/TReservationInfo/my-actual-meal?user_id=999');
        $this->assertSame(1, $this->viewVariable('selectedUserId'), '部屋にいない利用者は選べず先頭に戻る');
    }

    public function testMyActualMeal_adminWithoutStaffId_isAddedAsRoomMember(): void
    {
        ConnectionManager::get('test')->update('m_user_info', ['i_id_staff' => null], ['i_id_user' => 1]);
        $this->login(1, 1);

        $this->get('/TReservationInfo/my-actual-meal');

        $ids = array_column($this->viewVariable('targetUsers'), 'id');
        $this->assertSame(1, $ids[0]);
        $this->assertContains(2, $ids);
    }

    public function testMyActualMeal_adminCanBrowseBackAndWeekIsClamped(): void
    {
        $this->login(1, 1);

        $this->get('/TReservationInfo/my-actual-meal?week=2000-01-05');
        $oldest = (new \App\Service\ActualMealManagementService())->getAdminOldestAllowedMonday()->format('Y-m-d');
        $this->assertSame($oldest, $this->viewVariable('weekMondayStr'));

        $this->get('/TReservationInfo/my-actual-meal?week=2099-01-05');
        $this->assertSame((new \DateTimeImmutable($this->thisMonday()))->modify('+4 weeks')->format('Y-m-d'), $this->viewVariable('weekMondayStr'));

        $this->get('/TReservationInfo/my-actual-meal?week=garbage');
        $this->assertSame($this->thisMonday(), $this->viewVariable('weekMondayStr'));
    }

    public function testMyActualMeal_nonAdminPastWeek_isMovedToThisWeek(): void
    {
        $this->login(2, 0);

        $this->get('/TReservationInfo/my-actual-meal?week=2000-01-05');

        $this->assertSame($this->thisMonday(), $this->viewVariable('weekMondayStr'));
    }

    public function testMyActualMeal_unknownRoom_fallsBackToOwnRoom(): void
    {
        $this->login(2, 0);

        $this->get('/TReservationInfo/my-actual-meal?room_id=999');

        $this->assertSame(1, $this->viewVariable('selectedRoomId'));
    }

    public function testMyActualMeal_userWithoutRooms_hasNoRoom(): void
    {
        $this->login(4, 0);

        $this->get('/TReservationInfo/my-actual-meal');

        $this->assertResponseOk();
        $this->assertNull($this->viewVariable('selectedRoomId'));
        $this->assertSame([], $this->viewVariable('rooms'));
    }

    public function testMyActualMeal_showsLatestRejectionOfTheWeek(): void
    {
        $monday = $this->thisMonday();
        $db = ConnectionManager::get('test');
        $db->insert('t_approval_log', [
            'i_id_user' => 2, 'd_reservation_date' => $monday, 'i_id_room' => 1, 'i_reservation_type' => 1,
            'i_approval_status' => 3, 'i_approver_id' => 1, 'c_reject_reason' => '古い理由', 'dt_create' => '2026-01-01 10:00:00',
        ]);
        $db->insert('t_approval_log', [
            'i_id_user' => 2, 'd_reservation_date' => $monday, 'i_id_room' => 1, 'i_reservation_type' => 2,
            'i_approval_status' => 3, 'i_approver_id' => 1, 'c_reject_reason' => '最新の理由', 'dt_create' => '2026-01-02 10:00:00',
        ]);
        $db->insert('t_approval_log', [
            'i_id_user' => 3, 'd_reservation_date' => $monday, 'i_id_room' => 1, 'i_reservation_type' => 2,
            'i_approval_status' => 3, 'i_approver_id' => 1, 'c_reject_reason' => '他人の理由', 'dt_create' => '2026-01-03 10:00:00',
        ]);
        $this->login(2, 0);

        $this->get('/TReservationInfo/my-actual-meal');

        $banner = $this->viewVariable('rejectionBanner');
        $this->assertSame('最新の理由', $banner['reason']);
        $this->assertSame('管理者ユーザー', $banner['approver']);
        $this->assertSame('2026-01-02 10:00', $banner['date']);
    }

    // ------------------------------------------------------ actualMealSave

    private function save(array $data): void
    {
        $this->post('/TReservationInfo/actual-meal-save', $data);
    }

    public function testSave_invalidDateFormat_returns400(): void
    {
        $this->login(1, 1);

        $this->save(['room_id' => 1, 'date' => '2024/09/07', 'meal_type' => 1, 'user_id' => 1, 'checked' => 1]);

        $this->assertResponseCode(400);
        $this->assertSame('日付の形式が正しくありません。', $this->json()['message']);
    }

    public function testSave_staffForAnotherUser_isForbidden(): void
    {
        $this->login(2, 0);

        $this->save(['room_id' => 1, 'date' => '2024-09-07', 'meal_type' => 1, 'user_id' => 1, 'checked' => 0, 'version' => 1]);

        $this->assertResponseCode(403);
        $this->assertSame(1, $this->rowVersion(1, '2024-09-07', 1, 1), '他人の実食は変更されない');
    }

    public function testSave_updatesExistingRowWithOptimisticLock(): void
    {
        $this->login(1, 1);

        $this->save(['room_id' => 1, 'date' => '2024-09-07', 'meal_type' => 1, 'user_id' => 1, 'checked' => 0, 'version' => 1]);
        $this->assertResponseOk();
        $this->assertSame(2, $this->json()['data']['version']);
        $this->assertSame(2, $this->rowVersion(1, '2024-09-07', 1, 1));

        // 古いバージョンのまま再送すると競合として拒否される
        $this->save(['room_id' => 1, 'date' => '2024-09-07', 'meal_type' => 1, 'user_id' => 1, 'checked' => 1, 'version' => 1]);
        $this->assertResponseCode(409);
        $this->assertSame(2, $this->rowVersion(1, '2024-09-07', 1, 1));
    }

    public function testSave_userWhoLeftTheRoom_canOnlyUpdateExistingRows(): void
    {
        // 子ども(user 3)が部屋1を退出済み。既存の行の更新はできるが、新しい行は作れない
        ConnectionManager::get('test')->update('m_user_group', ['active_flag' => 1], ['i_id_user' => 3, 'i_id_room' => 1]);
        $table = $this->getTableLocator()->get('TIndividualReservationInfo');
        $table->saveOrFail($table->newEntity([
            'i_id_user' => 3, 'd_reservation_date' => '2024-09-07', 'i_id_room' => 1, 'i_reservation_type' => 2, 'eat_flag' => 1,
            'dt_create' => '2024-09-01 00:00:00', 'c_create_user' => 't', 'dt_update' => '2024-09-01 00:00:00', 'c_update_user' => 't',
        ]));
        $this->login(1, 1);

        $this->save(['room_id' => 1, 'date' => '2024-09-07', 'meal_type' => 3, 'user_id' => 3, 'checked' => 1, 'version' => 1]);
        $this->assertResponseCode(403);
        $this->assertNull($this->rowVersion(3, '2024-09-07', 1, 3));

        $this->save(['room_id' => 1, 'date' => '2024-09-07', 'meal_type' => 2, 'user_id' => 3, 'checked' => 0, 'version' => 1]);
        $this->assertResponseOk();
        $this->assertSame(2, $this->rowVersion(3, '2024-09-07', 1, 2));
    }

    public function testSave_approvedRow_cannotBeChanged(): void
    {
        $this->getTableLocator()->get('TIndividualReservationInfo')
            ->updateAll(['i_approval_status' => 2], ['i_id_user' => 1, 'd_reservation_date' => '2024-09-07']);
        $this->login(1, 1);

        $this->save(['room_id' => 1, 'date' => '2024-09-07', 'meal_type' => 1, 'user_id' => 1, 'checked' => 0, 'version' => 1]);

        $this->assertResponseCode(409);
        $this->assertSame('承認済みのデータは変更できません。', $this->json()['message']);
    }

    public function testSave_invalidMealType_returns409(): void
    {
        $this->login(1, 1);

        $this->save(['room_id' => 1, 'date' => '2024-09-08', 'meal_type' => 4, 'user_id' => 1, 'checked' => 1, 'version' => 1]);

        $this->assertResponseCode(409);
        $this->assertSame('無効な食事タイプです。', $this->json()['message']);
    }

    // ------------------------------------------- actualMealRequestApproval

    private function requestApproval(array $data): void
    {
        $this->post('/TReservationInfo/actual-meal-request-approval', $data);
    }

    private function key(int $userId, string $date = '2024-09-07', int $room = 1, int $meal = 1): array
    {
        return ['user_id' => $userId, 'room_id' => $room, 'date' => $date, 'meal_type' => $meal];
    }

    public function testRequestApproval_resetsRejectedRowToPending(): void
    {
        $table = $this->getTableLocator()->get('TIndividualReservationInfo');
        $table->updateAll(['i_approval_status' => 3], ['i_id_user' => 1, 'd_reservation_date' => '2024-09-07']);
        $this->login(1, 1);

        // 同じ部屋・対象者の組が複数あっても認可は1回で済み、全て申請される
        $this->requestApproval(['keys' => [$this->key(1), $this->key(1, '2024-09-08')]]);

        $this->assertResponseOk();
        $this->assertSame(1, $this->json()['data']['count']);
        $this->assertSame(0, (int)$table->find()->where(['i_id_user' => 1, 'd_reservation_date' => '2024-09-07'])->firstOrFail()->i_approval_status);
    }

    public function testRequestApproval_approvedRowIsNotReopened(): void
    {
        $table = $this->getTableLocator()->get('TIndividualReservationInfo');
        $table->updateAll(['i_approval_status' => 2], ['i_id_user' => 1, 'd_reservation_date' => '2024-09-07']);
        $this->login(1, 1);

        $this->requestApproval(['keys' => [$this->key(1)]]);

        $this->assertResponseOk();
        $this->assertSame(0, $this->json()['data']['count']);
        $this->assertSame(2, (int)$table->find()->where(['i_id_user' => 1])->firstOrFail()->i_approval_status);
    }

    public function testRequestApproval_emptyKeys_returns400(): void
    {
        $this->login(1, 1);
        $this->requestApproval(['keys' => []]);
        $this->assertResponseCode(400);
        $this->assertSame('申請対象が指定されていません。', $this->json()['message']);
    }

    public function testRequestApproval_malformedKey_returns400(): void
    {
        $this->login(1, 1);
        $this->requestApproval(['keys' => [['user_id' => 1, 'room_id' => 1, 'date' => 'bad', 'meal_type' => 1]]]);
        $this->assertResponseCode(400);
        $this->assertSame('申請対象の形式が正しくありません。', $this->json()['message']);
    }

    public function testRequestApproval_roomOutsideOwnRooms_isForbidden(): void
    {
        $this->login(1, 1);
        $this->requestApproval(['keys' => [$this->key(1, '2024-09-07', 999)]]);
        $this->assertResponseCode(403);
    }

    public function testMealCountGrid_unknownMode_fallsBackToIndividual(): void
    {
        $this->login(2, 0);

        $this->get('/TReservationInfo/meal-count-grid?mode=bogus');

        $this->assertResponseOk();
        $this->assertSame('individual', $this->viewVariable('viewMode'));
        $this->assertSame([2 => '職員ユーザー'], $this->viewVariable('nameList'));
    }

    public function testMealCountGrid_staffAllMode_showsAllOwnRooms(): void
    {
        $this->login(2, 0);

        $this->get('/TReservationInfo/meal-count-grid?mode=all');

        $this->assertResponseOk();
        $this->assertSame('all', $this->viewVariable('viewMode'));
        $this->assertSame([1], array_keys($this->viewVariable('allRooms')));
    }

    public function testMealCountGrid_child_isLimitedToIndividualMode(): void
    {
        $this->login(3, 0);

        $this->get('/TReservationInfo/meal-count-grid?mode=all');

        $this->assertResponseOk();
        $this->assertSame('individual', $this->viewVariable('viewMode'));
        $this->assertFalse($this->viewVariable('canViewRoom'));
    }

    public function testMealCountGrid_roomOutsideAllowedRooms_fallsBackToFirstRoom(): void
    {
        $this->login(2, 0);

        $this->get('/TReservationInfo/meal-count-grid?mode=room&room_id=999');

        $this->assertResponseOk();
        $this->assertSame(1, $this->viewVariable('selectedRoomId'));
    }

    public function testMealCountGrid_adminSelectingDeletedUser_fallsBackToSelf(): void
    {
        $this->login(1, 1);

        $this->get('/TReservationInfo/meal-count-grid?user_id=6');

        $this->assertResponseOk();
        $this->assertSame(1, $this->viewVariable('selectedUserId'));
        $this->assertArrayNotHasKey(6, $this->viewVariable('nameList'));
    }

    public function testMealCountGrid_roomModeWithoutRooms_hasEmptyGrid(): void
    {
        $this->login(4, 0);

        $this->get('/TReservationInfo/meal-count-grid?mode=room');

        $this->assertResponseOk();
        $this->assertNull($this->viewVariable('selectedRoomId'));
        $this->assertSame([], $this->viewVariable('allRooms'));
        $this->assertSame([], $this->viewVariable('gridData')['grid'] ?? []);
    }
}
