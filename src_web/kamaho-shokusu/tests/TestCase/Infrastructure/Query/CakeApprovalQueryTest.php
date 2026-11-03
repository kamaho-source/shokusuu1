<?php
declare(strict_types=1);

namespace App\Test\TestCase\Infrastructure\Query;

use App\Infrastructure\Query\CakeApprovalQuery;
use App\Infrastructure\Query\CakeRoomQuery;
use Cake\Chronos\ClockFactory;
use Cake\ORM\Table;
use Cake\TestSuite\TestCase;

/**
 * CakeApprovalQuery の一覧・件数・集計のテスト（旧 ApprovalServiceListTest から移した。期待値は同じ）。
 *
 * 前提データ（setUp で投入。日付はすべて過去日なので eat_flag がそのまま数えられる）:
 *   - ユーザー1,2 は大人(i_user_level=7)、ユーザー3 は子ども(i_user_level=1) = 一覧に出ない
 *   - ユーザー1,2,3,6 はブロック1に所属（ユーザー6は削除済み）
 */
class CakeApprovalQueryTest extends TestCase
{
    protected array $fixtures = [
        'app.TIndividualReservationInfo',
        'app.TApprovalLog',
        'app.MRoomInfo',
        'app.MUserInfo',
        'app.MUserGroup',
    ];

    private CakeApprovalQuery $query;
    private Table $individual;

    protected function setUp(): void
    {
        parent::setUp();
        $this->query = new CakeApprovalQuery(new CakeRoomQuery(), new ClockFactory('Asia/Tokyo'));
        $this->individual = $this->getTableLocator()->get('TIndividualReservationInfo');

        $this->getTableLocator()->get('MUserInfo')->updateAll(['i_user_level' => 7], ['i_id_user IN' => [1, 2]]);
        // 既存フィクスチャ(user1 / 2024-09-07 / 朝)は未承認のまま使う
        $this->add(2, '2024-09-07', 1, 1, 0);          // 未承認
        $this->add(2, '2024-09-08', 1, 3, 1);          // ブロック長承認済
        $this->add(1, '2024-09-09', 1, 1, 2);          // 最終承認済 食べる
        $this->add(2, '2024-09-09', 1, 1, 2, 0);       // 最終承認済 食べない
        $this->add(1, '2024-09-09', 1, 3, 2);          // 最終承認済 夕
        $this->add(2, '2024-09-10', 1, 2, 2);          // 最終承認済 昼
        $this->add(2, '2024-09-11', 1, 4, 2);          // 最終承認済 弁当
        $this->add(3, '2024-09-09', 1, 1, 0);          // 子ども（対象外）
        $this->add(6, '2024-09-09', 1, 1, 2);          // 削除済みユーザー（反映対象外）
    }

    private function add(int $userId, string $date, int $roomId, int $type, int $status, int $eat = 1): void
    {
        $this->individual->saveOrFail($this->individual->newEntity([
            'i_id_user' => $userId,
            'd_reservation_date' => $date,
            'i_id_room' => $roomId,
            'i_reservation_type' => $type,
            'eat_flag' => $eat,
            'dt_create' => '2024-09-01 00:00:00',
            'c_create_user' => 't',
            'dt_update' => '2024-09-01 00:00:00',
            'c_update_user' => 't',
        ]));
        // i_approval_status は一括代入できない項目なので別途更新する
        $this->individual->updateAll(['i_approval_status' => $status], [
            'i_id_user' => $userId,
            'd_reservation_date' => $date,
            'i_id_room' => $roomId,
            'i_reservation_type' => $type,
        ]);
    }

    /**
     * @param array<int, object> $rows
     * @return list<string> 「ユーザーID:日付:食種」
     */
    private function ids(array $rows): array
    {
        return array_map(
            fn($r) => $r->i_id_user . ':' . $r->d_reservation_date->format('Y-m-d') . ':' . $r->i_reservation_type,
            $rows
        );
    }

    public function testGetBlockLeaderList_defaultsToPendingAdultsInOwnRooms(): void
    {
        $this->assertSame(['1:2024-09-07:1', '2:2024-09-07:1'], $this->ids($this->query->getBlockLeaderList(1)));
    }

    public function testGetBlockLeaderList_filtersByStatusAndDateRange(): void
    {
        $this->assertSame(['2:2024-09-08:3'], $this->ids($this->query->getBlockLeaderList(1, null, null, null, 1)));
        $this->assertCount(2, $this->query->getBlockLeaderList(1, null, '2024-09-07', '2024-09-07', 0));
        $this->assertSame([], $this->query->getBlockLeaderList(1, null, '2024-09-08', '2024-09-30'));
        $this->assertSame([], $this->query->getBlockLeaderList(1, null, '2024-08-01', '2024-09-06'));
    }

    public function testGetBlockLeaderList_roomFilterOnlyAppliesToOwnRooms(): void
    {
        $this->assertCount(2, $this->query->getBlockLeaderList(1, 1));
        // 担当外のブロック指定は無視して担当ブロック全体を返す（他ブロックへ広げない）
        $this->assertCount(2, $this->query->getBlockLeaderList(1, 99));
    }

