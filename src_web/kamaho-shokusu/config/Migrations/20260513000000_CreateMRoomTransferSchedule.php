<?php
declare(strict_types=1);

use Migrations\AbstractMigration;

class CreateMRoomTransferSchedule extends AbstractMigration
{
    public function change(): void
    {
        $table = $this->table('m_room_transfer_schedule', ['id' => false, 'primary_key' => ['i_id']]);
        $table
            ->addColumn('i_id', 'integer', ['identity' => true, 'null' => false])
            ->addColumn('i_id_user', 'integer', ['null' => false])
            ->addColumn('i_id_room_from', 'integer', ['null' => true, 'default' => null])
            ->addColumn('i_id_room_to', 'integer', ['null' => false])
            ->addColumn('d_effective_date', 'date', ['null' => false])
            ->addColumn('i_status', 'integer', ['null' => false, 'default' => 0, 'limit' => 1, 'comment' => '0=予約中, 1=適用済み, 2=キャンセル'])
            ->addColumn('c_create_user', 'string', ['null' => true, 'limit' => 50])
            ->addColumn('dt_create', 'datetime', ['null' => true])
            ->addColumn('c_update_user', 'string', ['null' => true, 'limit' => 50])
            ->addColumn('dt_update', 'datetime', ['null' => true])
            ->addIndex(['i_id_user'], ['name' => 'idx_rts_user_id'])
            ->addIndex(['d_effective_date', 'i_status'], ['name' => 'idx_rts_effective_date'])
            ->create();
    }
}
