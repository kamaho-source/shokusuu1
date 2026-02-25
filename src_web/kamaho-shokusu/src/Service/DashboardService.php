<?php
declare(strict_types=1);

namespace App\Service;

use Cake\Cache\Cache;
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
     * 指定ユーザーが「本日分の食数報告」を既に登録済みかどうかを判定する。
     *
     * 判定ロジック:
     *   1. キャッシュキー「today_report:{userId}:{YYYY-MM-DD}」を参照し、
     *      キャッシュヒットした場合はその値(1 or 0)を bool にキャストして返す。
     *   2. キャッシュミス時は TIndividualReservationInfo テーブルを検索し、
     *      同一ユーザー・同一日付のレコードが存在するか確認する。
     *   3. 結果をキャッシュに書き込んでから返す。
     *
     * キャッシュを使うことで、同一リクエスト内や短時間の再アクセス時に
     * 不要な DB クエリを発行しないようにしている。
     *
     * @param int   $userId           ログイン中ユーザーの i_id_user
     * @param Table $reservationTable TIndividualReservationInfo テーブルオブジェクト
     * @return bool true = 本日分の報告済み / false = 未報告
     */
    public function hasTodayReport(int $userId, Table $reservationTable): bool
    {
        // アジア/東京タイムゾーンで「今日」の Date オブジェクトを取得する
        $today = Date::today('Asia/Tokyo');

        // キャッシュキーはユーザーIDと日付の組み合わせで一意にする
        // 例: today_report:42:2026-02-21
        $cacheKey = sprintf('today_report:%d:%s', $userId, $today->format('Y-m-d'));

        // キャッシュを確認する。false は「キャッシュなし」を意味する(CakePHP の仕様)
        $cached = Cache::read($cacheKey, 'default');
        if ($cached !== false) {
            // キャッシュヒット: 保存値(1 or 0)を bool に変換して返す
            return (bool)$cached;
        }

        // キャッシュミス: DB から当該ユーザー・当日のレコードを1件だけ取得する
        // enableAutoFields(false) + select(['i_id_user']) で SELECT を最小限に絞る
        $row = $reservationTable
            ->find()
            ->enableAutoFields(false)
            ->select(['i_id_user'])
            ->where([
                'i_id_user'           => $userId,
                'd_reservation_date'  => $today,
            ])
            ->limit(1)
            ->first();

        // レコードが存在すれば「報告済み」
        $has = $row !== null;

        // 報告済みの場合のみキャッシュに書き込む。
        // 未報告(false)はキャッシュしない。キャッシュに0を書き込むと
        // TTLが切れるまで「未報告」状態が固定され、その後の予約操作で
        // キャッシュクリアが漏れた際にアラートが長時間表示されなくなる
        // リスクがあるため、未報告は常にDBを参照して最新状態を返す。
        if ($has) {
            Cache::write($cacheKey, 1, 'default');
        }

        return $has;
    }
}
