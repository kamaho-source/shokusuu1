<?php
declare(strict_types=1);

namespace App\Domain\Service;

/**
 * 期間（開始日〜終了日）の決まり。予約履歴・部屋使用率で使う。過去日も未来日も、期間の長さも制限しない（日付の形式と前後だけ確かめる）。
 */
final class DatePeriodRule
{
    /**
     * 期間の問題。問題が無ければ null。
     */
    public static function error(string $from, string $to): ?string
    {
        if (!self::isDate($from) || !self::isDate($to)) {
            return '日付の形式が正しくありません。';
        }
        if ($from > $to) {
            return '開始日が終了日より後になっています。';
        }

        return null;
    }

    /**
     * 期間のすべての日付（Y-m-d、古い順）。予約状況・部屋集計の表は、予約の有無に関係なくこの日付で行を並べる。
     *
     * @return list<string>
     */
    public static function dates(string $from, string $to): array
    {
        $out = [];
        $end = new \DateTimeImmutable($to);
        for ($d = new \DateTimeImmutable($from); $d <= $end; $d = $d->modify('+1 day')) {
            $out[] = $d->format('Y-m-d');
        }

        return $out;
    }

    /** 暦にある YYYY-MM-DD か（2026-02-30 などは不可）。 */
    public static function isDate(string $s): bool
    {
        // NUL バイトを含むと createFromFormat が ValueError を投げ、500 になる
        if (str_contains($s, "\0")) {
            return false;
        }
        $d = \DateTimeImmutable::createFromFormat('!Y-m-d', $s);

        return $d !== false && $d->format('Y-m-d') === $s;
    }

    private function __construct() {}
}
