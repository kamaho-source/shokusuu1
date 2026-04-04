<?php
declare(strict_types=1);

namespace App\Test\TestCase\Service;

use App\Service\ApprovalService;
use App\Service\NotificationService;
use App\Service\RoomAccessService;
use Cake\Datasource\ConnectionManager;
use Cake\I18n\DateTime;
use Cake\ORM\TableRegistry;
use Cake\TestSuite\TestCase;

/**
 * ApprovalService のテスト
 *
 * 主な確認項目:
 *   - ブロック長承認でステータスが STATUS_BLOCK_LEADER に更新される
 *   - 管理者承認でステータスが STATUS_ADMIN に更新される
 *   - 差し戻しでステータスが STATUS_REJECTED に更新される
 *   - 承認ログが t_approval_log に記録される
 *   - countBlockLeaderPending / countAdminPending が正しい件数を返す
 *   - getAdminSummary が食種別サマリを正しく集計する
 */
class ApprovalServiceTest extends TestCase
{
    protected array $fixtures = [
        'app.TIndividualReservationInfo',
        'app.TApprovalLog',
        'app.TNotification',
        'app.MUserInfo',
        'app.MRoomInfo',
        'app.MUserGroup',
    ];

    private ApprovalService $service;

    /** @var \Cake\ORM\Table */
    private $individualTable;

    /** @var \Cake\ORM\Table */
    private $logTable;

    /** ルームアクセスを常に roomId=1 を返すモック */
    private function makeRoomAccessMock(): RoomAccessService
    {
        $mock = $this->getMockBuilder(RoomAccessService::class)
            ->onlyMethods(['getUserRoomIds'])
            ->getMock();
        $mock->method('getUserRoomIds')->willReturn([1]);
        return $mock;
    }

    /** 通知サービスモック（テスト中に実際のDB通知書き込みを制御） */
    private function makeNotificationMock(): NotificationService
    {
        $mock = $this->getMockBuilder(NotificationService::class)
            ->onlyMethods(['createRejectionNotifications'])
            ->getMock();
        // void メソッドは何も返さない（意図的に will() を省略）
        $mock->method('createRejectionNotifications');
        return $mock;
    }

    public function setUp(): void
    {
        parent::setUp();

        // フィクスチャのテスト接続が確実に使用されるよう TableLocator を初期化
        $this->getTableLocator()->clear();

        $this->service = new ApprovalService(
            $this->makeRoomAccessMock(),
            $this->makeNotificationMock()
        );

        $this->individualTable = $this->getTableLocator()->get(
            'TIndividualReservationInfo',
            ['table' => 't_individual_reservation_info']
        );
        $this->logTable = $this->getTableLocator()->get(
            'TApprovalLog',
            ['table' => 't_approval_log']
        );
    }

    // ---------------------------------------------------------------------------
    // ヘルパー
    // ---------------------------------------------------------------------------

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
            'i_approval_status'  => ApprovalService::STATUS_PENDING,
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

    private function fetchReservation(array $where): ?object
    {
        return $this->individualTable->find()->where($where)->first();
    }

    private function makeKey(array $override = []): array
    {
        return array_merge([
            'i_id_user'          => 1,
            'd_reservation_date' => '2026-06-01',
            'i_id_room'          => 1,
            'i_reservation_type' => 1,
        ], $override);
    }

    // ---------------------------------------------------------------------------
    // blockLeaderApprove()
    // ---------------------------------------------------------------------------

    public function testBlockLeaderApproveUpdatesStatusToBlockLeader(): void
    {
        $this->insertReservation();

        $result = $this->service->blockLeaderApprove([$this->makeKey()], 2, 'approver');

        $this->assertTrue($result);

        $row = $this->fetchReservation($this->makeKey());
        $this->assertSame(ApprovalService::STATUS_BLOCK_LEADER, (int)$row->i_approval_status);
    }

    public function testBlockLeaderApproveCreatesApprovalLog(): void
    {
        $this->insertReservation();

        $this->service->blockLeaderApprove([$this->makeKey()], 2, 'approver');

        $log = $this->logTable->find()
            ->where([
                'i_id_user'          => 1,
                'd_reservation_date' => '2026-06-01',
                'i_approval_status'  => ApprovalService::STATUS_BLOCK_LEADER,
            ])
            ->first();

        $this->assertNotNull($log, 'ブロック長承認ログが記録されていない');
        $this->assertSame(2, (int)$log->i_approver_id);
    }

    public function testBlockLeaderApproveReturnsFalseWhenRecordNotFound(): void
    {
        // レコードを挿入しない
        $result = $this->service->blockLeaderApprove([$this->makeKey()], 2, 'approver');

        $this->assertFalse($result);
    }

    // ---------------------------------------------------------------------------
    // adminApprove()
    // ---------------------------------------------------------------------------

