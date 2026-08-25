<?php
declare(strict_types=1);

namespace App\Test\TestCase\Service;

use App\Service\ApprovalService;
use App\Service\NotificationService;
use Cake\ORM\TableRegistry;
use Cake\TestSuite\TestCase;

/**
 * ApprovalService のテスト
 *
 * 主な確認項目:
 *   - blockLeaderApprove: STATUS_PENDING(0) のレコードのみ承認可能
 *   - adminApprove: STATUS_BLOCK_LEADER(1) のレコードのみ承認可能
 *   - reject: STATUS_PENDING(0) または STATUS_BLOCK_LEADER(1) から差し戻し可能
 *   - バグ再現: STATUS_PENDING(0) のレコードを adminApprove しても更新されないこと
 */
class ApprovalServiceTest extends TestCase
{
    protected array $fixtures = [
        'app.TIndividualReservationInfo',
        'app.TApprovalLog',
        'app.MRoomInfo',
        'app.MUserInfo',
        'app.TAuditLog',
    ];

    private ApprovalService $service;

    /** @var \Cake\ORM\Table */
    private $individualTable;

    private array $key1 = [
        'i_id_user'          => 1,
        'd_reservation_date' => '2024-09-07',
        'i_id_room'          => 1,
        'i_reservation_type' => 1,
    ];

    /** ブロック長・管理者(自己承認テスト用) とは別ユーザーの予約 */
    private array $key2 = [
        'i_id_user'          => 2,
        'd_reservation_date' => '2024-09-14',
        'i_id_room'          => 1,
        'i_reservation_type' => 1,
    ];

    public function setUp(): void
    {
        parent::setUp();

        $notificationMock = $this->getMockBuilder(NotificationService::class)
            ->onlyMethods(['createRejectionNotifications'])
            ->getMock();

        $this->service = new ApprovalService(null, $notificationMock);
        $this->individualTable = TableRegistry::getTableLocator()->get('TIndividualReservationInfo');

        // key2: 自己承認防止テスト用に、フィクスチャとは別ユーザー・別日付のレコードを追加する。
        // 既存のフィクスチャは他のテストが「1件のみ」であることを前提にしているため、
        // フィクスチャ自体は変更せずこのテストクラス内でのみ挿入する。
        $entity = $this->individualTable->newEntity(array_merge($this->key2, [
            'eat_flag' => 1,
            'i_approval_status' => ApprovalService::STATUS_PENDING,
            'dt_create' => '2024-09-14 16:00:30',
            'c_create_user' => 'tester',
            'dt_update' => '2024-09-14 16:00:30',
            'c_update_user' => 'tester',
        ]));
        $this->individualTable->saveOrFail($entity);
    }

    private function setStatus(int $status, ?array $key = null): void
    {
        $this->individualTable->updateAll(
            ['i_approval_status' => $status],
            $key ?? $this->key1
        );
    }

    private function getStatus(?array $key = null): int
    {
        $row = $this->individualTable->find()
            ->where($key ?? $this->key1)
            ->first();
        return (int)$row->i_approval_status;
    }

    /**
     * 直近の t_audit_log レコード（指定アクション）を target_id/count に整形して返す。
     *
     * @return array{target_id: string, count: int}
     */
    private function auditLog(string $action): array
    {
        $row = TableRegistry::getTableLocator()->get('TAuditLog')->find()
            ->where(['c_action' => $action])
            ->orderBy(['i_id_audit' => 'DESC'])
            ->firstOrFail();

        $detail = json_decode((string)$row->c_detail, true) ?? [];

        return [
            'target_id' => (string)$row->c_target_id,
            'count'     => (int)($detail['count'] ?? 0),
        ];
    }

    // ----------------------------------------------------------------
    // blockLeaderApprove
    // ----------------------------------------------------------------

