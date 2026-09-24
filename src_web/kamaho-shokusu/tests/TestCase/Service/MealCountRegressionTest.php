<?php
declare(strict_types=1);

namespace App\Test\TestCase\Service;

use App\Domain\Exception\ConflictException;
use App\Service\ReservationCopyService;
use App\Service\ReservationReportService;
use App\Service\ReservationWriteService;
use Cake\Datasource\ConnectionManager;
use Cake\I18n\Date;
use Cake\I18n\DateTime;
use Cake\ORM\TableRegistry;
use Cake\TestSuite\TestCase;

/**
 * 食数の計上・取消・集計に関するリグレッションテスト。
 *
 * 2026-09-23 の調査で再現したバグ（docs/meal-count-bug-review-2026-09-23.md）が
 * 再発しないことを確認する。各テストは修正前は失敗する。
 */
class MealCountRegressionTest extends TestCase
{
    protected array $fixtures = [
        'app.TIndividualReservationInfo',
        'app.MRoomInfo',
        'app.MUserInfo',
        'app.MUserGroup',
    ];

    private ReservationWriteService $service;
    private $table;

    public function setUp(): void
    {
        parent::setUp();
        $this->table = TableRegistry::getTableLocator()->get(
            'TIndividualReservationInfo',
            ['table' => 't_individual_reservation_info']
        );
        $this->service = new ReservationWriteService(
            $this->table,
            TableRegistry::getTableLocator()->get('MUserInfo', ['table' => 'm_user_info']),
            TableRegistry::getTableLocator()->get('MRoomInfo', ['table' => 'm_room_info']),
            '/webroot/'
        );
    }

    // ---- ヘルパー -----------------------------------------------------------

    /** 直前編集ウィンドウ内(今日+14日以内) */
    private function lastMinuteDate(): string
    {
        return Date::today('Asia/Tokyo')->addDays(3)->format('Y-m-d');
    }

    /** 通常予約期間(今日+15日以降) */
    private function normalDate(): string
    {
        return Date::today('Asia/Tokyo')->addDays(30)->format('Y-m-d');
    }

    private function rooms(): array
    {
        return [1 => ['i_id_room' => 1, 'c_room_name' => 'テスト部屋']];
    }

    private function insert(array $override): void
    {
        $now = DateTime::now('Asia/Tokyo')->format('Y-m-d H:i:s');
        ConnectionManager::get('test')->insert('t_individual_reservation_info', array_merge([
            'i_id_user'          => 1,
            'i_reservation_type' => 1,
            'i_id_room'          => 1,
            'i_version'          => 1,
            'c_create_user'      => 'test',
            'dt_create'          => $now,
        ], $override));
    }

    private function fetch(string $date, int $meal = 1, int $room = 1): ?object
    {
        return $this->table->find()->where([
            'i_id_user'          => 1,
            'd_reservation_date' => $date,
            'i_reservation_type' => $meal,
            'i_id_room'          => $room,
        ])->first();
    }

    private function save(string $date, array $meals): array
    {
        return $this->service->processIndividualReservation(
            $date,
            json_encode(['meals' => $meals]),
            $this->rooms(),
            1,
            'テストユーザー',
            fn($d) => true
        );
    }

    // ---- A: 直前追加した予約を取り消せる -----------------------------------

    public function testLastMinuteAddedReservationCanBeCancelled(): void
    {
        $date = $this->lastMinuteDate();
        // 直前トグルで追加された行: eat_flag=0 / i_change_flag=1
        $this->insert(['d_reservation_date' => $date, 'eat_flag' => 0, 'i_change_flag' => 1]);

        $this->save($date, ['1' => ['1' => 0]]);

        $row = $this->fetch($date);
        $this->assertSame(0, (int)$row->i_change_flag, '直前追加した予約が取り消せていない');
        $this->assertSame(0, (int)$row->eat_flag, '発注済みでない行の eat_flag が変わっている');
    }

    // ---- B: 直前キャンセル後に再予約すると戻る -----------------------------

    public function testReReserveAfterLastMinuteCancelRestoresMeal(): void
    {
        $date = $this->lastMinuteDate();
        // 直前キャンセル済み行: eat_flag=1(発注済み) / i_change_flag=0
        $this->insert(['d_reservation_date' => $date, 'eat_flag' => 1, 'i_change_flag' => 0]);

        $this->save($date, ['1' => ['1' => 1]]);

        $row = $this->fetch($date);
        $this->assertSame(1, (int)$row->i_change_flag, '再予約しても食数に戻っていない');
        $this->assertSame(1, (int)$row->eat_flag, '発注済みの eat_flag は保持されるべき');
    }

    // ---- 直前期間では発注済みの eat_flag を書き換えない ---------------------