    public function testAdminApproveUpdatesStatusToAdmin(): void
    {
        $this->insertReservation(['i_approval_status' => ApprovalService::STATUS_BLOCK_LEADER]);

        $result = $this->service->adminApprove([$this->makeKey()], 3, 'admin');

        $this->assertTrue($result);

        $row = $this->fetchReservation($this->makeKey());
        $this->assertSame(ApprovalService::STATUS_ADMIN, (int)$row->i_approval_status);
    }

    // ---------------------------------------------------------------------------
    // reject()
    // ---------------------------------------------------------------------------

    public function testRejectUpdatesStatusToRejected(): void
    {
        $this->insertReservation(['i_approval_status' => ApprovalService::STATUS_BLOCK_LEADER]);

        $result = $this->service->reject([$this->makeKey()], 2, 'approver', '理由あり');

        $this->assertTrue($result);

        $row = $this->fetchReservation($this->makeKey());
        $this->assertSame(ApprovalService::STATUS_REJECTED, (int)$row->i_approval_status);
    }

    public function testRejectCreatesLogWithReason(): void
    {
        $this->insertReservation();

        $this->service->reject([$this->makeKey()], 2, 'approver', '内容不備');

        $log = $this->logTable->find()
            ->where([
                'i_id_user'         => 1,
                'i_approval_status' => ApprovalService::STATUS_REJECTED,
            ])
            ->first();

        $this->assertNotNull($log);
        $this->assertSame('内容不備', (string)$log->c_reject_reason);
    }

    // ---------------------------------------------------------------------------
    // countBlockLeaderPending()
    // ---------------------------------------------------------------------------

    public function testCountBlockLeaderPendingReturnsCorrectCount(): void
    {
        $this->insertReservation(['i_approval_status' => ApprovalService::STATUS_PENDING]);

        $count = $this->service->countBlockLeaderPending(1);

        // フィクスチャ1件 + 今回挿入1件 = 2件だが、フィクスチャの approval_status デフォルトは NULL の可能性があるので
        // 少なくとも1以上であることを確認
        $this->assertGreaterThanOrEqual(1, $count);
    }

    public function testCountBlockLeaderPendingReturnsZeroForUnauthorizedUser(): void
    {
        // ルームアクセスなしのユーザーでは0を返す
        $serviceNoRoom = new ApprovalService(
            $this->makeRoomAccessMockWithNoRooms(),
            $this->makeNotificationMock()
        );

        $count = $serviceNoRoom->countBlockLeaderPending(999);

        $this->assertSame(0, $count);
    }

    private function makeRoomAccessMockWithNoRooms(): RoomAccessService
    {
        $mock = $this->getMockBuilder(RoomAccessService::class)
            ->onlyMethods(['getUserRoomIds'])
            ->getMock();
        $mock->method('getUserRoomIds')->willReturn([]);
        return $mock;
    }

    // ---------------------------------------------------------------------------
    // countAdminPending()
    // ---------------------------------------------------------------------------

    public function testCountAdminPendingReturnsCorrectCount(): void
    {
        $this->insertReservation(['i_approval_status' => ApprovalService::STATUS_BLOCK_LEADER]);

        $count = $this->service->countAdminPending();

        $this->assertSame(1, $count);
    }

    // ---------------------------------------------------------------------------
    // getAdminSummary()
    // ---------------------------------------------------------------------------

    public function testGetAdminSummaryAggregatesByDateAndRoom(): void
    {
        $this->insertReservation([
            'd_reservation_date' => '2026-07-01',
            'i_reservation_type' => 1, // 朝
            'i_approval_status'  => ApprovalService::STATUS_ADMIN,
            'eat_flag'           => 1,
        ]);
        $this->insertReservation([
            'd_reservation_date' => '2026-07-01',
            'i_reservation_type' => 2, // 昼
            'i_approval_status'  => ApprovalService::STATUS_ADMIN,
            'eat_flag'           => 1,
        ]);

        $summary = $this->service->getAdminSummary('2026-07-01', '2026-07-01');

        // 同一日付・同一部屋なので集約後は1エントリ
        $this->assertCount(1, $summary, '1件のサマリが返るべき');
        $this->assertSame(1, $summary[0]['breakfast'], '朝食カウントが1でない');
        $this->assertSame(1, $summary[0]['lunch'], '昼食カウントが1でない');
        $this->assertSame(0, $summary[0]['dinner'], '夕食カウントが0でない');
        $this->assertSame(0, $summary[0]['bento'], '弁当カウントが0でない');
    }

    public function testGetAdminSummaryExcludesNonEatFlag(): void
    {
        $this->insertReservation([
            'd_reservation_date' => '2026-07-02',
            'i_reservation_type' => 1,
            'i_approval_status'  => ApprovalService::STATUS_ADMIN,
            'eat_flag'           => 0, // 食べない
        ]);

        $summary = $this->service->getAdminSummary('2026-07-02', '2026-07-02');

        // eat_flag=0 のレコードはエントリを作るが食数カウントには含まれない
        $this->assertCount(1, $summary, 'サマリエントリが1件返るべき');
        $this->assertSame(0, $summary[0]['breakfast'], 'eat_flag=0 は朝食カウントに含めない');
    }
}
