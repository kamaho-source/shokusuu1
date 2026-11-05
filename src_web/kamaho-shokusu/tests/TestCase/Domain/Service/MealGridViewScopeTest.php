<?php
declare(strict_types=1);

namespace App\Test\TestCase\Domain\Service;

use App\Domain\Service\MealGridViewScope;
use PHPUnit\Framework\TestCase;

/**
 * エクセル食数予約の表示範囲（MealGridViewScope）。
 */
class MealGridViewScopeTest extends TestCase
{
    private const ROOMS = [71 => '本館1', 72 => '本館2', 73 => '別館'];

    private static function admin(): MealGridViewScope
    {
        return MealGridViewScope::forUser(1, 0, false);
    }

    private static function office(): MealGridViewScope
    {
        return MealGridViewScope::forUser(0, 1, true);
    }

    private static function blockLeader(): MealGridViewScope
    {
        return MealGridViewScope::forUser(2, 1, false);
    }

    private static function staff(): MealGridViewScope
    {
        return MealGridViewScope::forUser(0, 0, false);
    }

    private static function child(): MealGridViewScope
    {
        return MealGridViewScope::forUser(0, 1, false);
    }

    // ---- forUser ----

    public function testForUser_flags(): void
    {
        $cases = [
            'admin'        => [self::admin(), true, true, true],
            'systemAdmin'  => [MealGridViewScope::forUser(3, -1, false), true, true, true],
            'office'       => [self::office(), false, true, true],
            'blockLeader'  => [self::blockLeader(), false, false, true],
            'staff'        => [self::staff(), false, false, true],
            'child'        => [self::child(), false, false, false],
            'unknownLevel' => [MealGridViewScope::forUser(0, -1, false), false, false, false],
        ];
        foreach ($cases as $name => [$scope, $isAdmin, $canViewAll, $canViewRoom]) {
            $this->assertSame($isAdmin, $scope->isAdmin, $name);
            $this->assertSame($canViewAll, $scope->canViewAll, $name);
            $this->assertSame($canViewRoom, $scope->canViewRoom, $name);
            $this->assertSame($canViewRoom, $scope->canUseAllMode, $name);
        }
    }

    // ---- resolveMode ----

    public function testResolveMode_keepsValidModeForRoomViewer(): void
    {
        foreach (['individual', 'room', 'all'] as $mode) {
            $this->assertSame($mode, self::staff()->resolveMode($mode));
        }
    }

    public function testResolveMode_unknownOrMissingIsIndividual(): void
    {
        $this->assertSame('individual', self::admin()->resolveMode('bogus'));
        $this->assertSame('individual', self::admin()->resolveMode(null));
    }

    public function testResolveMode_childOnlyIndividual(): void
    {
        $this->assertSame('individual', self::child()->resolveMode('room'));
        $this->assertSame('individual', self::child()->resolveMode('all'));
    }

    // ---- visibleRooms ----

    public function testVisibleRooms_adminSeesAllRoomsInEveryMode(): void
    {
        foreach (['individual', 'room', 'all'] as $mode) {
            $this->assertSame(self::ROOMS, self::admin()->visibleRooms(self::ROOMS, [71], $mode));
        }
    }

    public function testVisibleRooms_officeLimitedToOwnRoomsInRoomAndAllModes(): void
    {
        $this->assertSame([71 => '本館1'], self::office()->visibleRooms(self::ROOMS, [71], 'room'));
        $this->assertSame([71 => '本館1'], self::office()->visibleRooms(self::ROOMS, [71], 'all'));
        $this->assertSame(self::ROOMS, self::office()->visibleRooms(self::ROOMS, [71], 'individual'));
    }

    // ---- resolveRoomId ----

    public function testResolveRoomId(): void
    {
        $scope = self::staff();
        $this->assertSame(72, $scope->resolveRoomId(72, 73, self::ROOMS));
        $this->assertSame(73, $scope->resolveRoomId(null, 73, self::ROOMS), '指定が無ければ前回');
        $this->assertSame(71, $scope->resolveRoomId(999, null, self::ROOMS), '扱えない部屋は先頭');
        $this->assertSame(71, $scope->resolveRoomId(0, 73, self::ROOMS), '0 も指定として扱い、扱えないので先頭');
        $this->assertSame(71, $scope->resolveRoomId(null, null, self::ROOMS));
        $this->assertNull($scope->resolveRoomId(72, null, []));
    }

    // ---- resolveUserId ----

    public function testResolveUserId_nonViewAllIsAlwaysSelf(): void
    {
        $this->assertSame(3, self::blockLeader()->resolveUserId(6, 7, 3, []));
    }

    public function testResolveUserId_viewAll(): void
    {
        $users = [3 => '本人', 6 => '利用者6', 7 => '利用者7'];
        $this->assertSame(6, self::admin()->resolveUserId(6, 7, 3, $users));
        $this->assertSame(7, self::admin()->resolveUserId(null, 7, 3, $users), '指定が無ければ前回');
        $this->assertSame(3, self::admin()->resolveUserId(null, null, 3, $users));
        $this->assertSame(3, self::office()->resolveUserId(99, null, 3, $users), '選べない利用者は本人');
    }

    // ---- targetRooms ----

    public function testTargetRooms(): void
    {
        $scope = self::admin();
        $this->assertSame([72 => '本館2', 73 => '別館'], $scope->targetRooms('individual', self::ROOMS, 71, [72, 73, 99]));
        $this->assertSame([71 => '本館1'], $scope->targetRooms('room', self::ROOMS, 71, [72]));
        $this->assertSame([], $scope->targetRooms('room', self::ROOMS, null, []));
        $this->assertSame([], $scope->targetRooms('room', self::ROOMS, 999, []));
        $this->assertSame(self::ROOMS, $scope->targetRooms('all', self::ROOMS, 71, []));
    }

    // ---- rowUserId ----

    public function testRowUserId_onlyIndividualNarrowsRows(): void
    {
        $this->assertSame(6, self::admin()->rowUserId('individual', 6));
        $this->assertNull(self::admin()->rowUserId('room', 6));
        $this->assertNull(self::admin()->rowUserId('all', 6));
    }

    // ---- nameList ----

    public function testNameList(): void
    {
        $users = [3 => '本人', 6 => '利用者6'];
        $this->assertSame($users, self::office()->nameList('individual', $users, 3, '本人'));
        $this->assertSame([3 => '職員A'], self::staff()->nameList('individual', [], 3, '職員A'));
        $this->assertSame([], self::admin()->nameList('room', $users, 3, '本人'));
    }

    // ---- editableRoomIds ----

    public function testEditableRoomIds(): void
    {
        $this->assertSame([71, 72, 73], self::admin()->editableRoomIds(self::ROOMS, [71]));
        $this->assertSame([71], self::office()->editableRoomIds(self::ROOMS, [71]));
        $this->assertSame([72], self::staff()->editableRoomIds([72 => '本館2'], [72]));
    }
}
