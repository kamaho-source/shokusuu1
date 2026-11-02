<?php
declare(strict_types=1);

namespace App\Application\UseCase\AddContactReplyFromUser;

/**
 * 問い合わせた本人が画面から追加の返信を送る依頼。
 */
final class AddContactReplyFromUserInput
{
    public function __construct(
        public readonly int $contactId,
        public readonly int $userId,
        public readonly string $replyBody,
    ) {}
}
