<?php
declare(strict_types=1);

namespace App\Test\Fixture;

use Cake\TestSuite\Fixture\TestFixture;

/**
 * TContactsFixture
 */
class TContactsFixture extends TestFixture
{
    public string $table = 't_contacts';

    public function init(): void
    {
        $this->records = [
            [
                'id'       => 1,
                'category' => '使い方の質問',
                'name'     => 'テスト太郎',
                'email'    => 'taro@example.com',
                'body'     => '予約の変更方法を教えてください。',
                'user_id'  => 1,
                'created'  => '2026-10-01 10:00:00',
                'modified' => '2026-10-01 10:00:00',
            ],
            [
                'id'       => 2,
                'category' => '不具合報告',
                'name'     => 'テスト花子',
                'email'    => 'hanako@example.com',
                'body'     => 'ログインできないことがあります。',
                'user_id'  => 2,
                'created'  => '2026-10-02 11:00:00',
                'modified' => '2026-10-02 11:00:00',
            ],
        ];
        parent::init();
    }
}
