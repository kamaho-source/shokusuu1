<?php
declare(strict_types=1);

namespace App\Test\TestCase\Service;

use App\Service\ReservationRoomDetailService;
use Cake\Http\Exception\NotFoundException;
use Cake\ORM\TableRegistry;
use Cake\TestSuite\TestCase;

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
        $nearDate = (new \DateTimeImmutable('+5 days'))->format('Y-m-d');

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
}
