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
            ->onlyMethods(['validateReservationDate'])
            ->getMock();
        $mock->method('validateReservationDate')->willReturn(true);
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
    private function callBulkAdd(array $dayUsers, int $roomId = 1): array
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
            $this->roomTable
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
}
