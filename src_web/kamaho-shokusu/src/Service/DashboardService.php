<?php
declare(strict_types=1);

namespace App\Service;

use Cake\I18n\Date;
use Cake\ORM\Table;

/**
 * DashboardService
 *
 * ダッシュボード画面に必要なビュー用データの生成と、
 * 当日の食数報告済みフラグの判定を担うサービスクラス。
 *
 * 責務:
 *   - 「今週・来週・再来週」および「通常予約対象の最初の週」など、
 *     画面に表示する各週の月曜日 (\DateTimeImmutable) を計算して返す。
 *   - ログイン済みユーザーが当日分の予約(食数報告)を登録済みかどうかを
 *     DB + キャッシュで判定する。
 */
class DashboardService
{
    /**
     * ダッシュボード画面のビュー変数を組み立てて返す。
     *
     * 返却する配列のキーと内容:
     *   - todayLabel          : 「2026年2月21日(土)」形式の今日の日付文字列
     *   - todayParam          : 「2026-02-21」形式の今日の日付文字列(URLパラメータ用)
     *   - thisWeekMonday      : 今週の月曜日 (\DateTimeImmutable)
     *   - nextWeekMonday      : 来週の月曜日 (\DateTimeImmutable)
     *   - nextNextWeekMonday  : 再来週の月曜日 (\DateTimeImmutable)
     *   - firstNormalWeekMonday  : 通常予約が可能な最初の週の月曜日
     *                             (今日+15日以降で、かつ次の月曜日以降)
     *   - secondNormalWeekMonday : firstNormalWeekMonday の翌週月曜日
     *   - thirdNormalWeekMonday  : firstNormalWeekMonday の2週後月曜日
     *   - fmtWeekRange        : 月曜〜金曜の期間文字列を返すクロージャ
     *                           例: 「2/23(月) 〜 2/27(金)」
     *
     * @param mixed $user 認証済みユーザーオブジェクト(未ログイン時は null)
     * @return array<string, mixed>
     */
    public function buildHomeContext($user): array
    {
        // 現在日時をアジア/東京タイムゾーンで取得する
        $today = new \DateTimeImmutable('now', new \DateTimeZone('Asia/Tokyo'));

        // 曜日の日本語ラベル (format('w') は 0=日 〜 6=土 を返す)
        $dow = ['日', '月', '火', '水', '木', '金', '土'];

        // 画面ヘッダーに表示する「今日の日付」文字列を生成する
        // 例: 「2026年2月21日(土)」
        $todayLabel = $today->format('Y年n月j日') . '(' . $dow[(int)$today->format('w')] . ')';

        // URLパラメータやフォームの hidden 値として使う「YYYY-MM-DD」形式の今日の日付
        $todayParam = $today->format('Y-m-d');

        // 今週の月曜日を取得する。
        // PHP の modify('monday this week') は ISO 週(月〜日)の月曜日を返す。
        // 今日が月曜日の場合は今日自身が返る。
        $thisWeekMonday = $today->modify('monday this week');

        // 来週・再来週の月曜日は今週月曜日に 7日・14日加算して求める
        $nextWeekMonday     = $thisWeekMonday->modify('+7 days');
        $nextNextWeekMonday = $thisWeekMonday->modify('+14 days');

        // 通常予約の最小受付日: 「今日から15日後」以降でなければ登録不可
        // (ReservationDatePolicy::NORMAL_ORDER_MIN_DAYS = 15 に対応)
        $minNormalDate = $today->modify('+15 days');

        // $minNormalDate が月曜日ならそのまま使い、それ以外なら「次の月曜日」に繰り上げる。
        // modify('monday this week') を使うと、$minNormalDate が日曜〜土曜の場合に
        // 同週の月曜日(= $minNormalDate より前の日付)が返ってしまうため、
        // 常に $minNormalDate 以降の月曜日を取得するためにこの分岐が必要。
        $firstNormalWeekMonday = (int)$minNormalDate->format('N') === 1
            ? $minNormalDate                       // 月曜日(N=1)ならそのまま
            : $minNormalDate->modify('next monday'); // それ以外は翌月曜日に繰り上げ

        // 通常予約の2週目・3週目の月曜日を算出する
        $secondNormalWeekMonday = $firstNormalWeekMonday->modify('+7 days');
        $thirdNormalWeekMonday  = $firstNormalWeekMonday->modify('+14 days');

        // 週の月曜〜金曜を「n/j(曜) 〜 n/j(曜)」形式の文字列に変換するクロージャ。
        // 引数の $monday から 4日後(金曜日)を算出し、範囲文字列を返す。
        // 例: 2/23(月) 〜 2/27(金)
        $fmtWeekRange = function (\DateTimeImmutable $monday) use ($dow): string {
            $fri = $monday->modify('+4 days');
            return $monday->format('n/j') . '(' . $dow[(int)$monday->format('w')] . ')' . ' 〜 ' .
                $fri->format('n/j') . '(' . $dow[(int)$fri->format('w')] . ')';
        };

        return [
            'todayLabel'             => $todayLabel,
            'todayParam'             => $todayParam,
            'thisWeekMonday'         => $thisWeekMonday,
            'nextWeekMonday'         => $nextWeekMonday,
            'nextNextWeekMonday'     => $nextNextWeekMonday,
            'firstNormalWeekMonday'  => $firstNormalWeekMonday,
            'secondNormalWeekMonday' => $secondNormalWeekMonday,
            'thirdNormalWeekMonday'  => $thirdNormalWeekMonday,
            'fmtWeekRange'           => $fmtWeekRange,
        ];
    }

    /**
     * 指定ユーザーが本日の予約レコードを持つかを判定する。
     *
     * 「食べる」「食べない」どちらの申告でも TIndividualReservationInfo に
     * レコードが作成されるため、レコードの有無だけで判定する。
     * レコードが存在する = 申告済み(アラート非表示)。
     * レコードが存在しない = 未申告(アラート表示)。
     *
     * @param int   $userId           ログイン中ユーザーの i_id_user
     * @param Table $reservationTable TIndividualReservationInfo テーブルオブジェクト
     * @return bool true = 本日の予約レコードあり / false = 本日の予約レコードなし
     */
    public function hasTodayReport(int $userId, Table $reservationTable): bool
    {
        $today = Date::today('Asia/Tokyo');

        return $reservationTable->exists([
            'i_id_user'          => $userId,
            'd_reservation_date' => $today,
        ]);
    }
}