    public function testBlockLeaderApprove_succeeds_from_pending(): void
    {
        $this->setStatus(ApprovalService::STATUS_PENDING);

        $result = $this->service->blockLeaderApprove([$this->key1], 99, 'tester');

        $this->assertTrue($result);
        $this->assertSame(ApprovalService::STATUS_BLOCK_LEADER, $this->getStatus());
    }

    public function testBlockLeaderApprove_fails_when_already_admin_approved(): void
    {
        $this->setStatus(ApprovalService::STATUS_ADMIN);

        $result = $this->service->blockLeaderApprove([$this->key1], 99, 'tester');

        $this->assertFalse($result);
        $this->assertSame(ApprovalService::STATUS_ADMIN, $this->getStatus(), 'ステータスが変更されていないこと');
    }

    // ----------------------------------------------------------------
    // adminApprove
    // ----------------------------------------------------------------

    public function testAdminApprove_succeeds_from_block_leader(): void
    {
        $this->setStatus(ApprovalService::STATUS_BLOCK_LEADER);

        $result = $this->service->adminApprove([$this->key1], 99, 'tester');

        $this->assertTrue($result);
        $this->assertSame(ApprovalService::STATUS_ADMIN, $this->getStatus());
    }

    /**
     * 管理者は緊急時にブロック長承認ステップを経ずに直接最終承認できること。
     */
    public function testAdminApprove_succeeds_from_pending(): void
    {
        $this->setStatus(ApprovalService::STATUS_PENDING);

        $result = $this->service->adminApprove([$this->key1], 99, 'tester');

        $this->assertTrue($result);
        $this->assertSame(ApprovalService::STATUS_ADMIN, $this->getStatus(), '管理者は未承認レコードを直接最終承認できる');
    }

    // ----------------------------------------------------------------
    // reject
    // ----------------------------------------------------------------

    public function testReject_succeeds_from_pending(): void
    {
        $this->setStatus(ApprovalService::STATUS_PENDING);

        $result = $this->service->reject([$this->key1], 99, 'tester', '理由');

        $this->assertTrue($result);
        $this->assertSame(ApprovalService::STATUS_REJECTED, $this->getStatus());
    }

    public function testReject_succeeds_from_block_leader(): void
    {
        $this->setStatus(ApprovalService::STATUS_BLOCK_LEADER);

        $result = $this->service->reject([$this->key1], 99, 'tester', '理由');

        $this->assertTrue($result);
        $this->assertSame(ApprovalService::STATUS_REJECTED, $this->getStatus());
    }

    public function testReject_fails_when_already_admin_approved(): void
    {
        $this->setStatus(ApprovalService::STATUS_ADMIN);

        $result = $this->service->reject([$this->key1], 99, 'tester', '理由');

        $this->assertFalse($result);
        $this->assertSame(ApprovalService::STATUS_ADMIN, $this->getStatus(), '最終承認済みは差し戻せないこと');
    }

    // ----------------------------------------------------------------
    // 自己承認防止（#652 監査バグ修正）
    // ----------------------------------------------------------------

    /**
     * ブロック長が自分自身の予約を含む一覧を承認しようとした場合、
     * 自分自身の予約は除外され、他ユーザーの予約のみ承認されること。
     */
    public function testBlockLeaderApprove_excludes_own_reservation_but_approves_others(): void
    {
        $this->setStatus(ApprovalService::STATUS_PENDING, $this->key1);
        $this->setStatus(ApprovalService::STATUS_PENDING, $this->key2);

        // 承認者(approverId=1) は key1 の予約者本人
        $result = $this->service->blockLeaderApprove([$this->key1, $this->key2], 1, 'tester');

        $this->assertTrue($result, '他ユーザーの予約が承認されるため全体としては成功する');
        $this->assertSame(
            ApprovalService::STATUS_PENDING,
            $this->getStatus($this->key1),
            '承認者自身の予約は自己承認防止のため未承認のまま'
        );
        $this->assertSame(
            ApprovalService::STATUS_BLOCK_LEADER,
            $this->getStatus($this->key2),
            '他ユーザーの予約は承認されること'
        );

        // CodeRabbit指摘：監査ログには実際に更新された予約（key2）のみが記録され、
        // 自己承認防止でスキップされた予約（key1）は対象件数・対象IDに含まれないこと。
        $log = $this->auditLog('approval_block_leader');
        $this->assertSame(1, $log['count'], '監査ログの件数は実際に更新した1件のみ');
        $this->assertStringNotContainsString('1:2024-09-07', $log['target_id'], 'スキップした自己予約(key1)が対象IDに含まれてはいけない');
        $this->assertStringContainsString('2:2024-09-14', $log['target_id'], '実際に更新したkey2は対象IDに含まれること');
    }

