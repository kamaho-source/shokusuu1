<?php
declare(strict_types=1);

namespace App\Infrastructure\Notification;

use App\Application\Port\ContactNotifierInterface;
use App\Domain\Entity\Contact;
use Cake\Http\Client;
use Cake\Log\Log;
use Cake\Mailer\Mailer;

/**
 * ContactNotifierInterface の実装（CakePHP の Mailer と Slack の Webhook）。文面は以前の ContactService と同じ。
 */
final class CakeContactNotifier implements ContactNotifierInterface
{
    /**
     * 自動返信・管理者通知メールの送信元アドレス。
     * メール設定(Email profile)のfromに依存せず常に有効なFromを明示するため、サービス側で保持する。
     */
    private const MAIL_FROM = ['no-reply@kamaho-shokusu.jp' => '鎌倉児童ホーム食数管理システム'];

    /** 管理者通知の宛先が未設定(デフォルト値)のときはメール送信をスキップする判定に使う。 */
    private const ADMIN_EMAIL_UNSET = 'admin@localhost';

    /**
     * 受信メール返信用ドメインのデフォルト値。
     * 本番では Configure('App.inboundReplyDomain') で上書きする想定（Resendの受信設定に合わせる）。
     */
    private const INBOUND_REPLY_DOMAIN_DEFAULT = 'reply.kamaho-shokusu.jp';

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

    public function contactReceived(Contact $contact): void
    {
        foreach (['notifySlack', 'sendAutoReply', 'sendAdminNotification'] as $method) {
            try {
                $this->{$method}($contact);
            } catch (\Throwable $e) {
                $this->logNotificationFailure($method, $e, $contact->id);
            }
        }
    }

    public function replySent(Contact $contact, string $replyBody): void
    {
        try {
            $this->sendReplyMail($contact, $replyBody);
        } catch (\Throwable $e) {
            $this->logNotificationFailure('sendReplyMail', $e, $contact->id);
            throw $e;
        }
    }

    public function userReplied(Contact $contact, string $replyBody): void
    {
        try {
            $this->notifyAdminOfUserReply($contact, $replyBody);
        } catch (\Throwable $e) {
            $this->logNotificationFailure('notifyAdminOfUserReply', $e, $contact->id);
        }
    }

    private function logNotificationFailure(string $action, \Throwable $e, int $contactId): void
    {
        Log::error(sprintf(
            'ContactService %s failed (contactId=%d): %s',
            $action,
            $contactId,
            $e->getMessage()
        ));
    }

    /**
     * Slackへ問い合わせ内容を通知する。
     */
    private function notifySlack(Contact $entity): void
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
    private function sendAutoReply(Contact $entity): void
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
            '鎌倉児童ホーム食数管理システム',
            'no-reply@kamaho-shokusu.jp',
        ]);

        $mailer = new Mailer('default');
        $mailer->setFrom(self::MAIL_FROM);
        $this->applyReplyTo($mailer, $entity);
        $mailer
            ->setTo($entity->email, $entity->name)
            ->setSubject($template['subject'])
            ->setEmailFormat('text')
            ->deliver($body);
    }

    /**
     * 管理者から問い合わせ者へ返信メールを送信する。
     */
    private function sendReplyMail(Contact $contact, string $replyBody): void
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
            '鎌倉児童ホーム食数管理システム サポート',
            'support@kamaho-shokusu.jp',
        ]);

        $mailer = new Mailer('default');
        $mailer->setFrom(['support@kamaho-shokusu.jp' => '鎌倉児童ホーム食数管理システム サポート']);
        $this->applyReplyTo($mailer, $contact);
        $mailer
            ->setTo($contact->email, $contact->name)
            ->setSubject('[食数管理システム] Re: ' . $contact->category . 'について')
            ->setEmailFormat('text')
            ->deliver($body);
    }

    /**
     * メールに返信専用アドレスをReply-Toとして設定する。
     * reply_token が未発行（移行前の既存データ等）の場合は何もしない。
     */
    private function applyReplyTo(Mailer $mailer, Contact $contact): void
    {
        if (empty($contact->replyToken)) {
            return;
        }

        $mailer->setReplyTo(self::buildReplyToAddress($contact->replyToken));
    }

    /**
     * 受信メール返信の宛先（reply+{token}@{domain}）を組み立てる。
     */
    private static function buildReplyToAddress(string $token): string
    {
        $domain = (string)\Cake\Core\Configure::read('App.inboundReplyDomain', self::INBOUND_REPLY_DOMAIN_DEFAULT);

        return 'reply+' . $token . '@' . $domain;
    }

    /**
     * 管理者通知メールの送信をスキップすべきか判定する。
     *
     * 宛先(App.adminEmail)が空、またはデフォルト値(admin@localhost)のままのときは
     * 無効アドレスへの送信になるためスキップする。DB非依存の純粋関数。
     *
     * @param string $adminEmail 管理者通知の宛先
     * @return bool true=送信しない
     */
    public static function shouldSkipAdminNotification(string $adminEmail): bool
    {
        return trim($adminEmail) === '' || $adminEmail === self::ADMIN_EMAIL_UNSET;
    }

    /**
     * 管理者へ通知メールを送信する。
     */
    private function sendAdminNotification(Contact $entity): void
    {
        $adminEmail = (string)\Cake\Core\Configure::read('App.adminEmail', self::ADMIN_EMAIL_UNSET);

        // 宛先が未設定(デフォルト値)または空のときは送信しない。
        // 無効アドレスへの送信試行で無駄な失敗ログ・バウンスを発生させないため。
        if (self::shouldSkipAdminNotification($adminEmail)) {
            return;
        }

        $mailer = new Mailer('default');
        $mailer
            ->setFrom(self::MAIL_FROM)
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

    /**
     * 問い合わせ者本人からの追加返信を管理者へ通知する。
     */
    private function notifyAdminOfUserReply(Contact $contact, string $replyBody): void
    {
        $adminEmail = (string)\Cake\Core\Configure::read('App.adminEmail', self::ADMIN_EMAIL_UNSET);

        if (self::shouldSkipAdminNotification($adminEmail)) {
            return;
        }

        $mailer = new Mailer('default');
        $mailer
            ->setFrom(self::MAIL_FROM)
            ->setTo($adminEmail)
            ->setSubject('[食数管理システム] お問い合わせに返信がありました：' . $contact->category)
            ->setEmailFormat('text')
            ->deliver(implode("\n", [
                $contact->name . ' 様からお問い合わせへの返信がありました。',
                '',
                'カテゴリ：' . $contact->category,
                'お名前：'  . $contact->name,
                'メール：'  . $contact->email,
                '',
                '--- 返信内容 ---',
                $replyBody,
                '',
                '--- 元のお問い合わせ内容 ---',
                $contact->body,
            ]));
    }
}
