<?php
declare(strict_types=1);

namespace App\Test\TestCase\Infrastructure\Query;

use App\Application\Query\Dto\RoomUserMeals;
use App\Infrastructure\Query\CakeReservationReadQuery;
use App\Infrastructure\Query\CakeRoomQuery;
use Cake\Chronos\ClockFactory;
use Cake\Datasource\ConnectionManager;
use Cake\I18n\Date;
use Cake\TestSuite\TestCase;

/**
 * 予約の読み取り API（CakeReservationReadQuery）。旧 ReservationQueryServiceTest を引き継ぐ。
 *
 * hasDuplicateReservation・getUsersByRoom・getReservationSnapshots・getUsersByRoomForBulk の挙動を検証する。
 */
class CakeReservationReadQueryTest extends TestCase
{
    protected array $fixtures = [
        'app.MUserInfo',
        'app.MUserGroup',
        'app.MRoomInfo',
        'app.TIndividualReservationInfo',
    ];

    private CakeReservationReadQuery $service;

    /**
     * @param list<RoomUserMeals> $users
     * @return list<array<string, int|string|bool>> API の応答の形
     */
    private static function rows(array $users): array
    {
        return array_map(static fn(RoomUserMeals $u) => $u->toArray(), $users);
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new CakeReservationReadQuery(new CakeRoomQuery(), new ClockFactory('Asia/Tokyo'));
    }

    // ----------------------------------------------------------------
    // hasDuplicateReservation — DB使用
    // ----------------------------------------------------------------

    public function testHasDuplicateReservation_nonExistent_returnsFalse(): void
    {
        $result = $this->service->hasDuplicateReservation(
            '2099-12-31',
            9999,
            1
        );

        $this->assertFalse($result);
    }

    // ----------------------------------------------------------------
    // getUsersByRoom — DB使用
    // ----------------------------------------------------------------

    public function testGetUsersByRoom_returnsArray(): void
    {
        $result = self::rows($this->service->getUsersByRoom(1, null));

        $this->assertIsArray($result);
    }

    public function testGetUsersByRoom_eachEntryHasRequiredKeys(): void
    {
        $result = self::rows($this->service->getUsersByRoom(1, null));

        $this->assertNotEmpty($result);
        foreach ($result as $entry) {
            $this->assertArrayHasKey('id', $entry);
            $this->assertArrayHasKey('name', $entry);
            $this->assertArrayHasKey('morning', $entry);
            $this->assertArrayHasKey('noon', $entry);
            $this->assertArrayHasKey('night', $entry);
            $this->assertArrayHasKey('bento', $entry);
        }
    }

    public function testGetUsersByRoom_nonExistentRoom_returnsEmpty(): void
    {
        $result = self::rows($this->service->getUsersByRoom(9999, null));

        $this->assertSame([], $result);
    }

    // ----------------------------------------------------------------
    // getReservationSnapshots — DB使用
    // ----------------------------------------------------------------

    public function testGetReservationSnapshots_zeroRoomId_returnsEmpty(): void
    {
        $result = $this->service->getReservationSnapshots(0, ['2099-01-01']);

        $this->assertSame([], $result);
    }

    public function testGetReservationSnapshots_emptyDates_returnsEmpty(): void
    {
        $result = $this->service->getReservationSnapshots(1, []);

        $this->assertSame([], $result);
    }

    public function testGetReservationSnapshots_nonExistentData_returnsEmptyMap(): void
    {
        $result = $this->service->getReservationSnapshots(1, ['2099-12-31']);

        $this->assertIsArray($result);
        $this->assertEmpty($result);
    }

    // ----------------------------------------------------------------
    // getUsersByRoomForBulk — DB使用
    // ----------------------------------------------------------------

    public function testGetUsersByRoomForBulk_returnsRequiredKeys(): void
    {
        $result = $this->service->getUsersByRoomForBulk(1, null, 1, 100)->toArray();

        $this->assertArrayHasKey('users', $result);
        $this->assertArrayHasKey('reservations', $result);
        $this->assertArrayHasKey('other_room_reservations', $result);
        $this->assertArrayHasKey('total', $result);
        $this->assertArrayHasKey('page', $result);
        $this->assertArrayHasKey('limit', $result);
        $this->assertArrayHasKey('reservation_snapshot', $result);
    }

    public function testGetUsersByRoomForBulk_roomWithUsers_returnsUsers(): void
    {
        $result = $this->service->getUsersByRoomForBulk(1, null, 1, 100)->toArray();

        // フィクスチャにroom1にuser1,2,3が存在する
        $this->assertGreaterThan(0, $result['total']);
    }

    // ----------------------------------------------------------------
    // 有効値判定の統一（バグ4）
    // ----------------------------------------------------------------

