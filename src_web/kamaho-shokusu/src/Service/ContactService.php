<?php
declare(strict_types=1);

namespace App\Service;

use App\Model\Table\TContactRepliesTable;
use App\Model\Table\TContactsTable;
use Cake\Http\Client;
use Cake\I18n\DateTime;
use Cake\Mailer\Mailer;
use Cake\ORM\TableRegistry;

class ContactService
{
    private TContactsTable $contacts;
    private TContactRepliesTable $replies;

    /** カテゴリ別の自動返信メール文面 */
    private const AUTO_REPLY_TEMPLATES = [
        'ご意見・ご要望' => [
            'subject' => '【食数管理システム】ご意見・ご要望を受け付けました',
            'message' => "この度はご意見・ご要望をお寄せいただき、誠にありがとうございます。\n\nいただいたご意見は、サービス改善の参考にさせていただきます。\n今後ともかまほ食数管理システムをよろしくお願いいたします。",
        ],
        '不具合報告' => [
            'subject' => '【食数管理システム】不具合報告を受け付けました',
            'message' => "不具合のご報告をいただき、誠にありがとうございます。\n\n担当者が内容を確認し、早急に対応いたします。\n解決までいましばらくお待ちください。",
        ],
        '使い方の質問' => [
            'subject' => '【食数管理システム】お問い合わせを受け付けました',
            'message' => "お問い合わせいただき、誠にありがとうございます。\n\n担当者が内容を確認の上、順次ご回答いたします。\n今しばらくお待ちくださいますようお願いいたします。",
        ],
        'その他' => [
            'subject' => '【食数管理システム】お問い合わせを受け付けました',
            'message' => "お問い合わせいただき、誠にありがとうございます。\n\n内容を確認の上、担当者よりご連絡いたします。",
        ],
    ];

    public function __construct()
    {
        /** @var TContactsTable $table */
        $table = TableRegistry::getTableLocator()->get('TContacts');
        $this->contacts = $table;

        /** @var TContactRepliesTable $replies */
        $replies = TableRegistry::getTableLocator()->get('TContactReplies');
        $this->replies = $replies;
    }

    /**
     * 問い合わせを保存してSlack通知・自動返信メールを送信する。
     *
     * @param array $data フォームデータ
     * @param int|null $userId ログインユーザーID
     * @return array{success: bool, entity: \App\Model\Entity\TContact}
     */
    public function submit(array $data, ?int $userId): array
    {
        $entity = $this->contacts->newEntity([
            'category' => $data['category'] ?? '',
            'name'     => trim($data['name'] ?? ''),
            'email'    => trim($data['email'] ?? ''),
            'body'     => trim($data['body'] ?? ''),
            'user_id'  => $userId,
        ]);

        if ($entity->getErrors()) {
            return ['success' => false, 'entity' => $entity];
        }

        if (!$this->contacts->save($entity)) {
            return ['success' => false, 'entity' => $entity];
        }

        try {
            $this->notifySlack($entity);
        } catch (\Throwable) {
        }

        try {
            $this->sendAutoReply($entity);
        } catch (\Throwable) {
        }

        try {
            $this->sendAdminNotification($entity);
        } catch (\Throwable) {
        }

        return ['success' => true, 'entity' => $entity];
    }

    /**
     * 問い合わせ一覧を取得する（管理者用）。
     */
    public function getList(int $page = 1, int $limit = 30): array
    {
        return $this->contacts->find()
            ->contain(['TContactReplies'])
            ->orderByDesc('created')
            ->limit($limit)
            ->page($page)
            ->toArray();
    }

    /**
     * 問い合わせ1件を返信付きで取得する。
     *
     * @throws \Cake\Datasource\Exception\RecordNotFoundException
     */
    public function getDetail(int $contactId): \App\Model\Entity\TContact
    {
        /** @var \App\Model\Entity\TContact */
        return $this->contacts->find()
            ->contain(['TContactReplies' => fn($q) => $q->orderByAsc('created')])
            ->where(['TContacts.id' => $contactId])
            ->firstOrFail();
    }

