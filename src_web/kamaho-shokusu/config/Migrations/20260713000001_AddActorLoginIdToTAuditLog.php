<?php
declare(strict_types=1);

use Migrations\AbstractMigration;

class AddActorLoginIdToTAuditLog extends AbstractMigration
{
    public function change(): void
    {
        $this->table('t_audit_log')
            ->addColumn('c_actor_login_id', 'string', [
                'limit'   => 100,
                'null'    => true,
                'default' => null,
                'comment' => '操作者ログインID',
                'after'   => 'i_actor_user_id',
            ])
            ->update();
    }
}
