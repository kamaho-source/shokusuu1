<?php
declare(strict_types=1);

namespace App\Test\TestCase\Service;

use App\Service\ReservationBulkService;
use App\Service\ReservationDatePolicy;
use Cake\Datasource\ConnectionManager;
use Cake\I18n\DateTime;
use Cake\ORM\TableRegistry;
use Cake\TestSuite\TestCase;

/**
 * ReservationBulkService のテスト
 *
 * 主な確認項目:
 *   - 通常予約でチェックON → eat_flag=1, i_change_flag=1 で新規作成
 *   - 通常予約でチェックOFF → eat_flag=0, i_change_flag=0 に更新
 */
class ReservationBulkServiceTest extends TestCase
{
    protected array $fixtures = [
        'app.TIndividualReservationInfo',
        'app.MRoomInfo',
        'app.MUserInfo',
    ];

    private ReservationBulkService $service;

    /** @var \Cake\ORM\Table */
    private $reservationTable;

    /** @var \Cake\ORM\Table */
    private $userTable;

    /** @var \Cake\ORM\Table */
    private $roomTable;

    // 日付バリデーションを常に通過させるポリシーモック
    private function makeDatePolicyMock(): ReservationDatePolicy
    {
        $mock = $this->getMockBuilder(ReservationDatePolicy::class)
            ->onlyMethods(['validateReservationDate', 'isPastDate'])
            ->getMock();
        $mock->method('validateReservationDate')->willReturn(true);
        $mock->method('isPastDate')->willReturn(false);
        return $mock;
    }

    public function setUp(): void
    {
        parent::setUp();

        $this->service = new ReservationBulkService($this->makeDatePolicyMock());

        $this->reservationTable = TableRegistry::getTableLocator()->get(
            'TIndividualReservationInfo',
            ['table' => 't_individual_reservation_info']
        );
        $this->userTable = TableRegistry::getTableLocator()->get(
            'MUserInfo',
            ['table' => 'm_user_info']
        );
        $this->roomTable = TableRegistry::getTableLocator()->get(
            'MRoomInfo',
            ['table' => 'm_room_info']
        );
    }

    // ---------------------------------------------------------------------------
    // ヘルパー
    // ---------------------------------------------------------------------------

    /** テスト用の有効予約レコードを直接挿入する */
    private function insertReservation(array $override = []): void
    {
        $now = DateTime::now('Asia/Tokyo')->format('Y-m-d H:i:s');
        $default = [
            'i_id_user'          => 1,
            'd_reservation_date' => '2026-06-01',
            'i_reservation_type' => 1,
            'i_id_room'          => 1,
            'eat_flag'           => 1,
            'i_change_flag'      => 1,
            'i_version'          => 1,
            'c_create_user'      => 'test',
            'dt_create'          => $now,
            'c_update_user'      => 'test',
            'dt_update'          => $now,
        ];
        ConnectionManager::get('test')->insert(
            't_individual_reservation_info',
            array_merge($default, $override)
        );
    }

    /** 予約レコードを取得する */
    private function fetchReservation(
        int $userId,
        string $date,
        int $mealType,
        int $roomId
    ): ?object {
        return $this->reservationTable->find()
            ->where([
                'i_id_user'          => $userId,
                'd_reservation_date' => $date,
                'i_reservation_type' => $mealType,
                'i_id_room'          => $roomId,
            ])
            ->first();
    }

    /** processBulkAdd を group モードで呼び出す共通ヘルパー */
    private function callBulkAdd(array $dayUsers, int $roomId = 1, bool $isAdmin = true): array
    {
        return $this->service->processBulkAdd(
            [
                'reservation_type' => 'group',
                'i_id_room'        => (string)$roomId,
                'day_users'        => $dayUsers,
            ],
            99,
            'tester',
            $this->reservationTable,
            $this->userTable,
            $this->roomTable,
            $isAdmin
        );
    }

    // ---------------------------------------------------------------------------
    // テストケース
    // ---------------------------------------------------------------------------

