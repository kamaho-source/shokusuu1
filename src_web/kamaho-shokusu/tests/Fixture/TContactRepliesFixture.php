<?php
declare(strict_types=1);

namespace App\Test\Fixture;

use Cake\TestSuite\Fixture\TestFixture;

/**
 * TContactRepliesFixture
 */
class TContactRepliesFixture extends TestFixture
{
    public string $table = 't_contact_replies';

    public function init(): void
    {
        $this->records = [
            [
                'id'          => 1,
                'contact_id'  => 1,
                'body'        => '予約の変更方法についてご案内いたします。',
                'author_type' => 'admin',
                'sent_at'     => '2026-10-01 12:00:00',
                'created'     => '2026-10-01 12:00:00',
                'modified'    => '2026-10-01 12:00:00',
            ],
        ];
        parent::init();
    }
}
