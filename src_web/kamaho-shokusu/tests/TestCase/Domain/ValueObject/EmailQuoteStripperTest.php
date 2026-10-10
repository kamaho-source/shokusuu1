<?php
declare(strict_types=1);

namespace App\Test\TestCase\Domain\ValueObject;

use App\Domain\ValueObject\EmailQuoteStripper;
use Cake\TestSuite\TestCase;

class EmailQuoteStripperTest extends TestCase
{
    public function testStrip_plainReplyWithoutQuote_returnsAsIs(): void
    {
        $body = "ありがとうございます。\n確認いたしました。";

        $this->assertSame($body, EmailQuoteStripper::strip($body));
    }

    public function testStrip_gmailStyleQuote_cutsAtOnWroteLine(): void
    {
        $body = "承知しました。\n\nOn 2026-10-10 12:00, Support <support@example.com> wrote:\n> 元のメッセージ本文\n> 続き";

        $this->assertSame('承知しました。', EmailQuoteStripper::strip($body));
    }

    public function testStrip_greaterThanQuotedLines_cutsAtFirstQuoteLine(): void
    {
        $body = "返信内容です。\n> 引用された行1\n> 引用された行2";

        $this->assertSame('返信内容です。', EmailQuoteStripper::strip($body));
    }

    public function testStrip_originalMessageSeparator_cutsThere(): void
    {
        $body = "対応お願いします。\n\n-----Original Message-----\n差出人: support@example.com\n元の本文";

        $this->assertSame('対応お願いします。', EmailQuoteStripper::strip($body));
    }

    public function testStrip_japaneseOriginalMessageSeparator_cutsThere(): void
    {
        $body = "確認しました。\n\n-----元のメッセージ-----\n差出人: サポート";

        $this->assertSame('確認しました。', EmailQuoteStripper::strip($body));
    }

    public function testStrip_underscoreSeparator_cutsThere(): void
    {
        $body = "承知しました。\n__________________\n元のメール本文";

        $this->assertSame('承知しました。', EmailQuoteStripper::strip($body));
    }

    public function testStrip_gmailJapaneseStyleQuote_cutsAtDateTimeSenderLine(): void
    {
        // Gmail日本語UIが引用開始に挿入する実際の形式（本番で確認済み）。
        $body = "テスト返信返信\n\n\n大橋 和幸\n\n2026年10月10日(土) 10:31 鎌倉児童ホーム食数管理システム サポート <support@kamaho-shokusu.jp>:\n> 元の本文";

        $this->assertSame("テスト返信返信\n\n\n大橋 和幸", EmailQuoteStripper::strip($body));
    }

    public function testStrip_trimsTrailingWhitespace(): void
    {
        $body = "本文です。   \n\n\n";

        $this->assertSame('本文です。', EmailQuoteStripper::strip($body));
    }
}
