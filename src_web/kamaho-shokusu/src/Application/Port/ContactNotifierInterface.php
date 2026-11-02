<?php
declare(strict_types=1);

namespace App\Application\Port;

use App\Domain\Entity\Contact;

/**
 * 問い合わせの通知（メール・Slack）。
 */
interface ContactNotifierInterface
{
    /**
     * 新しい問い合わせを知らせる（Slack・本人への自動返信・管理者への通知）。
     * それぞれ失敗してもログに残して続ける（問い合わせの保存は取り消さない）。
     */
    public function contactReceived(Contact $contact): void;

    /**
     * 管理者の返信を本人へメールで送る。
     *
     * @throws \Throwable 送れなかったとき（返信の履歴は保存済み）
     */
    public function replySent(Contact $contact, string $replyBody): void;

    /** 本人からの追加の返信を管理者へ知らせる。失敗してもログに残して続ける。 */
    public function userReplied(Contact $contact, string $replyBody): void;
}
