<?php
declare(strict_types=1);

namespace App\Application\UseCase\AddContactReplyFromUser;

use App\Application\Port\ContactNotifierInterface;
use App\Domain\Entity\ContactReply;
use App\Domain\Repository\ContactRepositoryInterface;

/**
 * 問い合わせた本人が画面から追加の返信を送る。保存できたら管理者へ知らせる。
 */
final class AddContactReplyFromUserUseCase
{
    public function __construct(
        private readonly ContactRepositoryInterface $contacts,
        private readonly ContactNotifierInterface $notifier,
    ) {}

    /**
     * 本人の問い合わせでなければ保存しない。
     */
    public function execute(AddContactReplyFromUserInput $input): AddContactReplyFromUserOutput
    {
        $contact = $this->contacts->findForUser($input->contactId, $input->userId);
        if ($contact === null) {
            return new AddContactReplyFromUserOutput(false);
        }

        $body = trim($input->replyBody);
        if (!$this->contacts->addReply($contact->id, $body, ContactReply::AUTHOR_USER)['saved']) {
            return new AddContactReplyFromUserOutput(false);
        }
        $this->notifier->userReplied($contact, $body);

        return new AddContactReplyFromUserOutput(true);
    }
}
