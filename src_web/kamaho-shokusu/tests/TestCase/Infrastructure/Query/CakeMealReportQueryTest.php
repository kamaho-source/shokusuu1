<?php
declare(strict_types=1);

namespace App\Test\TestCase\Infrastructure\Query;

use App\Application\Query\Dto\RoomUserMealStatus;
use App\Infrastructure\Cache\ReservationCache;
use App\Infrastructure\Query\CakeMealReportQuery;
use App\Service\ReservationWriteService;
use Cake\Cache\Cache;
use Cake\Chronos\ClockFactory;
use Cake\Datasource\ConnectionManager;
use Cake\I18n\Date;
use Cake\ORM\TableRegistry;
use Cake\TestSuite\TestCase;

/**
 * 食数・レポートの読み取り（CakeMealReportQuery）。旧 ReservationReportService のテストを引き継ぐ。
 *
 * 「食べる」の判定（直前期間は i_change_flag、空なら eat_flag）、集計の中身、キャッシュの形を確かめる。
 */
class CakeMealReportQueryTest extends TestCase
{
    protected array $fixtures = [
        'app.MUserInfo',
        'app.MUserGroup',
        'app.MRoomInfo',
        'app.TIndividualReservationInfo',
        'app.TReservationInfo',
    ];

    private CakeMealReportQuery $query;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::clear('default');
        $this->query = new CakeMealReportQuery(new ClockFactory('Asia/Tokyo'));
    }

    private function insert(int $userId, int $roomId, string $date, int $type, int $eat, ?int $change): void
    {
        ConnectionManager::get('test')->insert('t_individual_reservation_info', [
            'i_id_user' => $userId, 'i_id_room' => $roomId, 'd_reservation_date' => $date, 'i_reservation_type' => $type,
            'eat_flag' => $eat, 'i_change_flag' => $change, 'i_version' => 1,
            'dt_create' => '2026-01-01 00:00:00', 'c_create_user' => 't',
        ]);
    }

    private static function inDays(int $days): string
    {
        return Date::today('Asia/Tokyo')->addDays($days)->format('Y-m-d');
    }

    // ---- getMealCounts ----

    public function testGetMealCounts_noReservations_returnsEmpty(): void
    {
        $this->assertSame([], $this->query->getMealCounts('2099-12-31'));
    }

    /** 直前期間は i_change_flag で数える（eat_flag=0 でも i_change_flag=1 なら食べる） */
    public function testGetMealCounts_countsLastMinuteChangeFlagOnly(): void
    {
        $date = self::inDays(7);
        $this->insert(1, 1, $date, 2, 0, 1);
        $this->insert(2, 1, $date, 2, 1, 0);

        $result = $this->query->getMealCounts($date);

        $this->assertCount(1, $result);
        $this->assertSame(['meal_type' => 2, 'count' => 1], $result[0]->toArray());
    }

    /** 直前期間で i_change_flag が NULL の行は、当初予定（eat_flag）で数える（カレンダー・承認と同じ） */
    public function testGetMealCounts_nullChangeFlagFallsBackToEatFlag(): void
    {
        $date = self::inDays(7);
        $this->insert(1, 1, $date, 3, 1, null);

        $this->assertSame([['meal_type' => 3, 'count' => 1]], array_map(static fn($c) => $c->toArray(), $this->query->getMealCounts($date)));
    }

    /** 直前期間より先は eat_flag だけを見る（i_change_flag=1 でも eat_flag=0 なら数えない） */
    public function testGetMealCounts_beyondWindow_usesEatFlag(): void
    {
        $date = self::inDays(30);
        $this->insert(1, 1, $date, 1, 0, 1);
        $this->insert(2, 1, $date, 1, 1, 0);

        $this->assertSame([['meal_type' => 1, 'count' => 1]], array_map(static fn($c) => $c->toArray(), $this->query->getMealCounts($date)));
    }

    /** キャッシュは以前と同じキー・形で書き、以前の形のキャッシュもそのまま読める（リリースをまたいでも壊れない） */
    public function testGetMealCounts_cacheKeepsLegacyShape(): void
    {
        $date = self::inDays(30);
        $this->insert(1, 1, $date, 1, 1, null);
        $this->query->getMealCounts($date);
        $this->assertSame([['meal_type' => 1, 'count' => 1]], Cache::read(ReservationCache::mealCountsKey($date), 'default'));

        Cache::write(ReservationCache::mealCountsKey('2099-01-01'), [['meal_type' => 4, 'count' => 9]], 'default');
        $this->assertSame([['meal_type' => 4, 'count' => 9]], array_map(static fn($c) => $c->toArray(), $this->query->getMealCounts('2099-01-01')));
    }

    // ---- getUsersByRoomForEdit ----

    public function testGetUsersByRoomForEdit_reflectsEffectiveFlag(): void
    {
        $date = self::inDays(7);
        $this->insert(1, 1, $date, 2, 0, 1);
        $this->insert(1, 1, $date, 3, 1, 0);

        $this->assertSame(
            ['id' => 1, 'name' => '管理者ユーザー', 'meals' => ['morning' => false, 'noon' => true, 'night' => false, 'bento' => false]],
            $this->findUser($this->query->getUsersByRoomForEdit(1, $date), 1)->toArray()
        );
    }

    /** 予約を更新したら、直前編集用の部屋別利用者一覧にすぐ反映される（#819。キャッシュしない） */
    public function testGetUsersByRoomForEdit_reflectsToggleImmediately(): void
    {
        $locator = TableRegistry::getTableLocator();
        $date = self::inDays(20);
        $this->assertFalse($this->findUser($this->query->getUsersByRoomForEdit(1, $date), 1)->breakfast);

        $writer = new ReservationWriteService($locator->get('TIndividualReservationInfo'), $locator->get('MUserInfo'), $locator->get('MRoomInfo'), '/webroot/');
        $writer->processToggle(1, ['date' => $date, 'meal' => 1, 'value' => 1, 'userId' => 1], 1, '管理者ユーザー');

        $this->assertTrue($this->findUser($this->query->getUsersByRoomForEdit(1, $date), 1)->breakfast);
    }

    /** 異動で抜けた所属・削除済みのユーザーは、部屋別利用者一覧に含めない（#819） */
    public function testGetUsersByRoomForEdit_excludesWithdrawnAndDeletedUsers(): void
    {
        $locator = TableRegistry::getTableLocator();
        $locator->get('MUserGroup')->updateAll(['active_flag' => 1], ['i_id_user' => 2, 'i_id_room' => 1]);
        $locator->get('MUserInfo')->updateAll(['i_del_flag' => 1], ['i_id_user' => 3]);

        $ids = array_map(static fn(RoomUserMealStatus $u) => $u->userId, $this->query->getUsersByRoomForEdit(1, self::inDays(21)));

        $this->assertContains(1, $ids);
        $this->assertNotContains(2, $ids);
        $this->assertNotContains(3, $ids);
    }

    /** 他の部屋の予約は、この部屋の一覧に出さない */
    public function testGetUsersByRoomForEdit_ignoresOtherRoomsReservations(): void
    {
        $date = self::inDays(30);
        $this->insert(1, 2, $date, 1, 1, null);

        $this->assertFalse($this->findUser($this->query->getUsersByRoomForEdit(1, $date), 1)->breakfast);
    }

    public function testGetUsersByRoomForEdit_emptyRoom_returnsEmpty(): void
    {
        $this->assertSame([], $this->query->getUsersByRoomForEdit(9999, self::inDays(30)));
    }

    // ---- getExportJson ----

    public function testGetExportJson_emptyPeriod_returnsEmptyLists(): void
    {
        $this->assertSame(['overall' => [], 'rooms' => []], $this->query->getExportJson('2099-01-01', '2099-12-31')->toArray());
    }

    /** #647: 日付の列が Cake\I18n\Date で返っても型エラーにならず、fixture の 2024-09-07 を集計する */
    public function testGetExportJson_withCakeI18nDateColumn_doesNotThrow(): void
    {
        $result = $this->query->getExportJson('2024-01-01', '2024-12-31');

        $this->assertNotEmpty($result->overall, 'fixtureの2024-09-07データが集計結果に含まれること');
    }

    /** 部屋・日付・食事ごとに食べる人だけを数え、期間の終わりの日を含む */
    public function testGetExportJson_countsEatersPerRoomDateAndMeal(): void
    {
        $date = self::inDays(30);
        $this->insert(1, 1, $date, 1, 1, null);
        $this->insert(2, 1, $date, 1, 1, null);
        $this->insert(3, 1, $date, 3, 1, null);
        $this->insert(4, 1, $date, 2, 0, null);
        $this->insert(1, 1, $date, 9, 1, null);

        $result = $this->query->getExportJson($date, $date)->toArray();

        $counts = ['朝' => 2, '昼' => 0, '夜' => 1, '弁当' => 0];
        $this->assertSame(['Lorem ipsum dolor sit amet' => [$date => $counts]], $result['rooms']);
        $this->assertSame([['部屋名' => 'Lorem ipsum dolor sit amet', '日付' => $date] + $counts], $result['overall']);
    }

    // ---- getExportRank ----

    public function testGetExportRank_emptyPeriod_returnsEmpty(): void
    {
        $this->assertSame([], $this->query->getExportRank('2099-01-01', '2099-12-31'));
    }

    /** 年代・性別・日付ごとに数え、期間の終わりの日は含まない */
    public function testGetExportRank_countsPerRankGenderAndDate(): void
    {
        TableRegistry::getTableLocator()->get('MUserInfo')->updateAll(['i_user_rank' => 7, 'i_user_gender' => 2], ['i_id_user' => 1]);
        TableRegistry::getTableLocator()->get('MUserInfo')->updateAll(['i_user_rank' => 5, 'i_user_gender' => 1], ['i_id_user' => 2]);
        $date = self::inDays(30);
        $this->insert(1, 1, $date, 1, 1, null);
        $this->insert(1, 1, $date, 4, 1, null);
        $this->insert(1, 1, $date, 2, 0, null);
        $this->insert(1, 1, self::inDays(31), 1, 1, null);
        $this->insert(2, 1, $date, 3, 1, null);

        $rows = array_map(static fn($r) => $r->toArray(), $this->query->getExportRank($date, self::inDays(31)));

        // 並び順は決めていない（GROUP BY のまま）ので、性別で引いて比べる
        $this->assertCount(2, $rows);
        $byGender = array_column($rows, null, 'gender');
        $this->assertSame([
            'rank_name' => '大人', 'gender' => '女子', 'reservation_date' => $date,
            'breakfast' => 1, 'lunch' => 0, 'dinner' => 0, 'bento' => 1, 'total_eaters' => 2,
        ], $byGender['女子'] ?? null);
        $this->assertSame([
            'rank_name' => '中学生', 'gender' => '男子', 'reservation_date' => $date,
            'breakfast' => 0, 'lunch' => 0, 'dinner' => 1, 'bento' => 0, 'total_eaters' => 1,
        ], $byGender['男子'] ?? null);
    }

    // ---- getDailyMealCounts ----

    public function testGetDailyMealCounts_emptyPeriod_returnsEmpty(): void
    {
        $this->assertSame([], $this->query->getDailyMealCounts('2099-01-01', '2099-01-01'));
    }

    public function testGetDailyMealCounts_countsLastMinuteChangeFlagOnly(): void
    {
        $date = self::inDays(7);
        $this->insert(1, 1, $date, 2, 0, 1);

        $result = $this->query->getDailyMealCounts($date, $date);

        $this->assertSame([['date' => $date, 'morning' => 0, 'lunch' => 1, 'dinner' => 0, 'bento' => 0, 'total' => 1]], array_map(static fn($c) => $c->toArray(), $result));
    }

    /** 日付順に並べ、知らない食事の種類は数えず、部屋を指定すればその部屋だけを数える */
    public function testGetDailyMealCounts_sortsByDateAndFiltersRooms(): void
    {
        $d1 = self::inDays(30);
        $d2 = self::inDays(31);
        $this->insert(1, 1, $d2, 4, 1, null);
        $this->insert(1, 1, $d1, 3, 1, null);
        $this->insert(1, 1, $d1, 9, 1, null);
        $this->insert(2, 2, $d1, 1, 1, null);

        $all = array_map(static fn($c) => $c->toArray(), $this->query->getDailyMealCounts($d1, $d2));
        $room1 = array_map(static fn($c) => $c->toArray(), $this->query->getDailyMealCounts($d1, $d2, [1]));

        $this->assertSame([
            ['date' => $d1, 'morning' => 1, 'lunch' => 0, 'dinner' => 1, 'bento' => 0, 'total' => 2],
            ['date' => $d2, 'morning' => 0, 'lunch' => 0, 'dinner' => 0, 'bento' => 1, 'total' => 1],
        ], $all);
        $this->assertSame([
            ['date' => $d1, 'morning' => 0, 'lunch' => 0, 'dinner' => 1, 'bento' => 0, 'total' => 1],
            ['date' => $d2, 'morning' => 0, 'lunch' => 0, 'dinner' => 0, 'bento' => 1, 'total' => 1],
        ], $room1);
    }

    /** @param list<RoomUserMealStatus> $users */
    private function findUser(array $users, int $userId): RoomUserMealStatus
    {
        foreach ($users as $user) {
            if ($user->userId === $userId) {
                return $user;
            }
        }
        $this->fail('user ' . $userId . ' not found');
    }
}
