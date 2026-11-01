<?php
declare(strict_types=1);

namespace App\Application\UseCase\EditUser;

/**
 * 利用者の編集の結果。
 */
final class EditUserOutput
{
    /** 保存した */
    public const UPDATED = 'updated';
    /** 入力の誤り（性別・年代・役職・職員ID）。$message に理由。何も保存していない */
    public const REJECTED = 'rejected';
    /** 保存できなかった（テーブルの入力チェック・ルールを含む）。$fieldErrors に項目ごとの理由 */
    public const SAVE_FAILED = 'save_failed';

    /**
     * @param array<string, array<string, string>> $fieldErrors
     * @param list<int> $roomIds 保存しようとした所属部屋（画面に選んだ状態で出し直すため）
     */
    private function __construct(
        public readonly string $status,
        public readonly ?string $message = null,
        public readonly array $fieldErrors = [],
        public readonly array $roomIds = [],
    ) {}

    public static function updated(): self
    {
        return new self(self::UPDATED);
    }

    public static function rejected(string $message): self
    {
        return new self(self::REJECTED, $message);
    }

    /**
     * @param array<string, array<string, string>> $fieldErrors
     * @param list<int> $roomIds
     */
    public static function saveFailed(array $fieldErrors, array $roomIds): self
    {
        return new self(self::SAVE_FAILED, null, $fieldErrors, $roomIds);
    }
}
