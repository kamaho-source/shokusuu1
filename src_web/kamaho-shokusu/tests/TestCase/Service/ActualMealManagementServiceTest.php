<?php
declare(strict_types=1);

namespace App\Test\TestCase\Service;

use App\Service\ActualMealManagementService;
use Cake\TestSuite\TestCase;

/**
 * ActualMealManagementService テスト
 *
 * 対象メソッド:
 *   - resolveWeekNavigation() — DB 不要、純粋ロジック
 *   - ensureUserInList()      — MUserGroup テーブルが必要
 *   - userBelongsToRoom()     — MUserGroup テーブルが必要
 */
class ActualMealManagementServiceTest extends TestCase
{
    protected array $fixtures = [
        'app.MUserGroup',
    ];

    private ActualMealManagementService $service;

    /** テスト用の固定月曜日（2026-05-11 は月曜） */
    private \DateTimeImmutable $thisMonday;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service    = new ActualMealManagementService();
        $this->thisMonday = new \DateTimeImmutable('2026-05-11', new \DateTimeZone('Asia/Tokyo'));
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
        $wednesday = $this->thisMonday->modify('+2 days'); // 水曜

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
        $oldest = $this->service->getAdminOldestAllowedMonday();

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

    public function testResolveWeekNavigationCanGoPrevFalseForNonAdmin(): void
    {
        $result = $this->service->resolveWeekNavigation(null, false, $this->thisMonday);

        $this->assertFalse($result['canGoPrev']);
    }

    public function testResolveWeekNavigationCanGoPrevTrueWhenAdminNotAtOldest(): void
    {
        // 管理者・今週選択 → 前週に戻れるはず
        $result = $this->service->resolveWeekNavigation(null, true, $this->thisMonday);

        $this->assertTrue($result['canGoPrev']);
    }

    public function testResolveWeekNavigationCanGoNextFalseAtFutureLimit(): void
    {
        $fourWeeksOut = $this->thisMonday->modify('+4 weeks');

        $result = $this->service->resolveWeekNavigation($fourWeeksOut->format('Y-m-d'), false, $this->thisMonday);

        $this->assertFalse($result['canGoNext']);
    }

    public function testResolveWeekNavigationHandlesInvalidStringGracefully(): void
    {
        // 不正な日付文字列は今週にフォールバック
        $result = $this->service->resolveWeekNavigation('not-a-date', false, $this->thisMonday);

        $this->assertSame($this->thisMonday->format('Y-m-d'), $result['weekMondayStr']);
    }

    /* =====================================================================
     * ensureUserInList
     * ===================================================================== */

    public function testEnsureUserInListDoesNothingIfUserAlreadyPresent(): void
    {
        $userGroupTable = $this->getTableLocator()->get('MUserGroup');
        $users = [['id' => 1, 'name' => 'Alice', 'staff_id' => 'S001']];

        $result = $this->service->ensureUserInList($users, 1, 'Alice', 'S001', 1, false, $userGroupTable);

        $this->assertCount(1, $result);
        $this->assertSame(1, $result[0]['id']);
    }

    public function testEnsureUserInListPrependsAdminWithoutRoomBelongingCheck(): void
    {
        // isAdmin=true → DB チェック不要、常に先頭へ追加
        $userGroupTable = $this->getTableLocator()->get('MUserGroup');
        $users = [['id' => 2, 'name' => 'Bob', 'staff_id' => 'S002']];

        $result = $this->service->ensureUserInList($users, 1, 'Alice', 'S001', 1, true, $userGroupTable);

        $this->assertCount(2, $result);
        $this->assertSame(1, $result[0]['id']); // 先頭に追加
    }

    public function testEnsureUserInListPrependsNonAdminWhoIsInRoom(): void
    {
        // フィクスチャ: ユーザー1 は部屋1 に所属 (active_flag=0)
        $userGroupTable = $this->getTableLocator()->get('MUserGroup');
        $users = [['id' => 2, 'name' => 'Bob', 'staff_id' => 'S002']];

        $result = $this->service->ensureUserInList($users, 1, 'Alice', 'S001', 1, false, $userGroupTable);

        $this->assertCount(2, $result);
        $this->assertSame(1, $result[0]['id']);
    }

    public function testEnsureUserInListDoesNotAddNonAdminNotInRoom(): void
    {
        // ユーザー1 は部屋99 に所属していない
        $userGroupTable = $this->getTableLocator()->get('MUserGroup');
        $users = [['id' => 2, 'name' => 'Bob', 'staff_id' => 'S002']];

        $result = $this->service->ensureUserInList($users, 1, 'Alice', 'S001', 99, false, $userGroupTable);

        $this->assertCount(1, $result);
        $this->assertSame(2, $result[0]['id']); // 変化なし
    }

    /* =====================================================================
     * userBelongsToRoom
     * ===================================================================== */

    public function testUserBelongsToRoomReturnsTrueWhenUserIsInRoom(): void
    {
        // フィクスチャ: ユーザー1 は部屋1 に所属
        $userGroupTable = $this->getTableLocator()->get('MUserGroup');

        $this->assertTrue($this->service->userBelongsToRoom($userGroupTable, 1, 1));
    }

    public function testUserBelongsToRoomReturnsFalseWhenUserIsNotInRoom(): void
    {
        $userGroupTable = $this->getTableLocator()->get('MUserGroup');

        $this->assertFalse($this->service->userBelongsToRoom($userGroupTable, 1, 99));
    }

    public function testUserBelongsToRoomReturnsFalseForUnknownUser(): void
    {
        $userGroupTable = $this->getTableLocator()->get('MUserGroup');

        $this->assertFalse($this->service->userBelongsToRoom($userGroupTable, 999, 1));
    }
}
