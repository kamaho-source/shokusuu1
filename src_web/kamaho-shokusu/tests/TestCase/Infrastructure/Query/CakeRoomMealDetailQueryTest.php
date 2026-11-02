<?php
declare(strict_types=1);

namespace App\Test\TestCase\Infrastructure\Query;

use App\Domain\Exception\NotFoundException;
use App\Infrastructure\Query\CakeRoomMealDetailQuery;
use Cake\Chronos\ClockFactory;
use Cake\Datasource\ConnectionManager;
use Cake\I18n\DateTime;
use Cake\ORM\TableRegistry;
use Cake\TestSuite\TestCase;

/**
 * 部屋の詳細の読み取り（CakeRoomMealDetailQuery）。旧 ReservationRoomDetailServiceTest を引き継ぐ。
 *
 * 例外のガード条件、useChangeFlag の日付判定、食べる・食べない・未登録の振り分けを確かめる。
 */
class CakeRoomMealDetailQueryTest extends TestCase
{
    protected array $fixtures = [
        'app.MUserInfo',
        'app.MUserGroup',
        'app.MRoomInfo',
        'app.TIndividualReservationInfo',
    ];

    private CakeRoomMealDetailQuery $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new CakeRoomMealDetailQuery(new ClockFactory('Asia/Tokyo'));
    }

    // ----------------------------------------------------------------
    // getRoomMealDetail — 存在しない部屋は例外
    // ----------------------------------------------------------------

    public function testGetRoomDetails_nonExistentRoom_throwsNotFoundException(): void
    {
        $this->expectException(NotFoundException::class);

        $this->service->getRoomMealDetail(
            9999,
            '2099-01-01',
            1
        );
    }

    // ----------------------------------------------------------------
    // getRoomMealDetail — 無効な日付は例外
    // ----------------------------------------------------------------

    public function testGetRoomDetails_invalidDate_throwsInvalidArgumentException(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->service->getRoomMealDetail(
            1,
            'not-a-date',
            1
        );
    }

    // ----------------------------------------------------------------
    // getRoomMealDetail — 有効な部屋IDのハッピーパス
    // ----------------------------------------------------------------

    public function testGetRoomDetails_validRoom_returnsRequiredKeys(): void
    {
        $result = $this->service->getRoomMealDetail(
            1,
            '2099-01-01',
            1
        );

        $this->assertSame(TableRegistry::getTableLocator()->get('MRoomInfo')->get(1)->c_room_name, $result->roomName);
        $this->assertIsArray($result->eatUsers);
        $this->assertIsArray($result->noEatUsers);
        $this->assertIsArray($result->otherRoomEaters);
    }

    public function testGetRoomDetails_farFutureDate_useChangeFlagFalse(): void
    {
        $result = $this->service->getRoomMealDetail(
            1,
            '2099-12-31', // 14日以上先 -> useChangeFlag=false
            1
        );

        $this->assertFalse($result->useChangeFlag);
    }

    public function testGetRoomDetails_nearFutureDate_useChangeFlagTrue(): void
    {
        // 今日から5日後 -> 14日以内 -> useChangeFlag=true
        $nearDate = (new \DateTimeImmutable('+5 days', new \DateTimeZone('Asia/Tokyo')))->format('Y-m-d');

        $result = $this->service->getRoomMealDetail(
            1,
            $nearDate,
            1
        );

        $this->assertTrue($result->useChangeFlag);
    }

    /**
     * 境界日（今日+14日）までは i_change_flag、翌日からは eat_flag。日付を固定して境目を確かめる
     */
    public function testGetRoomDetails_boundaryIsTodayPlus14_withFixedToday(): void
    {
        DateTime::setTestNow(new DateTime('2026-11-01 23:30:00', 'Asia/Tokyo'));
        try {
            $this->assertTrue($this->service->getRoomMealDetail(1, '2026-11-15', 1)->useChangeFlag);
            $this->assertFalse($this->service->getRoomMealDetail(1, '2026-11-16', 1)->useChangeFlag);
        } finally {
            DateTime::setTestNow(null);
        }
    }

    /** 食べる人・食べない人（未登録を含む）・別の部屋で食べる人を、表示順の名前で返す */
    public function testSortsUsersIntoEatNoEatAndOtherRoom(): void
    {
        $db = ConnectionManager::get('test');
        $db->execute('DELETE FROM t_individual_reservation_info');
        $users = TableRegistry::getTableLocator()->get('MUserInfo');
        $name = fn(int $id) => (string)$users->get($id)->c_user_name;
        $insert = fn(int $userId, int $roomId, int $eat) => $db->insert('t_individual_reservation_info', [
            'i_id_user' => $userId, 'd_reservation_date' => '2099-01-01', 'i_reservation_type' => 1, 'i_id_room' => $roomId,
            'eat_flag' => $eat, 'i_change_flag' => $eat, 'i_version' => 1, 'dt_create' => '2026-01-01 00:00:00', 'c_create_user' => 't',
        ]);
        $insert(1, 1, 1);
        $insert(2, 1, 0);
        $insert(6, 1, 1); // 削除済みの人の予約

        $detail = $this->service->getRoomMealDetail(1, '2099-01-01', 1);

        $this->assertSame([$name(1)], $detail->eatUsers, '削除済みの人は、予約があっても出ない');
        $this->assertContains($name(2), $detail->noEatUsers, '食べない人');
        $this->assertContains($name(3), $detail->noEatUsers, 'まだ登録していない人');
        $this->assertNotContains($name(6), $detail->noEatUsers, '削除済みの人は出ない');
        $this->assertSame([], $detail->otherRoomEaters);
    }
}
