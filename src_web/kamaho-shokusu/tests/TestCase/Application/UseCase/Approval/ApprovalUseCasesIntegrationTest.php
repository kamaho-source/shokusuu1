<?php
declare(strict_types=1);

namespace App\Test\TestCase\Application\UseCase\Approval;

use App\Application\Port\ApprovalNotifierInterface;
use App\Application\Port\AuditActor;
use App\Application\UseCase\Approval\ApprovalWriter;
use App\Application\UseCase\ApproveByAdmin\ApproveByAdminInput;
use App\Application\UseCase\ApproveByAdmin\ApproveByAdminUseCase;
use App\Application\UseCase\ApproveByBlockLeader\ApproveByBlockLeaderInput;
use App\Application\UseCase\ApproveByBlockLeader\ApproveByBlockLeaderUseCase;
use App\Application\UseCase\RejectApproval\RejectApprovalInput;
use App\Application\UseCase\RejectApproval\RejectApprovalUseCase;
use App\Domain\ValueObject\ApprovalStatus;
use App\Infrastructure\Log\AuditLogAdapter;
use App\Infrastructure\Persistence\CakeTransactionManager;
use App\Infrastructure\Query\CakeRoomQuery;
use App\Infrastructure\Repository\CakeApprovalRepository;
use Cake\Chronos\ClockFactory;
use Cake\ORM\TableRegistry;
use Cake\TestSuite\TestCase;

/**
 * 承認・差し戻しの UseCase を本物の Repository・監査ログにつないだテスト（旧 ApprovalServiceTest を引き継ぐ。期待値は同じ）
 *
 * 主な確認項目:
 *   - ブロック長の承認: 未承認(0) のレコードのみ承認可能
 *   - 管理者の承認: 未承認(0)・ブロック長承認済(1) から最終承認できる
 *   - 差し戻し: 未承認(0) または ブロック長承認済(1) から差し戻し可能
 */
class ApprovalUseCasesIntegrationTest extends TestCase
{
    protected array $fixtures = [
        'app.TIndividualReservationInfo',
        'app.TApprovalLog',
        'app.MRoomInfo',
        'app.MUserInfo',
        'app.TAuditLog',
    ];

    private ApprovalNotifierInterface $notifier;

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

        $this->notifier = $this->createMock(ApprovalNotifierInterface::class);
        $this->individualTable = TableRegistry::getTableLocator()->get('TIndividualReservationInfo');

