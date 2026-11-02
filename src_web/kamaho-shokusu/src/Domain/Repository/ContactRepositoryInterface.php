<?php
declare(strict_types=1);

namespace App\Domain\Repository;

use App\Domain\Entity\Contact;

/**
 * 問い合わせの保存先。
 */
interface ContactRepositoryInterface
{
    /**
     * 問い合わせを保存する。入力に誤りがあれば保存せず、項目ごとの理由を返す。
     *
     * @return array{id: int|null, errors: array<string, array<string, string>>}
     */
    public function add(string $category, string $name, string $email, string $body, ?int $userId, string $replyToken): array;

    /** 返信付きの問い合わせ。無ければ null。 */
    public function find(int $id): ?Contact;

    /** 本人（user_id が一致する人）の問い合わせ。無い・他人のものなら null。 */
    public function findForUser(int $id, int $userId): ?Contact;

    /** 返信の宛先トークンの問い合わせ。無ければ null。 */
    public function findByToken(string $token): ?Contact;

    /** その受信メールの返信がすでに保存されているか（Webhook の再送で二重に保存しないため）。 */
    public function replyExists(string $externalMessageId): bool;

    /**
     * 返信を保存する。同じ受信メールがすでに保存されていれば（並行して処理された場合も）duplicate を返す。
     *
     * @return array{saved: bool, duplicate: bool, errors: array<string, array<string, string>>}
     */
    public function addReply(int $contactId, string $body, string $authorType, ?string $externalMessageId = null): array;
}
