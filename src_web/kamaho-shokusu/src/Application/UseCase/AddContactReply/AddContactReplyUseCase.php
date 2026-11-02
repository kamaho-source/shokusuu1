<?php
declare(strict_types=1);

namespace App\Application\UseCase\AddContactReply;

use App\Application\Port\ContactNotifierInterface;
use App\Domain\Entity\Contact;
use App\Domain\Entity\ContactReply;
use App\Domain\Repository\ContactRepositoryInterface;

/**
 * 問い合わせた本人が追加の返信を送る（画面から、または返信メールから）。管理者へ知らせる。
 */
final class AddContactReplyUseCase
{
    public function __construct(
        private readonly ContactRepositoryInterface $contacts,
        private readonly ContactNotifierInterface $notifier,
    ) {}

    /**
     * 画面からの返信。本人の問い合わせでなければ保存しない。
     *
     * @return bool 保存できたか
     */
    public function fromUser(int $contactId, int $userId, string $replyBody): bool
    {
        $contact = $this->contacts->findForUser($contactId, $userId);

        return $contact !== null && $this->save($contact, trim($replyBody), null);
    }

    /**
     * 返信メール（Resend の受信 Webhook）からの返信。トークンが本人であることの証明（Reply-To としてだけ配る）。
     * 同じメールの再送は保存し直さず、成功として扱う。
     *
     * @return array{success: bool, errors: array<string, mixed>}
     */
    public function fromEmail(string $token, string $replyBody, string $externalMessageId): array
    {
        $contact = $this->contacts->findByToken($token);
        if ($contact === null) {
            return ['success' => false, 'errors' => ['token' => '不明な返信先トークンです。']];
        }
        if ($this->contacts->replyExists($externalMessageId)) {
            return ['success' => true, 'errors' => []];
        }

        $result = $this->contacts->addReply($contact->id, trim($replyBody), ContactReply::AUTHOR_USER, $externalMessageId);
        if ($result['duplicate']) {
            return ['success' => true, 'errors' => []];
        }
        if (!$result['saved']) {
            return ['success' => false, 'errors' => $result['errors']];
        }
        $this->notifier->userReplied($contact, trim($replyBody));

        return ['success' => true, 'errors' => []];
    }

    private function save(Contact $contact, string $body, ?string $externalMessageId): bool
    {
        if (!$this->contacts->addReply($contact->id, $body, ContactReply::AUTHOR_USER, $externalMessageId)['saved']) {
            return false;
        }
        $this->notifier->userReplied($contact, $body);

        return true;
    }
}
