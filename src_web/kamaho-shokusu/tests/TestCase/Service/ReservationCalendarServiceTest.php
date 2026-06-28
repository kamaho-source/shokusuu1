<?php
declare(strict_types=1);

namespace App\Test\TestCase\Service;

use App\Service\ReservationCalendarService;
use Cake\I18n\Date;
use Cake\ORM\TableRegistry;
use Cake\TestSuite\TestCase;

class ReservationCalendarServiceTest extends TestCase
{
    protected array $fixtures = [
        'app.MUserInfo',
        'app.MUserGroup',
        'app.MRoomInfo',
        'app.TIndividualReservationInfo',
    ];

    private ReservationCalendarService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new ReservationCalendarService();
    }

    // ----------------------------------------------------------------
    // getPrimaryRoomId — DB不要
    // ----------------------------------------------------------------

    public function testGetPrimaryRoomId_emptyArray_returnsNull(): void
    {
        $this->assertNull($this->service->getPrimaryRoomId([]));
    }

    public function testGetPrimaryRoomId_returnsFirstElement(): void
    {
        $this->assertSame(42, $this->service->getPrimaryRoomId([42, 10, 5]));
    }

    // ----------------------------------------------------------------
    // buildMyReservationDates — DB不要
    // ----------------------------------------------------------------

    public function testBuildMyReservationDates_emptyDetails_returnsEmpty(): void
    {
        $this->assertSame([], $this->service->buildMyReservationDates([]));
    }

    public function testBuildMyReservationDates_onlyReservedDatesIncluded(): void
    {
        $details = [
            '2099-01-01' => ['breakfast' => 1, 'lunch' => null, 'dinner' => null, 'bento' => null],
            '2099-01-02' => ['breakfast' => 0, 'lunch' => 0, 'dinner' => 0, 'bento' => 0],
            '2099-01-03' => ['breakfast' => null, 'lunch' => null, 'dinner' => null, 'bento' => null],
        ];

        $result = $this->service->buildMyReservationDates($details);

        $this->assertSame(['2099-01-01'], $result);
    }

    public function testBuildMyReservationDates_sortsDates(): void
    {
        $details = [
            '2099-03-01' => ['breakfast' => 1, 'lunch' => null, 'dinner' => null, 'bento' => null],
            '2099-01-01' => ['breakfast' => 1, 'lunch' => null, 'dinner' => null, 'bento' => null],
            '2099-02-01' => ['breakfast' => 1, 'lunch' => null, 'dinner' => null, 'bento' => null],
        ];

        $result = $this->service->buildMyReservationDates($details);

        $this->assertSame(['2099-01-01', '2099-02-01', '2099-03-01'], $result);
    }

    // ----------------------------------------------------------------
    // buildCalendarEvents — DB不要
    // ----------------------------------------------------------------

    public function testBuildCalendarEvents_noReservations_generatesUnreservedEvents(): void
    {
        $startDate = new Date('2099-01-01');
        $endDate   = new Date('2099-01-04');

        $events = $this->service->buildCalendarEvents([], [], $startDate, $endDate);

        // 予約なしの場合、日付ごとに「未予約」イベントが生成されること
        $titles = array_column($events, 'title');
        $this->assertContains('未予約', $titles);
    }

    public function testBuildCalendarEvents_withReservation_addsReservedEvent(): void
    {
        $startDate = new Date('2099-01-01');
        $endDate   = new Date('2099-01-03');
        $details   = [
            '2099-01-01' => ['breakfast' => 1, 'lunch' => null, 'dinner' => null, 'bento' => null],
        ];

        $events = $this->service->buildCalendarEvents([], $details, $startDate, $endDate);

        $starts = array_column($events, 'start');
        $this->assertContains('2099-01-01', $starts);
    }

    // ----------------------------------------------------------------
    // getUserRoomIds — DB使用
    // ----------------------------------------------------------------

    public function testGetUserRoomIds_existingUser_returnsRoomIds(): void
    {
        $userGroupTable = TableRegistry::getTableLocator()->get('MUserGroup');

        $result = $this->service->getUserRoomIds($userGroupTable, 1);

        $this->assertIsArray($result);
        $this->assertContains(1, $result);
    }

    public function testGetUserRoomIds_nonExistentUser_returnsEmpty(): void
    {
        $userGroupTable = TableRegistry::getTableLocator()->get('MUserGroup');

        $result = $this->service->getUserRoomIds($userGroupTable, 9999);

        $this->assertSame([], $result);
    }

    // ----------------------------------------------------------------
    // isOfficeUser — DB使用
    // ----------------------------------------------------------------

    public function testIsOfficeUser_zeroUserId_returnsFalse(): void
    {
        $userGroupTable = TableRegistry::getTableLocator()->get('MUserGroup');
        $roomTable      = TableRegistry::getTableLocator()->get('MRoomInfo');

        $this->assertFalse($this->service->isOfficeUser($userGroupTable, $roomTable, 0));
    }

    public function testIsOfficeUser_noOfficeRoom_returnsFalse(): void
    {
        $userGroupTable = TableRegistry::getTableLocator()->get('MUserGroup');
        $roomTable      = TableRegistry::getTableLocator()->get('MRoomInfo');

        // フィクスチャの部屋名は '事務所' を含まないので false が返る
        $this->assertFalse($this->service->isOfficeUser($userGroupTable, $roomTable, 1));
    }

    // ----------------------------------------------------------------
    // getRoomsForUser — DB使用
    // ----------------------------------------------------------------

    public function testGetRoomsForUser_adminReturnsAllRooms(): void
    {
        $roomTable = TableRegistry::getTableLocator()->get('MRoomInfo');

        $result = $this->service->getRoomsForUser($roomTable, [], isAdmin: true);

        $this->assertIsArray($result);
        $this->assertArrayHasKey(1, $result); // fixture にid=1の部屋が存在する
    }

    public function testGetRoomsForUser_emptyRoomIds_returnsEmpty(): void
    {
        $roomTable = TableRegistry::getTableLocator()->get('MRoomInfo');

        $result = $this->service->getRoomsForUser($roomTable, [], isAdmin: false);

        $this->assertSame([], $result);
    }
}