    /**
     * チェックON（新規）→ eat_flag=1, i_change_flag=1 で作成される
     */
    public function testCheckOnCreatesReservationWithEatFlagOne(): void
    {
        $result = $this->callBulkAdd([
            '2026-06-01' => ['1' => ['1' => '1']],
        ]);

        $this->assertTrue($result['ok'], $result['message'] ?? '');

        $row = $this->fetchReservation(1, '2026-06-01', 1, 1);
        $this->assertNotNull($row, 'レコードが作成されていない');
        $this->assertSame(1, (int)$row->eat_flag, 'eat_flag が 1 でない');
        $this->assertSame(1, (int)$row->i_change_flag, 'i_change_flag が 1 でない');
    }

    /**
     * チェックOFF（既存の有効予約あり）→ eat_flag=0, i_change_flag=0 に更新される
     */
    public function testCheckOffDeactivatesExistingReservation(): void
    {
        // フィクスチャのレコード（i_id_user=1, date=2024-09-07）は eat_flag=1 で登録済み
        // テスト用に別途 2026-06-01 のレコードを挿入
        $this->insertReservation([
            'i_id_user'          => 1,
            'd_reservation_date' => '2026-06-01',
            'i_reservation_type' => 2,
            'i_id_room'          => 1,
            'eat_flag'           => 1,
            'i_change_flag'      => 1,
        ]);

        $result = $this->callBulkAdd([
            '2026-06-01' => ['1' => ['2' => '0']],
        ]);

        $this->assertTrue($result['ok'], $result['message'] ?? '');

        $row = $this->fetchReservation(1, '2026-06-01', 2, 1);
        $this->assertNotNull($row, 'レコードが見つからない');
        $this->assertSame(0, (int)$row->eat_flag, 'eat_flag が 0 になっていない');
        $this->assertSame(0, (int)$row->i_change_flag, 'i_change_flag が 0 になっていない');
    }

    /**
     * チェックOFF（既存の有効予約なし）→ 何もしない（エラーにならない）
     */
    public function testCheckOffWithNoExistingReservationIsNoOp(): void
    {
        $result = $this->callBulkAdd([
            '2026-06-02' => ['1' => ['3' => '0']],
        ]);

        $this->assertTrue($result['ok'], $result['message'] ?? '');

        $row = $this->fetchReservation(1, '2026-06-02', 3, 1);
        $this->assertNull($row, '存在しないはずのレコードが作成されている');
    }

    /**
     * 同一ユーザー・日付で ON/OFF 混在 → ON は新規作成、OFF は非活性化
     */
    public function testMixedCheckOnAndOffInSameSubmit(): void
    {
        // 朝(1) は既存予約あり → OFF でキャンセル
        $this->insertReservation([
            'i_id_user'          => 1,
            'd_reservation_date' => '2026-06-03',
            'i_reservation_type' => 1,
            'i_id_room'          => 1,
            'eat_flag'           => 1,
            'i_change_flag'      => 1,
        ]);

        // 昼(2) は未予約 → ON で新規作成
        $result = $this->callBulkAdd([
            '2026-06-03' => ['1' => ['1' => '0', '2' => '1']],
        ]);

        $this->assertTrue($result['ok'], $result['message'] ?? '');

        // 朝 → 非活性化
        $morning = $this->fetchReservation(1, '2026-06-03', 1, 1);
        $this->assertNotNull($morning);
        $this->assertSame(0, (int)$morning->eat_flag, '朝の eat_flag が 0 になっていない');
        $this->assertSame(0, (int)$morning->i_change_flag, '朝の i_change_flag が 0 になっていない');

        // 昼 → 新規作成
        $noon = $this->fetchReservation(1, '2026-06-03', 2, 1);
        $this->assertNotNull($noon, '昼のレコードが作成されていない');
        $this->assertSame(1, (int)$noon->eat_flag, '昼の eat_flag が 1 でない');
        $this->assertSame(1, (int)$noon->i_change_flag, '昼の i_change_flag が 1 でない');
    }

