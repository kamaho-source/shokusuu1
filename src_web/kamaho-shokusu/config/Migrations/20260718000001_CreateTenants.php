<?php
declare(strict_types=1);

use Migrations\AbstractMigration;

class CreateTenants extends AbstractMigration
{
    public function change(): void
    {
        $table = $this->table('tenants');

        $table->addColumn('tenant_code', 'string', [
            'limit'   => 50,
            'null'    => false,
            'comment' => 'テナント識別コード（サブドメイン用）',
        ]);
        $table->addColumn('name', 'string', [
            'limit'   => 100,
            'null'    => false,
            'comment' => '法人名称',
        ]);
        $table->addColumn('status', 'string', [
            'limit'   => 20,
            'null'    => false,
            'default' => 'trial',
            'comment' => 'trial|active|suspended|terminated',
        ]);
        $table->addColumn('plan_code', 'string', [
            'limit'   => 50,
            'null'    => true,
            'default' => null,
            'comment' => '契約プラン',
        ]);
        $table->addColumn('contract_started_at', 'datetime', [
            'null'    => true,
            'default' => null,
            'comment' => '契約開始日時',
        ]);
        $table->addColumn('contract_ended_at', 'datetime', [
            'null'    => true,
            'default' => null,
            'comment' => '契約終了日時',
        ]);
        $table->addColumn('created_at', 'datetime', [
            'null'    => false,
            'comment' => '作成日時',
        ]);
        $table->addColumn('updated_at', 'datetime', [
            'null'    => false,
            'comment' => '更新日時',
        ]);

        $table->addIndex(['tenant_code'], ['name' => 'uq_tenant_code', 'unique' => true]);
        $table->addIndex(['status'],      ['name' => 'idx_status']);

        $table->create();
    }
}
