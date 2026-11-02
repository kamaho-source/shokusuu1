<?php
declare(strict_types=1);

namespace App\Domain\Entity;

/**
 * 部屋を保存した結果。
 */
final class RoomSaveResult
{
    /**
     * @param int|null $roomId 保存できたときの部屋 ID
     * @param bool $changed 保存で値が変わったか（名前が同じなら保存しない）
     * @param array<string, array<string, string>> $fieldErrors 項目ごとの入力の誤り
     */
    private function __construct(
        public readonly ?int $roomId,
        public readonly bool $changed = false,
        public readonly array $fieldErrors = [],
    ) {}

    public static function saved(int $roomId, bool $changed = true): self
    {
        return new self($roomId, $changed);
    }

    /**
     * @param array<string, array<string, string>> $fieldErrors 無ければ空（DB の保存の失敗）
     */
    public static function failed(array $fieldErrors): self
    {
        return new self(null, false, $fieldErrors);
    }

    public function isSaved(): bool
    {
        return $this->roomId !== null;
    }
}
