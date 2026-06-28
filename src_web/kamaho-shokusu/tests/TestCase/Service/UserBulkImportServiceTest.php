<?php
declare(strict_types=1);

namespace App\Test\TestCase\Service;

use App\Service\UserBulkImportService;
use Cake\ORM\TableRegistry;
use Cake\TestSuite\TestCase;

class UserBulkImportServiceTest extends TestCase
{
    protected array $fixtures = [
        'app.MUserInfo',
        'app.MUserGroup',
        'app.MRoomInfo',
        'app.TAuditLog',
    ];

    private UserBulkImportService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new UserBulkImportService();
    }

    // ----------------------------------------------------------------
    // import — 空レコードのガード条件
    // ----------------------------------------------------------------

    public function testImport_emptyRecords_returnsZeroCounts(): void
    {
        $result = $this->service->import([], 'admin', 1, '127.0.0.1');

        $this->assertSame(0, $result['processed']);
        $this->assertSame(0, $result['created']);
        $this->assertSame(0, $result['skipped']);
        $this->assertSame(0, $result['failed']);
        $this->assertSame([], $result['errors']);
    }

    // ----------------------------------------------------------------
    // import — 必須項目が欠けているレコード
    // ----------------------------------------------------------------

    public function testImport_missingLoginId_incrementsFailedCount(): void
    {
        $records = [
            ['_row' => 1, 'name' => 'テスト太郎', 'role' => '職員', 'staff_id' => 'S001'],
        ];

        $result = $this->service->import($records, 'admin', 1, '127.0.0.1');

        $this->assertSame(1, $result['processed']);
        $this->assertSame(1, $result['failed']);
        $this->assertSame(0, $result['created']);
    }

    public function testImport_missingName_incrementsFailedCount(): void
    {
        $records = [
            ['_row' => 1, 'login_id' => 'test_user_xyz', 'role' => '職員', 'staff_id' => 'S001'],
        ];

        $result = $this->service->import($records, 'admin', 1, '127.0.0.1');

        $this->assertSame(1, $result['processed']);
        $this->assertSame(1, $result['failed']);
    }

    // ----------------------------------------------------------------
    // import — 不正なrole値
    // ----------------------------------------------------------------

    public function testImport_invalidRole_incrementsFailedCount(): void
    {
        $records = [
            ['_row' => 1, 'login_id' => 'new_test_user_abc', 'name' => 'テスト', 'role' => 'invalid_role'],
        ];

        $result = $this->service->import($records, 'admin', 1, '127.0.0.1');

        $this->assertSame(1, $result['failed']);
    }

    // ----------------------------------------------------------------
    // import — 職員にstaff_idが必須
    // ----------------------------------------------------------------

    public function testImport_staffWithoutStaffId_incrementsFailedCount(): void
    {
        $records = [
            ['_row' => 1, 'login_id' => 'staff_no_id_xyz', 'name' => '職員', 'role' => '職員'],
        ];

        $result = $this->service->import($records, 'admin', 1, '127.0.0.1');

        $this->assertSame(1, $result['failed']);
        $this->assertArrayHasKey(1, $result['errors']);
    }

    // ----------------------------------------------------------------
    // import — 既存login_idはスキップ
    // ----------------------------------------------------------------

    public function testImport_duplicateLoginId_incrementsSkippedCount(): void
    {
        // フィクスチャにある既存アカウントを使用
        $records = [
            [
                '_row' => 1,
                'login_id' => 'admin_user', // fixtures に存在するアカウント
                'name'     => 'テスト',
                'role'     => '職員',
                'staff_id' => 'S999',
            ],
        ];

        $result = $this->service->import($records, 'admin', 1, '127.0.0.1');

        $this->assertSame(1, $result['skipped']);
        $this->assertSame(0, $result['created']);
    }

    // ----------------------------------------------------------------
    // import — 正常なレコード作成
    // ----------------------------------------------------------------

    public function testImport_validRecord_createsUser(): void
    {
        $records = [
            [
                '_row'     => 1,
                'login_id' => 'bulk_new_user_' . time(),
                'name'     => '一括テスト太郎',
                'role'     => '児童',
                'password' => 'testPass123',
            ],
        ];

        $result = $this->service->import($records, 'admin', 1, '127.0.0.1');

        $this->assertSame(1, $result['created']);
        $this->assertSame(1, $result['processed']);
        $this->assertSame(0, $result['failed']);
    }

    // ----------------------------------------------------------------
    // import — 返却値の構造
    // ----------------------------------------------------------------

    public function testImport_returnsRequiredResultKeys(): void
    {
        $result = $this->service->import([], 'admin');

        $this->assertArrayHasKey('processed', $result);
        $this->assertArrayHasKey('created', $result);
        $this->assertArrayHasKey('skipped', $result);
        $this->assertArrayHasKey('failed', $result);
        $this->assertArrayHasKey('errors', $result);
    }
}
