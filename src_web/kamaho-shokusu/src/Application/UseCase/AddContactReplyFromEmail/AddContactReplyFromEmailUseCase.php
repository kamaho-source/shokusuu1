<?php
declare(strict_types=1);

namespace App\Application\UseCase\AddContactReplyFromEmail;

use App\Application\Port\ContactNotifierInterface;
use App\Domain\Entity\ContactReply;
use App\Domain\Repository\ContactRepositoryInterface;

/**
 * 返信メール（Resend の受信 Webhook）から本人の返信を登録する。保存できたら管理者へ知らせる。
 */
final class AddContactReplyFromEmailUseCase
{
    public function __construct(
        private readonly ContactRepositoryInterface $contacts,
        private readonly ContactNotifierInterface $notifier,
    ) {}

    /**
     * 同じメールの再送は保存し直さず DUPLICATE を返す。
     */
    public function execute(AddContactReplyFromEmailInput $input): AddContactReplyFromEmailOutput
    {
        $contact = $this->contacts->findByToken($input->token);
        if ($contact === null) {
            return AddContactReplyFromEmailOutput::unknownToken();
        }
        if ($this->contacts->replyExists($input->externalMessageId)) {
            return AddContactReplyFromEmailOutput::duplicate();
        }

        $body = trim($input->replyBody);
        $result = $this->contacts->addReply($contact->id, $body, ContactReply::AUTHOR_USER, $input->externalMessageId);
        if ($result['duplicate']) {
            return AddContactReplyFromEmailOutput::duplicate();
        }
        if (!$result['saved']) {
            return AddContactReplyFromEmailOutput::invalid($result['errors']);
        }
        $this->notifier->userReplied($contact, $body);

        return AddContactReplyFromEmailOutput::saved();
    }
}
