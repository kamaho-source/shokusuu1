<?php
declare(strict_types=1);

namespace App\Test\TestCase\Service;

use App\Service\MealCountGridService;
use Cake\TestSuite\TestCase;

/**
 * MealCountGridService テスト
 *
 * 対象メソッド:
 *   - buildDateRange()          — 純粋ロジック
 *   - resolveWeekNavigation()   — 純粋ロジック
 *   - getAdminOldestAllowedMonday() — 純粋ロジック
 *   - buildGrid()               — TIndividualReservationInfo・MUserGroup テーブルが必要
 *   - getRoomUsers()            — MUserGroup テーブルが必要
 */
class MealCountGridServiceTest extends TestCase
{
    protected array $fixtures = [
        'app.MUserGroup',
        'app.MUserInfo',
        'app.TIndividualReservationInfo',
    ];

    private MealCountGridService $service;

    /** テスト用の固定月曜日（2026-05-11 は月曜） */
    private \DateTimeImmutable $thisMonday;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service    = new MealCountGridService();
        $this->thisMonday = new \DateTimeImmutable('2026-05-11', new \DateTimeZone('Asia/Tokyo'));
    }

    /* =====================================================================
     * MEALS 定数
     * ===================================================================== */

    public function testMealsConstantContainsFourTypes(): void
    {
        $this->assertCount(4, MealCountGridService::MEALS);
        $this->assertSame('朝', MealCountGridService::MEALS[1]);
        $this->assertSame('昼', MealCountGridService::MEALS[2]);
        $this->assertSame('夕', MealCountGridService::MEALS[3]);
        $this->assertSame('弁', MealCountGridService::MEALS[4]);
    }

    /* =====================================================================
     * buildDateRange
     * ===================================================================== */

    public function testBuildDateRangeDefaultsTwentyEightDays(): void
    {
        $dates = $this->service->buildDateRange('2026-05-11');

        $this->assertCount(28, $dates);
    }

    public function testBuildDateRangeCanReturnSevenDays(): void
    {
        $dates = $this->service->buildDateRange('2026-05-11', 7);

        $this->assertCount(7, $dates);
        $this->assertSame('2026-05-11', $dates[0]);
        $this->assertSame('2026-05-17', $dates[6]);
    }

    public function testBuildDateRangeTwentyEightDaysEndsOnSunday(): void
    {
        $dates = $this->service->buildDateRange('2026-05-11');

        $this->assertSame('2026-05-11', $dates[0]);
        $this->assertSame('2026-06-07', $dates[27]);
    }

    public function testBuildDateRangeReturnsConsecutiveDays(): void
    {
        $dates = $this->service->buildDateRange('2026-05-11', 7);

        for ($i = 0; $i < 6; $i++) {
            $current = new \DateTimeImmutable($dates[$i]);
            $next    = new \DateTimeImmutable($dates[$i + 1]);
            $diff    = (int)$current->diff($next)->days;
            $this->assertSame(1, $diff, "インデックス {$i} と " . ($i + 1) . " は連続していない");
        }
    }

    /* =====================================================================
     * buildPeriodLabel
     * ===================================================================== */

    public function testBuildPeriodLabelReturnsCorrectFormat(): void
    {
        $dates = $this->service->buildDateRange('2026-05-11');

        $label = $this->service->buildPeriodLabel($dates);

        $this->assertStringContainsString('2026/05/11', $label);
        $this->assertStringContainsString('2026/06/07', $label);
        $this->assertStringContainsString('28日', $label);
    }

    public function testBuildPeriodLabelReturnsEmptyForEmptyDates(): void
    {
        $label = $this->service->buildPeriodLabel([]);

        $this->assertSame('', $label);
    }

    /* =====================================================================
     * buildMonthlyTotals
     * ===================================================================== */

    public function testBuildMonthlyTotalsReturnsAllMealTypes(): void
    {
        $gridData = [
            'dailyTotals' => [
                '2026-05-11' => [1 => 2, 2 => 1, 3 => 0, 4 => 0],
                '2026-05-12' => [1 => 1, 2 => 0, 3 => 1, 4 => 0],
            ],
        ];

        $totals = $this->service->buildMonthlyTotals($gridData);

        $this->assertSame(3, $totals[1]); // 朝: 2+1
        $this->assertSame(1, $totals[2]); // 昼: 1+0
        $this->assertSame(1, $totals[3]); // 夕: 0+1
        $this->assertSame(0, $totals[4]); // 弁: 0+0
    }

    public function testBuildMonthlyTotalsReturnsZeroForEmptyGrid(): void
    {
        $gridData = ['dailyTotals' => []];

        $totals = $this->service->buildMonthlyTotals($gridData);

        foreach (array_keys(\App\Service\MealCountGridService::MEALS) as $mealType) {
            $this->assertSame(0, $totals[$mealType]);
        }
    }

    /* =====================================================================
     * getAdminOldestAllowedMonday
     * ===================================================================== */

    public function testGetAdminOldestAllowedMondayReturnsMonday(): void
    {
        $oldest = $this->service->getAdminOldestAllowedMonday($this->thisMonday);

        $this->assertSame('1', $oldest->format('N'), '最古週が月曜日でない');
    }

    public function testGetAdminOldestAllowedMondayIsTwoMonthsBack(): void
    {
        $oldest = $this->service->getAdminOldestAllowedMonday($this->thisMonday);

        $this->assertLessThan($this->thisMonday, $oldest);
        // 2ヶ月以上前であること
        $diff = $this->thisMonday->diff($oldest);
        $this->assertGreaterThanOrEqual(1, $diff->m + ($diff->y * 12));
    }

    /* =====================================================================
     * resolveWeekNavigation
     * ===================================================================== */

    public function testResolveWeekNavigationReturnsCurrentWeekWhenNoParam(): void
    {
        $result = $this->service->resolveWeekNavigation(null, false, $this->thisMonday);

        $this->assertSame($this->thisMonday->format('Y-m-d'), $result['weekMondayStr']);
    }

    public function testResolveWeekNavigationNormalizesWednesdayToMonday(): void
    {
        $wednesday = $this->thisMonday->modify('+2 days');

        $result = $this->service->resolveWeekNavigation($wednesday->format('Y-m-d'), false, $this->thisMonday);

        $this->assertSame($this->thisMonday->format('Y-m-d'), $result['weekMondayStr']);
    }

    public function testResolveWeekNavigationNonAdminCannotGoBeforeCurrentWeek(): void
    {
        $lastWeek = $this->thisMonday->modify('-7 days');

        $result = $this->service->resolveWeekNavigation($lastWeek->format('Y-m-d'), false, $this->thisMonday);

        $this->assertSame($this->thisMonday->format('Y-m-d'), $result['weekMondayStr']);
    }

    public function testResolveWeekNavigationAdminCanReachOldestAllowedMonday(): void
    {
        $oldest = $this->service->getAdminOldestAllowedMonday($this->thisMonday);

        $result = $this->service->resolveWeekNavigation($oldest->format('Y-m-d'), true, $this->thisMonday);

        $this->assertSame($oldest->format('Y-m-d'), $result['weekMondayStr']);
    }

    public function testResolveWeekNavigationClampsFarFutureToFourWeeks(): void
    {
        $farFuture   = $this->thisMonday->modify('+10 weeks');
        $expectedMax = $this->thisMonday->modify('+4 weeks');

        $result = $this->service->resolveWeekNavigation($farFuture->format('Y-m-d'), false, $this->thisMonday);

        $this->assertSame($expectedMax->format('Y-m-d'), $result['weekMondayStr']);
    }

    public function testResolveWeekNavigationCanGoNextFalseAtFutureLimit(): void
    {
        $fourWeeksOut = $this->thisMonday->modify('+4 weeks');

        $result = $this->service->resolveWeekNavigation($fourWeeksOut->format('Y-m-d'), false, $this->thisMonday);

        $this->assertFalse($result['canGoNext']);
    }

    public function testResolveWeekNavigationHandlesInvalidStringGracefully(): void
    {
        $result = $this->service->resolveWeekNavigation('not-a-date', false, $this->thisMonday);

        $this->assertSame($this->thisMonday->format('Y-m-d'), $result['weekMondayStr']);
    }

    public function testResolveWeekNavigationPrevAndNextAreSurroundingWeeks(): void
    {
        $result = $this->service->resolveWeekNavigation(null, false, $this->thisMonday);

        $this->assertSame(
            $this->thisMonday->modify('-7 days')->format('Y-m-d'),
            $result['prevMonday']->format('Y-m-d')
        );
        $this->assertSame(
            $this->thisMonday->modify('+7 days')->format('Y-m-d'),
            $result['nextMonday']->format('Y-m-d')
        );
    }

    /* =====================================================================
     * buildGrid
     * ===================================================================== */

    public function testBuildGridReturnsExpectedStructure(): void
    {
        $reservationTable = $this->getTableLocator()->get('TIndividualReservationInfo');
        $dates = $this->service->buildDateRange('2026-05-11');

        $result = $this->service->buildGrid($reservationTable, [], [], $dates);

        $this->assertArrayHasKey('dates', $result);
        $this->assertArrayHasKey('meals', $result);
        $this->assertArrayHasKey('rooms', $result);
        $this->assertArrayHasKey('dailyTotals', $result);
    }

    public function testBuildGridWithNoRoomsReturnsEmptyRooms(): void
    {
        $reservationTable = $this->getTableLocator()->get('TIndividualReservationInfo');
        $dates = $this->service->buildDateRange('2026-05-11');

        $result = $this->service->buildGrid($reservationTable, [], [], $dates);

        $this->assertEmpty($result['rooms']);
    }

    public function testBuildGridDailyTotalsInitializedForAllDatesAndMeals(): void
    {
        $reservationTable = $this->getTableLocator()->get('TIndividualReservationInfo');
        $dates = $this->service->buildDateRange('2026-05-11');

        $result = $this->service->buildGrid($reservationTable, [], [], $dates);

        foreach ($dates as $d) {
            $this->assertArrayHasKey($d, $result['dailyTotals']);
            foreach (array_keys(MealCountGridService::MEALS) as $mealType) {
                $this->assertArrayHasKey($mealType, $result['dailyTotals'][$d]);
            }
        }
    }

    public function testBuildGridReturnsDatesPassed(): void
    {
        $reservationTable = $this->getTableLocator()->get('TIndividualReservationInfo');
        $dates = $this->service->buildDateRange('2026-05-11');

        $result = $this->service->buildGrid($reservationTable, [], [], $dates);

        $this->assertSame($dates, $result['dates']);
    }

    public function testBuildGridReturnsMealConstants(): void
    {
        $reservationTable = $this->getTableLocator()->get('TIndividualReservationInfo');
        $dates = $this->service->buildDateRange('2026-05-11');

        $result = $this->service->buildGrid($reservationTable, [], [], $dates);

        $this->assertSame(MealCountGridService::MEALS, $result['meals']);
    }

    /* =====================================================================
     * getRoomUsers
     * ===================================================================== */

    public function testGetRoomUsersReturnsUsersInRoom(): void
    {
        $userGroupTable = $this->getTableLocator()->get('MUserGroup');
        $userInfoTable  = $this->getTableLocator()->get('MUserInfo');

        // i_del_flag=0 のアクティブユーザーを追加
        $userInfoTable->save($userInfoTable->newEntity([
            'i_id_user'       => 2,
            'c_login_account' => 'active_user',
            'c_login_passwd'  => 'pass',
            'c_user_name'     => 'ActiveUser',
            'i_admin'         => 0,
            'i_disp_no'       => 2,
            'i_enable'        => 1,
            'i_del_flag'      => 0,
            'dt_create'       => '2024-01-01 00:00:00',
            'c_create_user'   => 'test',
            'dt_update'       => '2024-01-01 00:00:00',
            'c_update_user'   => 'test',
        ]));
        $userGroupTable->save($userGroupTable->newEntity([
            'i_id_user'     => 2,
            'i_id_room'     => 1,
            'active_flag'   => 0,
            'dt_create'     => '2024-01-01 00:00:00',
            'c_create_user' => 'test',
            'dt_update'     => '2024-01-01 00:00:00',
            'c_update_user' => 'test',
        ]));

        $result = $this->service->getRoomUsers($userGroupTable, $userInfoTable, 1);

        $userIds = array_column($result, 'id');
        $this->assertContains(2, $userIds);
    }

    public function testGetRoomUsersExcludesDeletedUsers(): void
    {
        $userGroupTable = $this->getTableLocator()->get('MUserGroup');
        $userInfoTable  = $this->getTableLocator()->get('MUserInfo');

        // フィクスチャのユーザー6は i_del_flag=1（削除済み）なので結果に含まれない
        $result = $this->service->getRoomUsers($userGroupTable, $userInfoTable, 1);

        $userIds = array_column($result, 'id');
        $this->assertNotContains(6, $userIds);
    }

    public function testGetRoomUsersReturnsEmptyForUnknownRoom(): void
    {
        $userGroupTable = $this->getTableLocator()->get('MUserGroup');
        $userInfoTable  = $this->getTableLocator()->get('MUserInfo');

        $result = $this->service->getRoomUsers($userGroupTable, $userInfoTable, 9999);

        $this->assertEmpty($result);
    }

    public function testGetRoomUsersResultHasIdAndNameKeys(): void
    {
        $userGroupTable = $this->getTableLocator()->get('MUserGroup');
        $userInfoTable  = $this->getTableLocator()->get('MUserInfo');

        // アクティブユーザーを追加
        $userInfoTable->save($userInfoTable->newEntity([
            'i_id_user'       => 3,
            'c_login_account' => 'user3',
            'c_login_passwd'  => 'pass',
            'c_user_name'     => 'User Three',
            'i_admin'         => 0,
            'i_disp_no'       => 3,
            'i_enable'        => 1,
            'i_del_flag'      => 0,
            'dt_create'       => '2024-01-01 00:00:00',
            'c_create_user'   => 'test',
            'dt_update'       => '2024-01-01 00:00:00',
            'c_update_user'   => 'test',
        ]));
        $userGroupTable->save($userGroupTable->newEntity([
            'i_id_user'     => 3,
            'i_id_room'     => 1,
            'active_flag'   => 0,
            'dt_create'     => '2024-01-01 00:00:00',
            'c_create_user' => 'test',
            'dt_update'     => '2024-01-01 00:00:00',
            'c_update_user' => 'test',
        ]));

        $result = $this->service->getRoomUsers($userGroupTable, $userInfoTable, 1);

        $this->assertNotEmpty($result);
        $this->assertArrayHasKey('id', $result[0]);
        $this->assertArrayHasKey('name', $result[0]);
    }
}