    public function testGetBlockLeaderList_userWithoutRooms_returnsEmpty(): void
    {
        $this->assertSame([], $this->query->getBlockLeaderList(4));
        $this->assertSame(0, $this->query->countBlockLeaderPending(4));
    }

    public function testGetAdminList_filtersAndExcludesChildren(): void
    {
        $this->assertCount(8, $this->query->getAdminList());
        $this->assertCount(5, $this->query->getAdminList(null, null, null, 2));
        $this->assertCount(0, $this->query->getAdminList(99));
        $this->assertCount(8, $this->query->getAdminList(1));
        $this->assertSame(
            ['1:2024-09-09:1', '1:2024-09-09:3', '2:2024-09-09:1'],
            $this->ids($this->query->getAdminList(1, '2024-09-09', '2024-09-09'))
        );
    }

    public function testCountBlockLeaderPending_countsPendingAdultsWithinRange(): void
    {
        $this->assertSame(2, $this->query->countBlockLeaderPending(1));
        $this->assertSame(0, $this->query->countBlockLeaderPending(1, '2024-09-08'));
        $this->assertSame(0, $this->query->countBlockLeaderPending(1, null, '2024-09-06'));
        $this->assertSame(2, $this->query->countBlockLeaderPending(1, '2024-09-07', '2024-09-07'));
    }

    public function testCountAdminPending_countsBlockLeaderApprovedOnly(): void
    {
        $this->assertSame(1, $this->query->countAdminPending());
        $this->assertSame(1, $this->query->countAdminPending('2024-09-08', '2024-09-08'));
        $this->assertSame(0, $this->query->countAdminPending('2024-09-09'));
        $this->assertSame(0, $this->query->countAdminPending(null, '2024-09-07'));
    }

    public function testGetAdminSummary_countsOnlyEatingFinalApprovedPerDayAndRoom(): void
    {
        $room = 'Lorem ipsum dolor sit amet';

        // 09-09 の朝食は user1・user6 の 2 件（user2 は食べない）。サマリは削除済みユーザーを除外しない
        $this->assertSame([
            ['reservation_date' => '2024-09-09', 'room_name' => $room, 'breakfast' => 2, 'lunch' => 0, 'dinner' => 1, 'bento' => 0],
            ['reservation_date' => '2024-09-10', 'room_name' => $room, 'breakfast' => 0, 'lunch' => 1, 'dinner' => 0, 'bento' => 0],
            ['reservation_date' => '2024-09-11', 'room_name' => $room, 'breakfast' => 0, 'lunch' => 0, 'dinner' => 0, 'bento' => 1],
        ], $this->query->getAdminSummary());
    }

    public function testGetAdminSummary_dateRange(): void
    {
        $summary = $this->query->getAdminSummary('2024-09-10', '2024-09-10');
        $this->assertCount(1, $summary);
        $this->assertSame(1, $summary[0]['lunch']);
        $this->assertSame([], $this->query->getAdminSummary('2025-01-01'));
    }

    /**
     * 承認履歴の表示範囲: 管理者は全部、ブロック長は担当部屋の履歴と自分が承認した履歴（新しい順）。
     */
    public function testApprovalLogs_scopeByRole(): void
    {
        // 履歴は部屋・承認者と内部結合するので、部屋2 を作っておく
        $rooms = $this->getTableLocator()->get('MRoomInfo');
        $rooms->saveOrFail($rooms->newEntity([
            'i_id_room' => 2, 'c_room_name' => '部屋2', 'i_disp_no' => 2, 'i_enable' => 1, 'i_del_flg' => 0,
            'dt_create' => '2024-09-01 00:00:00', 'c_create_user' => 't', 'dt_update' => '2024-09-01 00:00:00', 'c_update_user' => 't',
        ], ['accessibleFields' => ['*' => true]]));
        $logs = $this->getTableLocator()->get('TApprovalLog');
        foreach ([[1, 1, '2024-09-01 10:00:00'], [2, 2, '2024-09-02 10:00:00'], [2, 1, '2024-09-03 10:00:00']] as [$roomId, $approverId, $at]) {
            $logs->saveOrFail($logs->newEntity([
                'i_id_user' => 1, 'd_reservation_date' => '2024-09-07', 'i_id_room' => $roomId, 'i_reservation_type' => 1,
                'i_approval_status' => 1, 'i_approver_id' => $approverId, 'dt_create' => $at,
            ]));
        }
        $pairs = fn(iterable $rows): array => array_map(
            fn($r) => $r->i_id_room . ':' . $r->i_approver_id,
            is_array($rows) ? $rows : iterator_to_array($rows, false)
        );

        $this->assertSame(['2:1', '2:2', '1:1'], $pairs($this->query->approvalLogs(1, true, [])));
        // ユーザー2 は部屋1の担当（所属）。部屋2 は自分が承認した分だけ
        $this->assertSame(['2:2', '1:1'], $pairs($this->query->approvalLogs(2, false, [])));
    }
}
