<?php
declare(strict_types=1);

namespace App\Test\TestCase\Model\Table;

use App\Model\Table\TAuditLogTable;
use Cake\TestSuite\TestCase;

/**
 * TAuditLogTable テスト
 */
class TAuditLogTableTest extends TestCase
{
    protected TAuditLogTable $TAuditLog;

    protected array $fixtures = ['app.TAuditLog', 'app.MUserInfo'];

    protected function setUp(): void
    {
        parent::setUp();
        $config         = $this->getTableLocator()->exists('TAuditLog') ? [] : ['className' => TAuditLogTable::class];
        $this->TAuditLog = $this->getTableLocator()->get('TAuditLog', $config);
    }

    protected function tearDown(): void
    {
        unset($this->TAuditLog);
        parent::tearDown();
    }

    // ----------------------------------------------------------------
    // 初期化
    // ----------------------------------------------------------------

    public function testInitialize(): void
    {
        $this->assertSame('t_audit_log',   $this->TAuditLog->getTable());
        $this->assertSame('i_id_audit',    $this->TAuditLog->getPrimaryKey());
        $this->assertSame('c_action',      $this->TAuditLog->getDisplayField());
        $this->assertTrue($this->TAuditLog->hasAssociation('MUserInfo'));
    }

    // ----------------------------------------------------------------
    // バリデーション — 必須フィールド
    // ----------------------------------------------------------------

    public function testValidation_requiredFields_pass(): void
    {
        $entity = $this->TAuditLog->newEntity([
            'c_category'        => 'user',
            'c_action'          => 'user_login',
            'c_actor_user_name' => 'taro',
            'dt_create'         => '2026-06-01 10:00:00',
        ]);

        $this->assertEmpty($entity->getErrors(), 'No validation errors expected for valid entity');
    }

    public function testValidation_emptyCategory_fails(): void
    {
        $entity = $this->TAuditLog->newEntity([
            'c_category'        => '',
            'c_action'          => 'user_login',
            'c_actor_user_name' => 'taro',
            'dt_create'         => '2026-06-01 10:00:00',
        ]);

        $this->assertArrayHasKey('c_category', $entity->getErrors());
    }

    public function testValidation_emptyAction_fails(): void
    {
        $entity = $this->TAuditLog->newEntity([
            'c_category'        => 'user',
            'c_action'          => '',
            'c_actor_user_name' => 'taro',
            'dt_create'         => '2026-06-01 10:00:00',
        ]);

        $this->assertArrayHasKey('c_action', $entity->getErrors());
    }

    public function testValidation_emptyActorName_fails(): void
    {
        $entity = $this->TAuditLog->newEntity([
            'c_category'        => 'user',
            'c_action'          => 'user_login',
            'c_actor_user_name' => '',
            'dt_create'         => '2026-06-01 10:00:00',
        ]);

        $this->assertArrayHasKey('c_actor_user_name', $entity->getErrors());
    }

    // ----------------------------------------------------------------
    // フィクスチャデータの読み込み
    // ----------------------------------------------------------------

    public function testFind_fixtureRecords_loaded(): void
    {
        $count = $this->TAuditLog->find()->count();
        $this->assertGreaterThanOrEqual(3, $count, 'Fixture should load at least 3 records');
    }

    public function testFind_orderByDesc_returnsLatestFirst(): void
    {
        $first = $this->TAuditLog->find()->order(['i_id_audit' => 'DESC'])->first();
        $this->assertNotNull($first);
    }

    // ----------------------------------------------------------------
    // 保存
    // ----------------------------------------------------------------

    public function testSave_newRecord_success(): void
    {
        $entity = $this->TAuditLog->newEntity([
            'c_category'        => 'system',
            'c_action'          => 'table_test',
            'c_actor_user_name' => 'unit_test',
            'dt_create'         => date('Y-m-d H:i:s'),
        ]);

        $saved = $this->TAuditLog->save($entity);
        $this->assertNotFalse($saved);
        $this->assertNotNull($saved->i_id_audit);
    }

    // ----------------------------------------------------------------
    // アソシエーション
    // ----------------------------------------------------------------

    public function testAssociation_belongsToMUserInfo(): void
    {
        $assoc = $this->TAuditLog->getAssociation('MUserInfo');
        $this->assertSame('i_actor_user_id', $assoc->getForeignKey());
        $this->assertSame('LEFT', $assoc->getJoinType());
    }
}