        // key2: 自己承認防止テスト用に、フィクスチャとは別ユーザー・別日付のレコードを追加する。
        // 既存のフィクスチャは他のテストが「1件のみ」であることを前提にしているため、
        // フィクスチャ自体は変更せずこのテストクラス内でのみ挿入する。
        $entity = $this->individualTable->newEntity(array_merge($this->key2, [
            'eat_flag' => 1,
            'i_approval_status' => ApprovalStatus::PENDING,
            'dt_create' => '2024-09-14 16:00:30',
            'c_create_user' => 'tester',
            'dt_update' => '2024-09-14 16:00:30',
            'c_update_user' => 'tester',
        ]));
        $this->individualTable->saveOrFail($entity);
    }

    /** 承認の UseCase を本物の Repository・監査ログ・トランザクションにつなぐ（差し戻しの通知は Spy） */
    private function writer(): ApprovalWriter
    {
        return new ApprovalWriter(new CakeApprovalRepository(), new ClockFactory('Asia/Tokyo'));
    }

    private function transaction(): CakeTransactionManager
    {
        return new CakeTransactionManager($this->getTableLocator()->get('TIndividualReservationInfo')->getConnection());
    }

    /**
     * 旧 ApprovalService::blockLeaderApprove と同じ呼び方。$roomScopeUserId が null なら全部屋（承認者が管理者）、
     * 渡せば承認者の担当部屋だけ（承認者＝$roomScopeUserId として扱う）。
     *
     * @param array<int, array<string, int|string>> $keys
     */
    private function blockLeaderApprove(array $keys, int $approverId, string $actor, ?int $roomScopeUserId): bool
    {
        $useCase = new ApproveByBlockLeaderUseCase($this->writer(), new CakeRoomQuery(), $this->transaction(), new AuditLogAdapter(), new ClockFactory('Asia/Tokyo'));

        return $useCase->execute(new ApproveByBlockLeaderInput($keys, new AuditActor($actor, $approverId, '', ''), $roomScopeUserId === null))->succeeded();
    }

    /**
     * 旧 ApprovalService::adminApprove と同じ呼び方。
     *
     * @param array<int, array<string, int|string>> $keys
     */
    private function adminApprove(array $keys, int $approverId, string $actor): bool
    {
        $useCase = new ApproveByAdminUseCase($this->writer(), $this->transaction(), new AuditLogAdapter(), new ClockFactory('Asia/Tokyo'));

        return $useCase->execute(new ApproveByAdminInput($keys, new AuditActor($actor, $approverId, '', '')))->succeeded();
    }

    /**
     * 旧 ApprovalService::reject と同じ呼び方。$excludeUserId を渡すとブロック長の差し戻し（自分の予約を除く）、
     * 渡さなければ管理者の差し戻し。$roomScopeUserId が null なら全部屋。
     *
     * @param array<int, array<string, int|string>> $keys
     */
    private function reject(array $keys, int $approverId, string $actor, ?string $reason, ?int $roomScopeUserId, string $ipAddress = '', string $actorLoginId = '', ?int $excludeUserId = null): bool
    {
        $useCase = new RejectApprovalUseCase($this->writer(), new CakeRoomQuery(), $this->transaction(), $this->notifier, new AuditLogAdapter(), new ClockFactory('Asia/Tokyo'));
        $actorObj = new AuditActor($actor, $approverId, $actorLoginId, $ipAddress);
        $input = $excludeUserId !== null
            ? RejectApprovalInput::byBlockLeader($keys, $actorObj, $reason, $roomScopeUserId === null)
            : RejectApprovalInput::byAdmin($keys, $actorObj, $reason);

        return $useCase->execute($input)->succeeded();
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
        $this->setStatus(ApprovalStatus::PENDING);

        $result = $this->blockLeaderApprove([$this->key1], 99, 'tester', null);

        $this->assertTrue($result);
        $this->assertSame(ApprovalStatus::BLOCK_LEADER, $this->getStatus());
    }

    public function testBlockLeaderApprove_fails_when_already_admin_approved(): void
    {
        $this->setStatus(ApprovalStatus::ADMIN);

        $result = $this->blockLeaderApprove([$this->key1], 99, 'tester', null);

        $this->assertFalse($result);
        $this->assertSame(ApprovalStatus::ADMIN, $this->getStatus(), 'ステータスが変更されていないこと');
    }

    // ----------------------------------------------------------------
    // adminApprove
    // ----------------------------------------------------------------

    public function testAdminApprove_succeeds_from_block_leader(): void
    {
        $this->setStatus(ApprovalStatus::BLOCK_LEADER);

        $result = $this->adminApprove([$this->key1], 99, 'tester');

        $this->assertTrue($result);
        $this->assertSame(ApprovalStatus::ADMIN, $this->getStatus());
    }

    /**
     * 管理者は緊急時にブロック長承認ステップを経ずに直接最終承認できること。
     */
    public function testAdminApprove_succeeds_from_pending(): void
    {
        $this->setStatus(ApprovalStatus::PENDING);

        $result = $this->adminApprove([$this->key1], 99, 'tester');

        $this->assertTrue($result);
        $this->assertSame(ApprovalStatus::ADMIN, $this->getStatus(), '管理者は未承認レコードを直接最終承認できる');
    }

    // ----------------------------------------------------------------
    // reject
    // ----------------------------------------------------------------

    public function testReject_succeeds_from_pending(): void
    {
        $this->setStatus(ApprovalStatus::PENDING);

        $result = $this->reject([$this->key1], 99, 'tester', '理由', null);

        $this->assertTrue($result);
        $this->assertSame(ApprovalStatus::REJECTED, $this->getStatus());
    }

    public function testReject_succeeds_from_block_leader(): void
    {
        $this->setStatus(ApprovalStatus::BLOCK_LEADER);

        $result = $this->reject([$this->key1], 99, 'tester', '理由', null);

        $this->assertTrue($result);
        $this->assertSame(ApprovalStatus::REJECTED, $this->getStatus());
    }

    public function testReject_fails_when_already_admin_approved(): void
    {
        $this->setStatus(ApprovalStatus::ADMIN);

        $result = $this->reject([$this->key1], 99, 'tester', '理由', null);

        $this->assertFalse($result);
        $this->assertSame(ApprovalStatus::ADMIN, $this->getStatus(), '最終承認済みは差し戻せないこと');
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
        $this->setStatus(ApprovalStatus::PENDING, $this->key1);
        $this->setStatus(ApprovalStatus::PENDING, $this->key2);

        // 承認者(approverId=1) は key1 の予約者本人
        $result = $this->blockLeaderApprove([$this->key1, $this->key2], 1, 'tester', null);

        $this->assertTrue($result, '他ユーザーの予約が承認されるため全体としては成功する');
        $this->assertSame(
            ApprovalStatus::PENDING,
            $this->getStatus($this->key1),
            '承認者自身の予約は自己承認防止のため未承認のまま'
        );
        $this->assertSame(
            ApprovalStatus::BLOCK_LEADER,
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
        $this->setStatus(ApprovalStatus::BLOCK_LEADER, $this->key1);
        $this->setStatus(ApprovalStatus::BLOCK_LEADER, $this->key2);

        // 承認者(approverId=1) は key1 の予約者本人
        $result = $this->adminApprove([$this->key1, $this->key2], 1, 'tester');

        $this->assertTrue($result);
        $this->assertSame(
            ApprovalStatus::ADMIN,
            $this->getStatus($this->key1),
            '管理者は自己承認が許可されているため自身の予約も最終承認される'
        );
        $this->assertSame(ApprovalStatus::ADMIN, $this->getStatus($this->key2));
    }

    /**
     * ブロック長が却下する場合も承認と対称に、自分自身の予約は却下対象から除外されること。
     */
    public function testReject_excludes_own_reservation_when_block_leader_rejects(): void
    {
        $this->setStatus(ApprovalStatus::PENDING, $this->key1);
        $this->setStatus(ApprovalStatus::PENDING, $this->key2);

        // ブロック長(approverId=1, excludeUserId=1) は key1 の予約者本人
        $result = $this->reject([$this->key1, $this->key2], 1, 'tester', '理由', null, '', '', 1);

        $this->assertTrue($result, '他ユーザーの予約が却下されるため全体としては成功する');
        $this->assertSame(
            ApprovalStatus::PENDING,
            $this->getStatus($this->key1),
            'ブロック長自身の予約は自己承認防止のため却下対象外'
        );
        $this->assertSame(
            ApprovalStatus::REJECTED,
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
        $this->setStatus(ApprovalStatus::PENDING, $this->key1);
        $this->setStatus(ApprovalStatus::PENDING, $this->key2);

        // 管理者(approverId=1) は key1 の予約者本人。excludeUserId は渡さない。
        $result = $this->reject([$this->key1, $this->key2], 1, 'tester', '理由', null);

        $this->assertTrue($result);
        $this->assertSame(
            ApprovalStatus::REJECTED,
            $this->getStatus($this->key1),
            '管理者は自身の予約も却下対象に含まれること'
        );
        $this->assertSame(ApprovalStatus::REJECTED, $this->getStatus($this->key2));
    }

}