    /**
     * 非管理者職員が他の職員ユーザー（i_user_level=0）の予約を操作しようとするとエラー
     */
    public function testNonAdminStaffCannotEditOtherStaffReservation(): void
    {
        // user 1 は i_user_level=0（職員）のため、非管理者職員 loginUserId=99 は操作不可
        $result = $this->callBulkAdd(
            ['2026-06-01' => ['1' => ['1' => '1']]],
            1,
            false  // isAdmin = false
        );

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('権限がありません', $result['message']);
    }

    /**
     * 管理者は他の職員ユーザーの予約を操作できる
     */
    public function testAdminCanEditOtherStaffReservation(): void
    {
        $result = $this->callBulkAdd(
            ['2026-06-01' => ['1' => ['1' => '1']]],
            1,
            true  // isAdmin = true
        );

        $this->assertTrue($result['ok'], $result['message'] ?? '');
    }

    /** processBulkChangeEdit を呼び出す共通ヘルパー（ログイン: user 2 = 非管理者職員） */
    private function callBulkChangeEdit(array $dayUsers, bool $isAdmin = false, int $loginUserId = 2): array
    {
        return $this->service->processBulkChangeEdit(
            $dayUsers,
            1,
            'tester',
            $this->reservationTable,
            $this->userTable,
            [],
            $loginUserId,
            $isAdmin,
            0,     // loginUserLevel = 0（職員）
            false  // isBlockLeader
        );
    }

    /**
     * 直前一括編集: 他ユーザーの「値が変わらない行」がペイロードに含まれていても
     * 保存が拒否されない（画面が部屋内全ユーザーの既存予約を自動送信するための回帰テスト）
     */
    public function testBulkChangeEditIgnoresUnchangedRowsOfOtherUsers(): void
    {
        // user 1（別の職員）の既存予約（i_change_flag=1）
        $this->insertReservation([
            'i_id_user'          => 1,
            'd_reservation_date' => '2026-06-10',
            'i_reservation_type' => 3,
            'i_id_room'          => 1,
            'eat_flag'           => 1,
            'i_change_flag'      => 1,
        ]);

        // user 1 の行は値の変わらない no-op、user 2（自分）の行のみ実変更
        $result = $this->callBulkChangeEdit([
            '2026-06-10' => [
                '1' => ['3' => '1'],
                '2' => ['1' => '1'],
            ],
        ]);

        $this->assertTrue($result['ok'], $result['message'] ?? '');
        $this->assertSame(1, $result['created'], '自分の予約が作成されていない');
        $this->assertSame(0, $result['updated']);

        // user 1 の既存予約は更新されていない
        $other = $this->fetchReservation(1, '2026-06-10', 3, 1);
        $this->assertNotNull($other);
        $this->assertSame(1, (int)$other->i_version, '他ユーザーの予約が更新されている');
    }

    /**
     * 直前一括編集: 他の職員ユーザーへの実変更（新規作成）は引き続き拒否される
     */
    public function testBulkChangeEditRejectsActualChangeToOtherStaff(): void
    {
        $result = $this->callBulkChangeEdit([
            '2026-06-10' => ['1' => ['1' => '1']],
        ]);

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('権限がありません', $result['message']);
        $this->assertNull($this->fetchReservation(1, '2026-06-10', 1, 1), '権限のない予約が作成されている');
    }

    /**
     * 直前一括編集: 職員は子供（i_user_level=1）の予約を変更できる
     */
    public function testBulkChangeEditStaffCanEditChildReservation(): void
    {
        $result = $this->callBulkChangeEdit([
            '2026-06-10' => ['3' => ['1' => '1']],
        ]);

        $this->assertTrue($result['ok'], $result['message'] ?? '');
        $this->assertSame(1, $result['created']);
    }

