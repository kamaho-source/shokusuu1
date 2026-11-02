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
     * @return bool 保存してメールも送れたか（メールだけ失敗しても履歴は残る）
     * @throws NotFoundException 問い合わせが無い
     */
    public function execute(int $contactId, string $replyBody): bool
    {
        $contact = $this->contacts->find($contactId) ?? throw new NotFoundException('お問い合わせが見つかりません。');
        $body = trim($replyBody);
        if (!$this->contacts->addReply($contactId, $body, ContactReply::AUTHOR_ADMIN)['saved']) {
            return false;
        }

        try {
            $this->notifier->replySent($contact, $body);
        } catch (\Throwable) {
            return false;
        }

        return true;
    }
}