    /**
     * バグ4: getUsersByRoomForBulk の有効値判定を他サービスと統一する。
     *
     * 過去日の予約は isLastMinuteWindow（今日〜今日+14日）では対象外となり
     * eat_flag が使われていたが、他サービスは下限のない shouldUseChangeFlag を
     * 使うため i_change_flag が優先される。両者の結果が一致することを検証する。
     */
    public function testGetUsersByRoomForBulk_pastDate_usesChangeFlagLikeOtherServices(): void
    {
        $pastDate = Date::today('Asia/Tokyo')->subDays(3)->format('Y-m-d');

        // eat_flag=0（未予約）だが i_change_flag=1（直前で予約に変更）の過去日データ
        ConnectionManager::get('test')->insert('t_individual_reservation_info', [
            'i_id_user'          => 2,
            'd_reservation_date' => $pastDate,
            'i_reservation_type' => 1,
            'i_id_room'          => 1,
            'eat_flag'           => 0,
            'i_change_flag'      => 1,
            'i_approval_status'  => 0,
            'i_version'          => 1,
            'dt_create'          => '2026-01-01 00:00:00',
            'c_create_user'      => 'test',
        ]);

        $bulk = $this->service->getUsersByRoomForBulk(1, $pastDate, 1, 100)->toArray();
        $this->assertTrue(
            $bulk['reservations'][2][1] ?? false,
            'i_change_flag=1 の過去日予約が一括編集画面で有効と判定されていない'
        );

        // getUsersByRoom（shouldUseChangeFlag 利用）と判定が一致する
        $byRoom = self::rows($this->service->getUsersByRoom(1, $pastDate));
        $user2  = array_values(array_filter($byRoom, static fn(array $u): bool => $u['id'] === 2));
        $this->assertTrue($user2[0]['morning'], 'getUsersByRoom と判定が一致していない');
    }

    /** 直前一括の競合チェック用のスナップショットは、Y-m-d のキーで返す（画面が日付で引けるように） */
    public function testGetReservationSnapshots_keysAreYmd(): void
    {
        $date = \Cake\I18n\Date::today()->addDays(5)->format('Y-m-d');
        \Cake\Datasource\ConnectionManager::get('test')->insert('t_individual_reservation_info', [
            'i_id_user' => 3, 'd_reservation_date' => $date, 'i_reservation_type' => 1, 'i_id_room' => 1,
            'eat_flag' => 1, 'i_change_flag' => 1, 'i_version' => 1,
            'dt_create' => '2026-01-01 00:00:00', 'c_create_user' => 't',
        ]);

        $map = $this->service->getReservationSnapshots(1, [$date]);

        $this->assertArrayHasKey($date, $map);
    }

    /** スナップショットの値は日時（年だけにならない）。年だけだと同じ年の更新を競合として検出できない */
    public function testGetReservationSnapshots_valueIsDateTime(): void
    {
        $date = \Cake\I18n\Date::today()->addDays(5)->format('Y-m-d');
        \Cake\Datasource\ConnectionManager::get('test')->insert('t_individual_reservation_info', [
            'i_id_user' => 3, 'd_reservation_date' => $date, 'i_reservation_type' => 1, 'i_id_room' => 1,
            'eat_flag' => 1, 'i_change_flag' => 1, 'i_version' => 1,
            'dt_create' => '2026-01-01 00:00:00', 'c_create_user' => 't', 'dt_update' => '2026-03-04 05:06:07',
        ]);

        $map = $this->service->getReservationSnapshots(1, [$date]);

        $this->assertSame('2026-03-04 05:06:07', $map[$date]);
    }

    /** 一括登録の応答に、その日・部屋の最終更新日時（楽観的ロック用）が入る */
    public function testGetUsersByRoomForBulk_includesSnapshot(): void
    {
        $date = \Cake\I18n\Date::today()->addDays(5)->format('Y-m-d');
        \Cake\Datasource\ConnectionManager::get('test')->insert('t_individual_reservation_info', [
            'i_id_user' => 3, 'd_reservation_date' => $date, 'i_reservation_type' => 1, 'i_id_room' => 1,
            'eat_flag' => 1, 'i_change_flag' => 1, 'i_version' => 1,
            'dt_create' => '2026-01-01 00:00:00', 'c_create_user' => 't', 'dt_update' => '2026-03-04 05:06:07',
        ]);

        $payload = $this->service->getUsersByRoomForBulk(1, $date, 1, 100)->toArray();

        $this->assertSame('2026-03-04 05:06:07', $payload['reservation_snapshot']);
        $this->assertTrue($payload['reservations'][3][1] ?? false);
    }

    /** 個人予約の応答に、本人と、選べる部屋（所属している削除されていない部屋）が入る */
    public function testGetPersonalReservation_includesUserAndAuthorizedRooms(): void
    {
        $payload = $this->service->getPersonalReservation(1, '管理者', '2099-01-01')->toArray();

        $this->assertSame(['i_id_user' => 1, 'c_user_name' => '管理者'], $payload['user']);
        $this->assertArrayHasKey(1, $payload['authorized_rooms']);
        $this->assertSame([], $payload['reservation']);
    }
}