    /**
     * 管理者が自分自身の予約を含む一覧を承認しようとした場合、
     * 自己承認が意図的に許可されているため、自分自身の予約も承認されること（#144）。
     */
    public function testAdminApprove_allows_own_reservation(): void
    {
        $this->setStatus(ApprovalService::STATUS_BLOCK_LEADER, $this->key1);
        $this->setStatus(ApprovalService::STATUS_BLOCK_LEADER, $this->key2);

        // 承認者(approverId=1) は key1 の予約者本人
        $result = $this->service->adminApprove([$this->key1, $this->key2], 1, 'tester');

        $this->assertTrue($result);
        $this->assertSame(
            ApprovalService::STATUS_ADMIN,
            $this->getStatus($this->key1),
            '管理者は自己承認が許可されているため自身の予約も最終承認される'
        );
        $this->assertSame(ApprovalService::STATUS_ADMIN, $this->getStatus($this->key2));
    }

    /**
     * ブロック長が却下する場合も承認と対称に、自分自身の予約は却下対象から除外されること。
     */
    public function testReject_excludes_own_reservation_when_block_leader_rejects(): void
    {
        $this->setStatus(ApprovalService::STATUS_PENDING, $this->key1);
        $this->setStatus(ApprovalService::STATUS_PENDING, $this->key2);

        // ブロック長(approverId=1, excludeUserId=1) は key1 の予約者本人
        $result = $this->service->reject([$this->key1, $this->key2], 1, 'tester', '理由', '', '', 1);

        $this->assertTrue($result, '他ユーザーの予約が却下されるため全体としては成功する');
        $this->assertSame(
            ApprovalService::STATUS_PENDING,
            $this->getStatus($this->key1),
            'ブロック長自身の予約は自己承認防止のため却下対象外'
        );
        $this->assertSame(
            ApprovalService::STATUS_REJECTED,
            $this->getStatus($this->key2),
            '他ユーザーの予約は却下されること'
        );

        // CodeRabbit指摘：監査ログには実際に却下された予約（key2）のみが記録されること。
        $log = $this->auditLog('approval_rejected');
        $this->assertSame(1, $log['count']);
        $this->assertStringNotContainsString('1:2024-09-07', $log['target_id']);
        $this->assertStringContainsString('2:2024-09-14', $log['target_id']);
    }

    /**
     * 管理者が却下する場合は自己承認と対称に、自分自身の予約も却下対象に含まれること。
     */
    public function testReject_includes_own_reservation_when_admin_rejects(): void
    {
        $this->setStatus(ApprovalService::STATUS_PENDING, $this->key1);
        $this->setStatus(ApprovalService::STATUS_PENDING, $this->key2);

        // 管理者(approverId=1) は key1 の予約者本人。excludeUserId は渡さない。
        $result = $this->service->reject([$this->key1, $this->key2], 1, 'tester', '理由');

        $this->assertTrue($result);
        $this->assertSame(
            ApprovalService::STATUS_REJECTED,
            $this->getStatus($this->key1),
            '管理者は自身の予約も却下対象に含まれること'
        );
        $this->assertSame(ApprovalService::STATUS_REJECTED, $this->getStatus($this->key2));
    }

}