    /**
     * 管理者から問い合わせ者へ返信メールを送信し、履歴を保存する。
     *
     * @return array{success: bool, errors: array}
     */
    public function sendReply(int $contactId, string $replyBody): array
    {
        $contact = $this->getDetail($contactId);

        $reply = $this->replies->newEntity([
            'contact_id' => $contactId,
            'body'       => trim($replyBody),
            'sent_at'    => new DateTime(),
        ]);

        if ($reply->getErrors()) {
            return ['success' => false, 'errors' => $reply->getErrors()];
        }

        if (!$this->replies->save($reply)) {
            return ['success' => false, 'errors' => ['save' => '保存に失敗しました。']];
        }

        try {
            $this->sendReplyMail($contact, trim($replyBody));
        } catch (\Throwable) {
        }

        return ['success' => true, 'errors' => []];
    }

    /**
     * Slackへ問い合わせ内容を通知する。
     */
    private function notifySlack(\App\Model\Entity\TContact $entity): void
    {
        $webhookUrl = env('SLACK_CONTACT_WEBHOOK', '');
        if ($webhookUrl === '') {
            return;
        }

        $text = implode("\n", [
            ':mailbox_with_mail: *新しいお問い合わせが届きました*',
            '>*カテゴリ：* ' . $entity->category,
            '>*お名前：* ' . $entity->name,
            '>*メール：* ' . $entity->email,
            '>*内容：*',
            '>```' . $entity->body . '```',
        ]);

        $http = new Client();
        $http->post($webhookUrl, json_encode(['text' => $text]), [
            'type' => 'json',
        ]);
    }

    /**
     * カテゴリに応じた自動返信メールを送信する。
     */
    private function sendAutoReply(\App\Model\Entity\TContact $entity): void
    {
        if (empty($entity->email)) {
            return;
        }

        $template = self::AUTO_REPLY_TEMPLATES[$entity->category]
            ?? self::AUTO_REPLY_TEMPLATES['その他'];

        $body = implode("\n", [
            $entity->name . ' 様',
            '',
            $template['message'],
            '',
            '─────────────────────────────',
            '【お問い合わせ内容】',
            'カテゴリ：' . $entity->category,
            '',
            $entity->body,
            '─────────────────────────────',
            'かまほ食数管理システム',
            'no-reply@kamaho-shokusu.jp',
        ]);

        $mailer = new Mailer('default');
        $mailer
            ->setTo($entity->email, $entity->name)
            ->setSubject($template['subject'])
            ->setEmailFormat('text')
            ->deliver($body);
    }

    /**
     * 管理者から問い合わせ者へ返信メールを送信する。
     */
    private function sendReplyMail(\App\Model\Entity\TContact $contact, string $replyBody): void
    {
        if (empty($contact->email)) {
            return;
        }

        $body = implode("\n", [
            $contact->name . ' 様',
            '',
            'お問い合わせいただきありがとうございます。',
            '以下の通りご回答申し上げます。',
            '',
            '─────────────────────────────',
            $replyBody,
            '─────────────────────────────',
            '',
            '【元のお問い合わせ内容】',
            'カテゴリ：' . $contact->category,
            $contact->body,
            '─────────────────────────────',
            'かまほ食数管理システム サポート',
            'support@kamaho-shokusu.jp',
        ]);

        $mailer = new Mailer('default');
        $mailer
            ->setFrom(['support@kamaho-shokusu.jp' => 'かまほ食数管理システム サポート'])
            ->setTo($contact->email, $contact->name)
            ->setSubject('[食数管理システム] Re: ' . $contact->category . 'について')
            ->setEmailFormat('text')
            ->deliver($body);
    }

    /**
     * 管理者へ通知メールを送信する。
     */
    private function sendAdminNotification(\App\Model\Entity\TContact $entity): void
    {
        $adminEmail = \Cake\Core\Configure::read('App.adminEmail', 'admin@localhost');

        $mailer = new Mailer('default');
        $mailer
            ->setTo($adminEmail)
            ->setSubject('[食数管理システム] 新しいお問い合わせ：' . $entity->category)
            ->setEmailFormat('text')
            ->deliver(implode("\n", [
                '新しいお問い合わせが届きました。',
                '',
                'カテゴリ：' . $entity->category,
                'お名前：'  . $entity->name,
                'メール：'  . $entity->email,
                '',
                '--- 内容 ---',
                $entity->body,
            ]));
    }
}
