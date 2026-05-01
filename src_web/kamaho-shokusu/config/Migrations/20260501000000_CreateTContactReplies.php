<?php
declare(strict_types=1);

use Migrations\AbstractMigration;

class CreateTContactReplies extends AbstractMigration
{
    public function change(): void
    {
        $table = $this->table('t_contact_replies');
        $table
            ->addColumn('contact_id', 'integer', ['null' => false, 'signed' => false])
            ->addColumn('body', 'text', ['null' => false])
            ->addColumn('sent_at', 'datetime', ['null' => false])
            ->addColumn('created', 'datetime', ['null' => false])
            ->addColumn('modified', 'datetime', ['null' => false])
            ->addForeignKey('contact_id', 't_contacts', 'id', [
                'delete' => 'CASCADE',
                'update' => 'NO_ACTION',
            ])
            ->create();
    }
}
