<?php
declare(strict_types=1);

namespace App\Test\TestCase\Service;

use App\Service\MealReportingService;
use Cake\Datasource\ConnectionManager;
use Cake\I18n\Date;
use Cake\ORM\Table;
use Cake\ORM\TableRegistry;
use Cake\TestSuite\TestCase;

/**
 * MealReportingService テスト。
 *
 * 当日の「食べる」「食べない」報告が、食事ごとに別の部屋を選べる予約と二重にならないことを検証する。
 * user 1 は部屋1（代表部屋）と部屋60に所属する。
 */
class MealReportingServiceTest extends TestCase
{
    protected array $fixtures = [
        'app.MUserInfo',
        'app.MUserGroup',
        'app.MRoomInfo',
        'app.TIndividualReservationInfo',
    ];

    private MealReportingService $service;
    private Table $reservationTable;
    private Table $userGroupTable;
    private string $today;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service          = new MealReportingService();
        $this->reservationTable = TableRegistry::getTableLocator()->get('TIndividualReservationInfo');
        $this->userGroupTable   = TableRegistry::getTableLocator()->get('MUserGroup');
        $this->today            = Date::today('Asia/Tokyo')->format('Y-m-d');

        $db = ConnectionManager::get('test');
        $db->insert('m_room_info', [
            'i_id_room' => 60, 'c_room_name' => '部屋60', 'i_disp_no' => 60, 'i_enable' => 1, 'i_del_flg' => 0,
        ]);
        $db->insert('m_user_group', [
            'i_id_user' => 1, 'i_id_room' => 60, 'active_flag' => 0,
            'dt_create' => '2026-01-01 00:00:00', 'c_create_user' => 't', 'dt_update' => '2026-01-01 00:00:00', 'c_update_user' => 't',
        ]);
    }

    private function insertMeal(int $roomId, int $mealType, int $eat, int $chg): void
    {
        ConnectionManager::get('test')->insert('t_individual_reservation_info', [
            'i_id_user'          => 1,
            'd_reservation_date' => $this->today,
            'i_reservation_type' => $mealType,
            'i_id_room'          => $roomId,
            'eat_flag'           => $eat,
            'i_change_flag'      => $chg,
            'i_approval_status'  => 0,
            'i_version'          => 1,
            'dt_create'          => '2026-01-01 00:00:00',
            'c_create_user'      => 'test',
        ]);
    }

    /** @return array<string, array{int, int}> ["roomId:mealType" => [eat_flag, i_change_flag]] */
    private function flags(): array
    {
        $rows = $this->reservationTable->find()
            ->where(['i_id_user' => 1, 'd_reservation_date' => $this->today])
            ->orderByAsc('i_id_room')
            ->orderByAsc('i_reservation_type')
            ->all();
        $map = [];
        foreach ($rows as $r) {
            $map[(int)$r->i_id_room . ':' . (int)$r->i_reservation_type] = [(int)$r->eat_flag, (int)$r->i_change_flag];
        }

        return $map;
    }

    /** 別の部屋ですでに有効な夕食は、代表部屋に付けない（二重にならない） */
    public function testReportEat_doesNotAddMealAlreadyEffectiveInAnotherRoom(): void
    {
        $this->insertMeal(1, 2, 0, 0);  // 昼（代表部屋）
        $this->insertMeal(1, 4, 0, 0);  // 弁当（代表部屋）
        $this->insertMeal(60, 3, 0, 1); // 夕食は部屋60で有効（直前追加）

        $result = $this->service->reportEat($this->reservationTable, $this->userGroupTable, 1, '管理者ユーザー');

        $this->assertTrue($result['ok'], $result['message']);
        $flags = $this->flags();
        $this->assertArrayNotHasKey('1:3', $flags, '別の部屋で有効な夕食が代表部屋にも付いている');
        $this->assertSame([0, 1], $flags['60:3']);
        $this->assertSame([1, 1], $flags['1:1']);
    }

    /** 「食べない」は代表部屋以外の部屋の有効な予約も取り消し、行が無い食事は代表部屋に 0/0 の行を作る */
    public function testReportNoMeal_cancelsAllRoomsAndCreatesMissingRowsInPrimaryRoom(): void
    {
        $this->insertMeal(1, 1, 1, 1);  // 朝（代表部屋）
        $this->insertMeal(60, 3, 0, 1); // 夕食（部屋60）

        $result = $this->service->reportNoMeal($this->reservationTable, $this->userGroupTable, 1, '管理者ユーザー');

        $this->assertTrue($result['ok'], $result['message']);
        $this->assertSame([
            '1:1'  => [0, 0],
            '1:2'  => [0, 0],
            '1:4'  => [0, 0],
            '60:3' => [0, 0],
        ], $this->flags());
    }

    public function testReportNoMeal_leavesApprovedRowsUnchanged(): void
    {
        $this->insertMeal(60, 3, 1, 1);
        ConnectionManager::get('test')->update('t_individual_reservation_info', ['i_approval_status' => 2], ['i_id_user' => 1, 'i_id_room' => 60]);

        $this->service->reportNoMeal($this->reservationTable, $this->userGroupTable, 1, '管理者ユーザー');

        $this->assertSame([1, 1], $this->flags()['60:3']);
    }

    public function testReportEat_withNoRows_registersBreakfastLunchDinnerButNotBento(): void
    {
        $result = $this->service->reportEat($this->reservationTable, $this->userGroupTable, 1, '管理者ユーザー');

        $this->assertTrue($result['ok'], $result['message']);
        $flags = $this->flags();
        $this->assertSame([1, 1], $flags['1:1']);
        $this->assertSame([1, 1], $flags['1:2']);
        $this->assertSame([1, 1], $flags['1:3']);
        $this->assertArrayNotHasKey('1:4', $flags);
    }

    public function testReportEat_keepsBentoAndDoesNotAddLunch(): void
    {
        $this->insertMeal(1, 4, 1, 1);

        $result = $this->service->reportEat($this->reservationTable, $this->userGroupTable, 1, '管理者ユーザー');

        $this->assertTrue($result['ok'], $result['message']);
        $flags = $this->flags();
        $this->assertSame([1, 1], $flags['1:4']);
        $this->assertArrayNotHasKey('1:2', $flags);
    }
}
