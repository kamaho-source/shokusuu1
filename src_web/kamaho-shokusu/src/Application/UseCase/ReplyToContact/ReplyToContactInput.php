<?php
declare(strict_types=1);

namespace App\Application\UseCase\ReplyToContact;

/**
 * 管理者がお問い合わせに返信する依頼。
 */
final class ReplyToContactInput
{
    public function __construct(
        public readonly int $contactId,
        public readonly string $replyBody,
    ) {}
}
