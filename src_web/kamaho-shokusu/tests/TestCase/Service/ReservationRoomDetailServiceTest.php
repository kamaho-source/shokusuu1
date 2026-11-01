<?php
declare(strict_types=1);

namespace App\Test\TestCase\Service;

use App\Service\ReservationRoomDetailService;
use Cake\Http\Exception\NotFoundException;
use Cake\I18n\DateTime;
use Cake\ORM\TableRegistry;
use Cake\TestSuite\TestCase;

/**
 * ReservationRoomDetailService テスト。
 *
 * getRoomDetails の例外ガード条件と useChangeFlag の日付判定ロジックを検証する。
 */
class ReservationRoomDetailServiceTest extends TestCase
{
    protected array $fixtures = [
        'app.MUserInfo',
        'app.MUserGroup',
        'app.MRoomInfo',
        'app.TIndividualReservationInfo',
    ];

    private ReservationRoomDetailService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new ReservationRoomDetailService();
    }

    // ----------------------------------------------------------------
    // getRoomDetails — 存在しない部屋は例外
    // ----------------------------------------------------------------

    public function testGetRoomDetails_nonExistentRoom_throwsNotFoundException(): void
    {
        $roomTable        = TableRegistry::getTableLocator()->get('MRoomInfo');
        $reservationTable = TableRegistry::getTableLocator()->get('TIndividualReservationInfo');
        $userGroupTable   = TableRegistry::getTableLocator()->get('MUserGroup');

        $this->expectException(NotFoundException::class);

        $this->service->getRoomDetails(
            9999,
            '2099-01-01',
            1,
            $roomTable,
            $reservationTable,
            $userGroupTable
        );
    }

    // ----------------------------------------------------------------
    // getRoomDetails — 無効な日付は例外
    // ----------------------------------------------------------------

    public function testGetRoomDetails_invalidDate_throwsInvalidArgumentException(): void
    {
        $roomTable        = TableRegistry::getTableLocator()->get('MRoomInfo');
        $reservationTable = TableRegistry::getTableLocator()->get('TIndividualReservationInfo');
        $userGroupTable   = TableRegistry::getTableLocator()->get('MUserGroup');

        $this->expectException(\InvalidArgumentException::class);

        $this->service->getRoomDetails(
            1,
            'not-a-date',
            1,
            $roomTable,
            $reservationTable,
            $userGroupTable
        );
    }

    // ----------------------------------------------------------------
    // getRoomDetails — 有効な部屋IDのハッピーパス
    // ----------------------------------------------------------------

    public function testGetRoomDetails_validRoom_returnsRequiredKeys(): void
    {
        $roomTable        = TableRegistry::getTableLocator()->get('MRoomInfo');
        $reservationTable = TableRegistry::getTableLocator()->get('TIndividualReservationInfo');
        $userGroupTable   = TableRegistry::getTableLocator()->get('MUserGroup');

        $result = $this->service->getRoomDetails(
            1,
            '2099-01-01',
            1,
            $roomTable,
            $reservationTable,
            $userGroupTable
        );

        $this->assertArrayHasKey('room', $result);
        $this->assertArrayHasKey('eatUsers', $result);
        $this->assertArrayHasKey('noEatUsers', $result);
        $this->assertArrayHasKey('otherRoomEaters', $result);
        $this->assertArrayHasKey('useChangeFlag', $result);
    }

    public function testGetRoomDetails_farFutureDate_useChangeFlagFalse(): void
    {
        $roomTable        = TableRegistry::getTableLocator()->get('MRoomInfo');
        $reservationTable = TableRegistry::getTableLocator()->get('TIndividualReservationInfo');
        $userGroupTable   = TableRegistry::getTableLocator()->get('MUserGroup');

        $result = $this->service->getRoomDetails(
            1,
            '2099-12-31', // 14日以上先 -> useChangeFlag=false
            1,
            $roomTable,
            $reservationTable,
            $userGroupTable
        );

        $this->assertFalse($result['useChangeFlag']);
    }

    public function testGetRoomDetails_nearFutureDate_useChangeFlagTrue(): void
    {
        $roomTable        = TableRegistry::getTableLocator()->get('MRoomInfo');
        $reservationTable = TableRegistry::getTableLocator()->get('TIndividualReservationInfo');
        $userGroupTable   = TableRegistry::getTableLocator()->get('MUserGroup');

        // 今日から5日後 -> 14日以内 -> useChangeFlag=true
        $nearDate = (new \DateTimeImmutable('+5 days', new \DateTimeZone('Asia/Tokyo')))->format('Y-m-d');

        $result = $this->service->getRoomDetails(
            1,
            $nearDate,
            1,
            $roomTable,
            $reservationTable,
            $userGroupTable
        );

        $this->assertTrue($result['useChangeFlag']);
    }

    /**
     * 境界日（今日+14日）までは i_change_flag、翌日からは eat_flag。日付を固定して境目を確かめる
     */
    public function testGetRoomDetails_boundaryIsTodayPlus14_withFixedToday(): void
    {
        $tables = [
            TableRegistry::getTableLocator()->get('MRoomInfo'),
            TableRegistry::getTableLocator()->get('TIndividualReservationInfo'),
            TableRegistry::getTableLocator()->get('MUserGroup'),
        ];
        DateTime::setTestNow(new DateTime('2026-11-01 23:30:00', 'Asia/Tokyo'));
        try {
            $this->assertTrue($this->service->getRoomDetails(1, '2026-11-15', 1, ...$tables)['useChangeFlag']);
            $this->assertFalse($this->service->getRoomDetails(1, '2026-11-16', 1, ...$tables)['useChangeFlag']);
        } finally {
            DateTime::setTestNow(null);
        }
    }
}
