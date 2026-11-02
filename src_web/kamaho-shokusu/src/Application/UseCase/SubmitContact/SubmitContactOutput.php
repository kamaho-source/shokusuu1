<?php
declare(strict_types=1);

namespace App\Application\UseCase\SubmitContact;

/**
 * 問い合わせの送信の結果。保存できなかったときは、入力を出し直すための値と項目ごとの理由を持つ。
 */
final class SubmitContactOutput
{
    /**
     * @param array<string, array<string, string>> $errors 項目 => 理由
     */
    public function __construct(
        public readonly bool $saved,
        public readonly string $category,
        public readonly string $name,
        public readonly string $email,
        public readonly string $body,
        public readonly array $errors = [],
    ) {}

    /**
     * 項目の理由（テンプレートの各欄に出す）。無ければ null。
     *
     * @return array<string, string>|null
     */
    public function getError(string $field): ?array
    {
        return $this->errors[$field] ?? null;
    }
}
