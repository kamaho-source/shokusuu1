<?php
declare(strict_types=1);

namespace App\Application\UseCase\SubmitContact;

use App\Application\Port\ContactNotifierInterface;
use App\Domain\Repository\ContactRepositoryInterface;

/**
 * 問い合わせを保存して、Slack・本人への自動返信・管理者へ知らせる（通知に失敗しても保存は取り消さない）。
 * 返信の宛先のトークンはここで作り、入力からは受け取らない。
 */
final class SubmitContactUseCase
{
    public function __construct(
        private readonly ContactRepositoryInterface $contacts,
        private readonly ContactNotifierInterface $notifier,
    ) {}

    /**
     * 入力（$input->data の category・name・email・body）を検証して保存し、通知する。
     */
    public function execute(SubmitContactInput $input): SubmitContactOutput
    {
        $data   = $input->data;
        $userId = $input->userId;
        $category = $data['category'] ?? '';
        $name     = trim($data['name'] ?? '');
        $email    = trim($data['email'] ?? '');
        $body     = trim($data['body'] ?? '');

        $result = $this->contacts->add($category, $name, $email, $body, $userId, bin2hex(random_bytes(16)));
        if ($result['id'] === null) {
            return new SubmitContactOutput(false, $category, $name, $email, $body, $result['errors']);
        }

        $contact = $this->contacts->find($result['id']);
        if ($contact !== null) {
            $this->notifier->contactReceived($contact);
        }

        return new SubmitContactOutput(true, $category, $name, $email, $body);
    }
}