    public function testLastMinuteCancelKeepsOrderedEatFlag(): void
    {
        $date = $this->lastMinuteDate();
        $this->insert(['d_reservation_date' => $date, 'eat_flag' => 1, 'i_change_flag' => 1]);

        $this->save($date, ['1' => ['1' => 0]]);

        $row = $this->fetch($date);
        $this->assertSame(0, (int)$row->i_change_flag, '直前キャンセルが反映されていない');
        $this->assertSame(1, (int)$row->eat_flag, '発注済みの eat_flag を書き換えてはいけない');
    }

    // ---- C: 昼食と弁当を同時に有効化できない -------------------------------

    public function testLunchAndBentoCannotBothBeEnabled(): void
    {
        $date = $this->normalDate();
        $this->insert(['d_reservation_date' => $date, 'i_reservation_type' => 2, 'eat_flag' => 0, 'i_change_flag' => 0]);
        $this->insert(['d_reservation_date' => $date, 'i_reservation_type' => 4, 'eat_flag' => 0, 'i_change_flag' => 0]);

        $this->expectException(ConflictException::class);
        $this->save($date, ['2' => ['1' => 1], '4' => ['1' => 1]]);
    }

    // ---- D: 別部屋の同一食事は二重計上されない -----------------------------

    public function testToggleRejectsSameMealInAnotherRoom(): void
    {
        $conn = ConnectionManager::get('test');
        $now  = DateTime::now('Asia/Tokyo')->format('Y-m-d H:i:s');
        $conn->insert('m_room_info', [
            'i_id_room' => 2, 'c_room_name' => '部屋B', 'i_disp_no' => 2,
            'i_enable' => 1, 'i_del_flg' => 0, 'dt_create' => $now, 'c_create_user' => 'test',
        ]);
        $conn->insert('m_user_group', [
            'i_id_user' => 1, 'i_id_room' => 2, 'active_flag' => 0,
            'dt_create' => $now, 'c_create_user' => 'test',
        ]);

        $date = $this->normalDate();
        $this->insert(['d_reservation_date' => $date, 'i_id_room' => 1, 'eat_flag' => 1, 'i_change_flag' => 1]);

        $this->expectException(ConflictException::class);
        $this->service->processToggle(
            2,
            ['userId' => 1, 'date' => $date, 'meal' => 1, 'value' => 1],
            1,
            'テストユーザー'
        );
    }

    public function testMealCountsDoNotDoubleCountSameUserAcrossRooms(): void
    {
        $date = $this->normalDate();
        $this->insert(['d_reservation_date' => $date, 'i_id_room' => 1, 'eat_flag' => 1, 'i_change_flag' => 1]);
        $this->insert(['d_reservation_date' => $date, 'i_id_room' => 2, 'eat_flag' => 1, 'i_change_flag' => 1]);

        $counts = (new ReservationReportService())->getMealCounts($this->table, $date);

        $this->assertSame(1, (int)$counts[0]['count'], '同一人物が2食として集計されている');
    }

    // ---- E: 集計と画面の判定基準が一致する ---------------------------------

    public function testCopyDoesNotProduceDivergentFlags(): void
    {
        $today = Date::today('Asia/Tokyo');
        // 直前キャンセル済みの行(eat_flag=1 / i_change_flag=0 = その日は食べない)
        $src       = $today->addDays(3);
        $srcMonday = $src->subDays(((int)$src->format('N')) - 1);
        $this->insert(['d_reservation_date' => $src->format('Y-m-d'), 'eat_flag' => 1, 'i_change_flag' => 0]);

        // 4週間後(通常予約期間)へ週コピー
        (new ReservationCopyService())->copyWeek($srcMonday, $srcMonday->addDays(28), 1, false, null, false);

        $dst = $src->addDays(28)->format('Y-m-d');
        $row = $this->fetch($dst);
        $this->assertNotNull($row, 'コピーされていない');
        $this->assertSame(
            (int)$row->eat_flag,
            (int)$row->i_change_flag,
            '通常予約期間へ eat_flag と i_change_flag が食い違う行が作られている'
        );

        $counts = (new ReservationReportService())->getMealCounts($this->table, $dst);
        $this->assertSame([], $counts, '「食べない」予約が集計に出ている');
    }

    // ---- F: 過去日は変更できない -------------------------------------------

    public function testPastDateIsRejectedByLastMinuteValidator(): void
    {
        $policy = new \App\Service\ReservationDatePolicy();
        $past   = Date::today('Asia/Tokyo')->subDays(10)->format('Y-m-d');

        $this->assertTrue($policy->isPastDate($past), '過去日判定が働いていない');
        $this->assertFalse($policy->isPastDate($this->lastMinuteDate()), '直前期間が過去日扱いされている');
    }
}
