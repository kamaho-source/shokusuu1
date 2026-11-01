<?php
declare(strict_types=1);

namespace App\Domain\Entity;

/**
 * 利用者を新しく保存した結果。
 */
final class UserCreationResult
{
    /**
     * @param int|null $userId 保存できたときの利用者 ID
     * @param array<string, array<string, string>> $fieldErrors 項目ごとの入力の誤り（項目 => [規則 => 文言]）
     */
    private function __construct(
        public readonly ?int $userId,
        public readonly array $fieldErrors,
    ) {}

    public static function created(int $userId): self
    {
        return new self($userId, []);
    }

    /**
     * @param array<string, array<string, string>> $fieldErrors
     */
    public static function invalid(array $fieldErrors): self
    {
        return new self(null, $fieldErrors);
    }

    public static function saveFailed(): self
    {
        return new self(null, []);
    }

    public function isCreated(): bool
    {
        return $this->userId !== null;
    }
}
