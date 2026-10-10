<?php
declare(strict_types=1);

use Migrations\AbstractMigration;

class AddInboundReplySupport extends AbstractMigration
{
    /**
     * t_contacts に返信メールの宛先特定用トークンを、
     * t_contact_replies に受信メールの重複処理防止用IDを追加する。
     */
    public function change(): void
    {
        $this->table('t_contacts')
            ->addColumn('reply_token', 'string', [
                'limit'   => 64,
                'null'    => true,
                'default' => null,
                'comment' => '受信メール返信をスレッドへ紐付けるための固有トークン',
                'after'   => 'user_id',
            ])
            ->addIndex(['reply_token'], ['unique' => true])
            ->update();

        $this->table('t_contact_replies')
            ->addColumn('external_message_id', 'string', [
                'limit'   => 255,
                'null'    => true,
                'default' => null,
                'comment' => 'Resend受信メールID（Webhook再送時の重複登録防止）',
                'after'   => 'author_type',
            ])
            ->addIndex(['external_message_id'], ['unique' => true])
            ->update();
    }
}
