<?php
declare(strict_types=1);

namespace App\Domain\Entity;

/**
 * 問い合わせ（t_contacts）と、その返信（古い順）。
 */
final class Contact
{
    /** 問い合わせのカテゴリ（選択肢） */
    public const CATEGORIES = ['ご意見・ご要望', '不具合報告', '使い方の質問', 'その他'];

    /**
     * @param string|null $replyToken メールで返信を受け取るための宛先のトークン（移行前のデータは null）
     * @param list<ContactReply> $replies
     */
    public function __construct(
        public readonly int $id,
        public readonly string $category,
        public readonly string $name,
        public readonly string $email,
        public readonly string $body,
        public readonly ?int $userId,
        public readonly ?string $replyToken,
        public readonly ?\DateTimeImmutable $createdAt,
        public readonly array $replies = [],
    ) {}
}