    /**
     * 週次一括登録: 他ユーザーの既存有効予約（＝スキップされる行）がペイロードに
     * 含まれていても保存が拒否されない（回帰テスト）
     */
    public function testBulkAddIgnoresExistingActiveReservationOfOtherUsers(): void
    {
        // user 1（別の職員）の既存有効予約
        $this->insertReservation([
            'i_id_user'          => 1,
            'd_reservation_date' => '2026-06-11',
            'i_reservation_type' => 1,
            'i_id_room'          => 1,
            'eat_flag'           => 1,
            'i_change_flag'      => 1,
        ]);

        // user 1 の行は既存予約ありでスキップ対象、user 3（子供）の行のみ実変更
        $result = $this->callBulkAdd(
            ['2026-06-11' => ['1' => ['1' => '1'], '3' => ['2' => '1']]],
            1,
            false  // isAdmin = false
        );

        $this->assertTrue($result['ok'], $result['message'] ?? '');
        $this->assertStringContainsString('スキップ', $result['message']);
        $this->assertNotNull($this->fetchReservation(3, '2026-06-11', 2, 1), '子供の予約が作成されていない');
    }

    /**
     * 週次一括登録: 他の職員ユーザーの有効予約の取り消し（実変更）は引き続き拒否される
     */
    public function testBulkAddRejectsDeactivatingOtherStaffReservation(): void
    {
        $this->insertReservation([
            'i_id_user'          => 1,
            'd_reservation_date' => '2026-06-12',
            'i_reservation_type' => 1,
            'i_id_room'          => 1,
            'eat_flag'           => 1,
            'i_change_flag'      => 1,
        ]);

        $result = $this->callBulkAdd(
            ['2026-06-12' => ['1' => ['1' => '0']]],
            1,
            false  // isAdmin = false
        );

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('権限がありません', $result['message']);

        $row = $this->fetchReservation(1, '2026-06-12', 1, 1);
        $this->assertNotNull($row);
        $this->assertSame(1, (int)$row->eat_flag, '権限がないのに予約が取り消されている');
    }

    /**
     * 週次一括登録: 後続行が権限拒否された場合、先行行の非活性化も保存されない（原子性）
     */
    public function testBulkAddPermissionRejectionLeavesNoPartialWrites(): void
    {
        // user 3（子供・編集可）と user 1（他の職員・編集不可）の両方に有効予約
        $this->insertReservation([
            'i_id_user'          => 3,
            'd_reservation_date' => '2026-06-13',
            'i_reservation_type' => 1,
            'i_id_room'          => 1,
            'eat_flag'           => 1,
            'i_change_flag'      => 1,
        ]);
        $this->insertReservation([
            'i_id_user'          => 1,
            'd_reservation_date' => '2026-06-13',
            'i_reservation_type' => 2,
            'i_id_room'          => 1,
            'eat_flag'           => 1,
            'i_change_flag'      => 1,
        ]);

        // 子供の取り消し（許可）→ 職員の取り消し（拒否）の順で処理される
        $result = $this->callBulkAdd(
            ['2026-06-13' => ['3' => ['1' => '0'], '1' => ['2' => '0']]],
            1,
            false  // isAdmin = false
        );

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('権限がありません', $result['message']);

        // 拒否時は先行して処理された子供の予約も取り消されていないこと
        $child = $this->fetchReservation(3, '2026-06-13', 1, 1);
        $this->assertNotNull($child);
        $this->assertSame(1, (int)$child->eat_flag, '権限拒否時に先行行だけが非活性化されている');
    }

    /**
     * 週次一括登録: 編集可能な行のみの場合、取り消しは正しく保存される
     */
    public function testBulkAddDeactivationStillWorksWithinTransaction(): void
    {
        $this->insertReservation([
            'i_id_user'          => 3,
            'd_reservation_date' => '2026-06-14',
            'i_reservation_type' => 1,
            'i_id_room'          => 1,
            'eat_flag'           => 1,
            'i_change_flag'      => 1,
        ]);

        $result = $this->callBulkAdd(
            ['2026-06-14' => ['3' => ['1' => '0']]],
            1,
            false  // isAdmin = false（職員→子供は許可）
        );

        $this->assertTrue($result['ok'], $result['message'] ?? '');

        $row = $this->fetchReservation(3, '2026-06-14', 1, 1);
        $this->assertNotNull($row);
        $this->assertSame(0, (int)$row->eat_flag, '取り消しが保存されていない');
        $this->assertSame(0, (int)$row->i_change_flag);
    }
}
