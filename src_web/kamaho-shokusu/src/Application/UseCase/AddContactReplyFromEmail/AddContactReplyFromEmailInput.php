<?php
declare(strict_types=1);

namespace App\Application\UseCase\AddContactReplyFromEmail;

/**
 * 返信メール（Resend の受信 Webhook）から本人の返信を登録する依頼。
 */
final class AddContactReplyFromEmailInput
{
    /**
     * @param string $token 宛先 reply+{token}@... のトークン（本人であることの証明。Reply-To としてだけ配る）
     * @param string $externalMessageId Resend の email_id（再送の見分けに使う）
     */
    public function __construct(
        public readonly string $token,
        public readonly string $replyBody,
        public readonly string $externalMessageId,
    ) {}
}
