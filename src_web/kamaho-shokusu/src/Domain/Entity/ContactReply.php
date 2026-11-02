<?php
declare(strict_types=1);

namespace App\Domain\Entity;

/**
 * 問い合わせへの返信1件。
 */
final class ContactReply
{
    public const AUTHOR_ADMIN = 'admin';
    public const AUTHOR_USER = 'user';

    /**
     * @param string|null $authorType AUTHOR_ADMIN（管理者）か AUTHOR_USER（問い合わせた本人）
     */
    public function __construct(
        public readonly string $body,
        public readonly ?string $authorType,
        public readonly ?\DateTimeImmutable $sentAt,
    ) {}
}
