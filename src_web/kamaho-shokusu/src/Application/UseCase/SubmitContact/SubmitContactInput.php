<?php
declare(strict_types=1);

namespace App\Application\UseCase\SubmitContact;

/**
 * お問い合わせを送る依頼。
 */
final class SubmitContactInput
{
    /**
     * @param array<string, string> $data category・name・email・body（画面の入力）
     * @param int|null $userId ログイン中の利用者（未ログインは null）
     */
    public function __construct(
        public readonly array $data,
        public readonly ?int $userId,
    ) {}
}
