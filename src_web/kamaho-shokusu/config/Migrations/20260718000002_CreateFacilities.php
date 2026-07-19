<?php
declare(strict_types=1);

use Migrations\AbstractMigration;

class CreateFacilities extends AbstractMigration
{
    public function change(): void
    {
        $table = $this->table('facilities');

        $table->addColumn('tenant_id', 'integer', [
            'null'    => false,
            'comment' => '所属テナントID',
        ]);
        $table->addColumn('facility_code', 'string', [
            'limit'   => 50,
            'null'    => false,
            'comment' => '施設コード（テナント内一意）',
        ]);
        $table->addColumn('name', 'string', [
            'limit'   => 100,
            'null'    => false,
            'comment' => '施設名',
        ]);
        $table->addColumn('timezone', 'string', [
            'limit'   => 50,
            'null'    => false,
            'default' => 'Asia/Tokyo',
            'comment' => 'タイムゾーン',
        ]);
        $table->addColumn('is_active', 'boolean', [
            'null'    => false,
            'default' => true,
            'comment' => '有効フラグ',
        ]);
        $table->addColumn('created_at', 'datetime', [
            'null'    => false,
            'comment' => '作成日時',
        ]);
        $table->addColumn('updated_at', 'datetime', [
            'null'    => false,
            'comment' => '更新日時',
        ]);

        $table->addIndex(['tenant_id', 'facility_code'], ['name' => 'uq_tenant_facility_code', 'unique' => true]);
        $table->addIndex(['tenant_id'],                  ['name' => 'idx_tenant_id']);

        $table->addForeignKey('tenant_id', 'tenants', 'id', [
            'delete' => 'RESTRICT',
            'update' => 'CASCADE',
        ]);

        $table->create();
    }
}
