<?php
declare(strict_types=1);

use Migrations\AbstractMigration;

class AddAuthorTypeToTContactReplies extends AbstractMigration
{
    public function change(): void
    {
        $table = $this->table('t_contact_replies');
        $table
            ->addColumn('author_type', 'string', [
                'limit'   => 10,
                'null'    => false,
                'default' => 'admin',
                'after'   => 'body',
            ])
            ->update();
    }
}
