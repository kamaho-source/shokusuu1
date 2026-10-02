<?php
declare(strict_types=1);

namespace App\Service;

use Cake\Cache\Cache;

/**
 * 予約データの「版数」を管理するサービス。
 *
 * 予約が書き換わるたびに整数をひとつ進める。用途は2つ。
 *   1. 帳票・集計キャッシュのキーに混ぜて、古い結果を配信しないようにする
 *   2. 画面が「他の人が更新したか」を調べるための目印にする
 *
 * 2 のために毎秒のように参照されるため、DB は引かずキャッシュだけで完結させる。
 *
 * 注意: 予約を書き換えるサービスは必ず bump() を呼ぶこと。
 * 呼び忘れると、その経路で更新しても他の画面が気づけない。
 */
final class ReservationVersionService
{
    /** 版数を格納するキャッシュキー */
    private const CACHE_KEY = 'reservation_version';

    /** 版数が未設定のときの初期値 */
    private const INITIAL = 1;

    /**
     * 現在の版数を返す。
     *
     * キャッシュが消えている場合は初期値を返す。版数は「前回と違うか」だけを
     * 見るための値なので、消えて巻き戻っても画面が一度更新されるだけで済む。
     */
    public static function current(): int
    {
        $value = Cache::read(self::CACHE_KEY, 'default');

        return (is_int($value) && $value > 0) ? $value : self::INITIAL;
    }

    /**
     * 版数をひとつ進める。予約を書き換えた直後に呼ぶ。
     *
     * @return int 進めたあとの版数
     */
    public static function bump(): int
    {
        $next = self::current() + 1;
        Cache::write(self::CACHE_KEY, $next, 'default');

        return $next;
    }
}
