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

    public function setUp(): void
    {
        parent::setUp();

        $notificationMock = $this->getMockBuilder(NotificationService::class)
            ->onlyMethods(['createRejectionNotifications'])
            ->getMock();

        $this->service = new ApprovalService(null, $notificationMock);
        $this->individualTable = TableRegistry::getTableLocator()->get('TIndividualReservationInfo');
    }

    private function setStatus(int $status): void
    {
        $this->individualTable->updateAll(
            ['i_approval_status' => $status],
            $this->key1
        );
    }

    private function getStatus(): int
    {
        $row = $this->individualTable->find()
            ->where($this->key1)
            ->first();
        return (int)$row->i_approval_status;
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
     * バグ再現テスト:
     * STATUS_PENDING(0) のレコードを adminApprove しても STATUS_ADMIN(2) にならないこと。
     */
    public function testAdminApprove_fails_when_status_is_pending(): void
    {
        $this->setStatus(ApprovalService::STATUS_PENDING);

        $result = $this->service->adminApprove([$this->key1], 99, 'tester');

        $this->assertFalse($result);
        $this->assertSame(ApprovalService::STATUS_PENDING, $this->getStatus(), '承認ルートを経由せず承認済みになってはならない');
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

}
