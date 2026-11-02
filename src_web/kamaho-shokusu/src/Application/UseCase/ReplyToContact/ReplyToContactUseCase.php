<?php
declare(strict_types=1);

namespace App\Application\UseCase\ReplyToContact;

use App\Application\Port\ContactNotifierInterface;
use App\Domain\Entity\ContactReply;
use App\Domain\Exception\NotFoundException;
use App\Domain\Repository\ContactRepositoryInterface;

/**
 * 管理者が問い合わせに返信する（履歴を保存して、本人へメールで送る）。
 */
final class ReplyToContactUseCase
{
    public function __construct(
        private readonly ContactRepositoryInterface $contacts,
        private readonly ContactNotifierInterface $notifier,
    ) {}

    /**
     * @throws NotFoundException 問い合わせが無い
     */
    public function execute(ReplyToContactInput $input): ReplyToContactOutput
    {
        $contact = $this->contacts->find($input->contactId) ?? throw new NotFoundException('お問い合わせが見つかりません。');
        $body = trim($input->replyBody);
        if (!$this->contacts->addReply($input->contactId, $body, ContactReply::AUTHOR_ADMIN)['saved']) {
            return new ReplyToContactOutput(false);
        }

        try {
            $this->notifier->replySent($contact, $body);
        } catch (\Throwable) {
            return new ReplyToContactOutput(false);
        }

        return new ReplyToContactOutput(true);
    }
}
