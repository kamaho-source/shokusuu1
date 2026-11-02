<?php
declare(strict_types=1);

namespace App\Test\TestCase\Application\UseCase\ReportMeal;

use App\Application\UseCase\ReportNoMeal\ReportNoMealInput;
use App\Domain\Exception\DomainException;
use App\Infrastructure\ReservationUseCases;
use Cake\Datasource\ConnectionManager;
use Cake\I18n\Date;
use Cake\ORM\TableRegistry;
use Cake\TestSuite\TestCase;

/**
 * 今日の食事の「食べない」の報告（ReportNoMealUseCase）を実際の DB で確かめる。旧 MealReportingServiceTest を引き継ぐ。
 *
 * 当日の「食べる」「食べない」報告が、食事ごとに別の部屋を選べる予約と二重にならないことを検証する。
 * user 1 は部屋1（代表部屋）と部屋60に所属する。
 */
class ReportMealIntegrationTest extends TestCase
{
    protected array $fixtures = [
        'app.MUserInfo',
        'app.MUserGroup',
        'app.MRoomInfo',
        'app.TIndividualReservationInfo',
    ];

    private ReservationUseCases $useCases;
    private \App\Infrastructure\Persistence\Table\TIndividualReservationInfoTable $reservationTable;
    private string $today;

    protected function setUp(): void
    {
        parent::setUp();
        $this->reservationTable = TableRegistry::getTableLocator()->get('TIndividualReservationInfo');
        $this->useCases         = new ReservationUseCases($this->reservationTable, TableRegistry::getTableLocator()->get('MUserInfo'));
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

    /** 「食べない」で報告する。失敗したら ['ok' => false, 'message' => …, 'status' => …] を返す */
    private function noMeal(int $userId = 1): array
    {
        try {
            $out = $this->useCases->reportNoMeal()->execute(new ReportNoMealInput($userId, '管理者ユーザー'));
        } catch (DomainException $e) {
            return ['ok' => false, 'message' => $e->getMessage(), 'status' => $e->getStatusCode(), 'exception' => $e];
        }

        return ['ok' => true, 'message' => $out->message, 'approvedUnchanged' => $out->approvedUnchanged];
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


    /** 「食べない」は代表部屋以外の部屋の有効な予約も取り消し（当日は直前期間なので i_change_flag だけ 0 にし、eat_flag は残す）、行が無い食事は代表部屋に 0/0 の行を作る */
    public function testReportNoMeal_cancelsAllRoomsAndCreatesMissingRowsInPrimaryRoom(): void
    {
        $this->insertMeal(1, 1, 1, 1);  // 朝（代表部屋）
        $this->insertMeal(60, 3, 0, 1); // 夕食（部屋60）

        $result = $this->noMeal();

        $this->assertTrue($result['ok'], $result['message']);
        $this->assertSame([
            '1:1'  => [1, 0],
            '1:2'  => [0, 0],
            '1:4'  => [0, 0],
            '60:3' => [0, 0],
        ], $this->flags());
    }

    public function testReportNoMeal_leavesApprovedRowsUnchanged(): void
    {
        $this->insertMeal(60, 3, 1, 1);
        ConnectionManager::get('test')->update('t_individual_reservation_info', ['i_approval_status' => 2], ['i_id_user' => 1, 'i_id_room' => 60]);

        $result = $this->noMeal();

        $this->assertSame([1, 1], $this->flags()['60:3']);
        $this->assertStringContainsString('承認済みの食事は変更されていません', $result['message']);
    }





    /** 所属部屋が無い（抜けた・削除済みの部屋だけ）と、報告せずに 400 を返す */
    public function testReport_withoutActiveRoom_returns400(): void
    {
        $db = ConnectionManager::get('test');
        $db->update('m_user_group', ['active_flag' => 1], ['i_id_user' => 1, 'i_id_room' => 1]);
        $db->update('m_room_info', ['i_del_flg' => 1], ['i_id_room' => 60]);

        foreach ([$this->noMeal()] as $result) {
            $this->assertFalse($result['ok']);
            $this->assertSame(400, $result['status']);
            $this->assertSame('所属部屋が設定されていません。管理者にお問い合わせください。', $result['message']);
        }
        $this->assertSame([], $this->flags());
    }

    /** 代表部屋は、所属中で削除されていない部屋のうち ID の小さい部屋（部屋1 が削除済みなら部屋60） */
    public function testReport_primaryRoomSkipsDeletedRoom(): void
    {
        ConnectionManager::get('test')->update('m_room_info', ['i_del_flg' => 1], ['i_id_room' => 1]);

        $this->assertTrue($this->noMeal()['ok']);

        $this->assertSame(['60:1' => [0, 0], '60:2' => [0, 0], '60:3' => [0, 0], '60:4' => [0, 0]], $this->flags());
    }

    /** 更新した行は版を上げる（同時に開いていた画面からの古い保存を競合として止める）。新しい行は版 1 */
    public function testReport_bumpsVersionOfUpdatedRows(): void
    {
        $this->insertMeal(1, 1, 1, 1);

        $this->assertTrue($this->noMeal()['ok']);
        $versions = $this->reservationTable->find('list', keyField: 'i_reservation_type', valueField: 'i_version')
            ->where(['i_id_user' => 1, 'd_reservation_date' => $this->today])->toArray();
        $this->assertSame([1 => 2, 2 => 1, 3 => 1, 4 => 1], array_map('intval', $versions));

    }

    /** 報告するとその日の食数のキャッシュを消す */
    public function testReport_forgetsMealCountsCache(): void
    {
        $key = \App\Infrastructure\Cache\ReservationCache::mealCountsKey($this->today);
        foreach (['noMeal'] as $action) {
            \Cake\Cache\Cache::write($key, ['stale'], 'default');
            $this->assertTrue($this->{$action}()['ok']);
            $this->assertNull(\Cake\Cache\Cache::read($key, 'default'), $action);
        }
    }

    /** 予約の行の追加を DB で失敗させる（SQLite のトリガー）。テストの終わりに消す。 */
    private function failInserts(): void
    {
        ConnectionManager::get('test')->execute(
            "CREATE TRIGGER ct_fail_insert BEFORE INSERT ON t_individual_reservation_info BEGIN SELECT RAISE(ABORT, 'forced failure'); END"
        );
    }

    protected function tearDown(): void
    {
        ConnectionManager::get('test')->execute('DROP TRIGGER IF EXISTS ct_fail_insert');
        parent::tearDown();
    }

    /** 「食べない」で行の追加が DB で失敗したら、先にした取消も残さず、SQL を含まない 500 を返す */
    public function testReportNoMeal_dbFailure_rollsBackAndReturns500(): void
    {
        $this->insertMeal(1, 1, 1, 1);
        $this->failInserts();

        $result = $this->noMeal();

        $this->assertFalse($result['ok']);
        $this->assertSame(500, $result['status'] ?? null);
        $this->assertSame('報告の保存に失敗しました（システムエラー）。管理者にお問い合わせください。', $result['message']);
        $this->assertSame(['1:1' => [1, 1]], $this->flags(), '取消が残っている');
    }

}
