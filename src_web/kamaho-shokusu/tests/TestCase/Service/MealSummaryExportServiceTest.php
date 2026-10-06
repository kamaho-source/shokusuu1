<?php
declare(strict_types=1);

namespace App\Test\TestCase\Service;

use App\Service\MealSummaryExportService;
use Cake\Core\Configure;
use Cake\TestSuite\TestCase;

/**
 * MealSummaryExportService テスト
 *
 * 承認機能フラグ(Features.approval)によって、食事控除表(集計)の
 * 承認ステータス絞り込みが切り替わることを検証する。
 */
class MealSummaryExportServiceTest extends TestCase
{
    private mixed $originalApproval = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->originalApproval = Configure::read('Features.approval');
    }

    protected function tearDown(): void
    {
        Configure::write('Features.approval', $this->originalApproval);
        parent::tearDown();
    }

    public function testApprovalFilter_whenApprovalEnabled_limitsToApproved(): void
    {
        Configure::write('Features.approval', true);

        // 承認ON: 承認済み(2)のみを集計対象にする
        $this->assertSame(
            ['i_approval_status' => 2],
            MealSummaryExportService::approvalFilterConditions()
        );
    }

    public function testApprovalFilter_whenApprovalDisabled_noStatusFilter(): void
    {
        Configure::write('Features.approval', false);

        // 承認OFF: 承認ステータスで絞らない（有効予約をそのまま集計）
        $this->assertSame([], MealSummaryExportService::approvalFilterConditions());
    }
}
