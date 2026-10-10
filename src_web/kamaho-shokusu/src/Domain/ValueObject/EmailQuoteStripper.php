<?php
declare(strict_types=1);

namespace App\Domain\ValueObject;

/**
 * 受信メールの本文から、引用された元メールの部分を取り除き、
 * 新規に書かれた返信本文のみを抽出する。
 *
 * メールクライアントごとに引用形式が異なるため完全な除去は保証できない
 * ベストエフォートの実装（よくある区切りパターンに達した時点で以降を破棄する）。
 */
final class EmailQuoteStripper
{
    /** @var list<string> 行頭からこれらの正規表現にマッチしたら、その行以降を破棄する */
    private const QUOTE_MARKER_PATTERNS = [
        '/^-{2,}\s*(Original Message|元のメッセージ)\s*-{2,}$/iu',
        '/^_{10,}$/u',
        '/^On .+ wrote:$/u',
        '/^\d{4}[-\/]\d{1,2}[-\/]\d{1,2}.*(さんは|さんが).*(書きました|書いた)[:：]?$/u',
        // Gmail日本語UIの引用開始行: 「2026年10月10日(土) 10:31 差出人名 <email>:」
        '/^\d{4}年\d{1,2}月\d{1,2}日\(.+?\)\s+\d{1,2}:\d{2}\s+.+?<[^<>]+@[^<>]+>\s*[:：]?$/u',
        '/^差出人[:：]/u',
    ];

    /**
     * メール本文から引用部分を取り除き、新規に書かれた返信本文のみを返す。
     */
    public static function strip(string $body): string
    {
        $lines = explode("\n", str_replace("\r\n", "\n", $body));
        $kept = [];

        foreach ($lines as $line) {
            if (self::isQuoteMarker($line)) {
                break;
            }
            $kept[] = $line;
        }

        return trim(implode("\n", $kept));
    }

    private static function isQuoteMarker(string $line): bool
    {
        $trimmed = trim($line);

        if (str_starts_with($trimmed, '>')) {
            return true;
        }

        foreach (self::QUOTE_MARKER_PATTERNS as $pattern) {
            if (preg_match($pattern, $trimmed) === 1) {
                return true;
            }
        }

        return false;
    }

    private function __construct()
    {
    }
}
