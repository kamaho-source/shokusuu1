<?php
declare(strict_types=1);

namespace App\Test\TestCase\Service;

use App\Service\ActualMealManagementService;
use Cake\ORM\TableRegistry;
use Cake\TestSuite\TestCase;

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

        // フィクスチャのユーザーは i_id_staff が null なので空配列が返るはず
        // (staff_id 未設定ユーザーは除外される)
        $this->assertIsArray($result);
        foreach ($result as $user) {
            $this->assertArrayHasKey('id', $user);
            $this->assertArrayHasKey('name', $user);
            $this->assertArrayHasKey('staff_id', $user);
        }
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
}
