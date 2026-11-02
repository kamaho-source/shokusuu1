<?php
declare(strict_types=1);

namespace App\Application\UseCase\UpdateRoom;

/**
 * 部屋の編集の結果。
 */
final class UpdateRoomOutput
{
    /** 保存した（変わらなかった場合も含む） */
    public const UPDATED = 'updated';
    /** 部屋名がテーブルの入力チェックに当たった。$fieldErrors に理由。何も保存していない */
    public const INVALID_FIELDS = 'invalid_fields';
    /** 並び順の誤り（顔ぶれが変わった）。$message に理由。何も保存していない */
    public const REJECTED = 'rejected';
    /** 保存できなかった。何も保存していない */
    public const SAVE_FAILED = 'save_failed';

    /**
     * @param array<string, array<string, string>> $fieldErrors
     * @param int $reordered 並び替えで表示順が変わった人数
     */
    private function __construct(
        public readonly string $status,
        public readonly ?string $message = null,
        public readonly array $fieldErrors = [],
        public readonly int $reordered = 0,
    ) {}

    public static function updated(int $reordered = 0): self
    {
        return new self(self::UPDATED, reordered: $reordered);
    }

    /**
     * @param array<string, array<string, string>> $fieldErrors
     */
    public static function invalidFields(array $fieldErrors): self
    {
        return new self(self::INVALID_FIELDS, null, $fieldErrors);
    }

    public static function rejected(string $message): self
    {
        return new self(self::REJECTED, $message);
    }

    public static function saveFailed(): self
    {
        return new self(self::SAVE_FAILED);
    }
}
