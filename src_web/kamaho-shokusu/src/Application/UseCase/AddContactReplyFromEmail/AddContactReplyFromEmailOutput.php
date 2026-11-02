<?php
declare(strict_types=1);

namespace App\Application\UseCase\AddContactReplyFromEmail;

/**
 * 返信メールからの返信の結果。
 */
final class AddContactReplyFromEmailOutput
{
    /** 保存して管理者へ知らせた */
    public const SAVED = 'saved';
    /** 同じメールはすでに保存済み（Webhook の再送）。成功として扱う */
    public const DUPLICATE = 'duplicate';
    /** トークンに当たる問い合わせがない */
    public const UNKNOWN_TOKEN = 'unknown_token';
    /** 入力チェックに当たって保存できなかった。$errors に理由 */
    public const INVALID = 'invalid';

    /**
     * @param array{token: string}|array<string, array<string, string>> $errors
     *   UNKNOWN_TOKEN は ['token' => 文言]（文字列）、INVALID は 項目 => [ルール => 文言]（テーブルの入力チェックの形）。
     *   SAVED・DUPLICATE は空。
     */
    private function __construct(
        public readonly string $status,
        public readonly array $errors = [],
    ) {}

    public static function saved(): self
    {
        return new self(self::SAVED);
    }

    public static function duplicate(): self
    {
        return new self(self::DUPLICATE);
    }

    public static function unknownToken(): self
    {
        return new self(self::UNKNOWN_TOKEN, ['token' => '不明な返信先トークンです。']);
    }

    /**
     * @param array<string, array<string, string>> $errors
     */
    public static function invalid(array $errors): self
    {
        return new self(self::INVALID, $errors);
    }

    /**
     * 保存できた、またはすでに保存済みか。
     */
    public function succeeded(): bool
    {
        return $this->status === self::SAVED || $this->status === self::DUPLICATE;
    }
}
