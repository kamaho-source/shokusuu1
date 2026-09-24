<?php
declare(strict_types=1);

namespace App\Service;

use Cake\I18n\Date;

/**
 * ReservationDatePolicy
 *
 * 食数予約の日付ルールを一元管理するポリシークラス。
 *
 * ビジネスルール:
 *   - 通常予約: 今日から15日後以降の日付のみ登録可能。
 *   - 直前編集: 今日から14日以内の日付は「変更フラグ(i_change_flag)」列を使って
 *               登録・更新する。14日を超える日付は「eat_flag」列を使う。
 *
 * このクラスに日付計算ロジックを集約することで、Controller や View から
 * 重複したマジックナンバーを排除し、ルール変更時の修正箇所を一か所にまとめる。
 */
class ReservationDatePolicy
{
    /**
     * 直前編集ウィンドウの日数。
     * 今日からこの日数以内の予約日は「直前編集」扱いとなり、
     * i_change_flag 列を使って登録・更新する。
     */
    private const CHANGE_WINDOW_DAYS = 14;

    /**
     * 通常予約の最短リードタイム(日数)。
     * 今日からこの日数未満の日付は通常予約として受け付けない。
     */
    private const NORMAL_ORDER_MIN_DAYS = 15;

    /**
     * 通常予約の予約日バリデーションを行う。
     *
     * 以下のチェックを順番に実施する:
     *   1. $reservationDate が空でないか
     *   2. $reservationDate が有効な日付文字列か(Date オブジェクトに変換できるか)
     *   3. $reservationDate が minimumOrderDate() 以降の日付か
     *
     * すべてのチェックをパスした場合は true を返す。
     * チェックに引っかかった場合はエラーメッセージ文字列を返す。
     *
     * @param string|null $reservationDate 検証する日付文字列(例: 「2026-03-10」)
     * @return string|bool true = 有効 / string = エラーメッセージ
     */
    public function validateReservationDate(?string $reservationDate): string|bool
    {
        // 空チェック: null・空文字・空白のみ のいずれかの場合はエラー
        if (empty($reservationDate)) {
            return '予約日が指定されていません。';
        }

        // 日付形式チェック: 不正なフォーマットは Date コンストラクタが例外を投げる
        try {
            $reservationDateObj = new Date($reservationDate);
        } catch (\Exception $e) {
            return '無効な日付フォーマットです。';
        }

        // リードタイムチェック: 最小受付日より前の日付は通常予約不可
        $minDate = $this->minimumOrderDate();

        if ($reservationDateObj < $minDate) {
            return sprintf(
                '通常発注は「きょうから15日目以降」のみ登録できます（%s 以降）。',
                $minDate->i18nFormat('yyyy-MM-dd')
            );
        }

        return true;
    }

    /**
     * 直前編集ウィンドウの境界日を返す。
     *
     * 「今日 + CHANGE_WINDOW_DAYS(14日)」の Date オブジェクトを返す。
     * この日付以前の予約は「直前編集」扱いとなる。
     *
     * 例: 今日が 2026-02-21 の場合 → 2026-03-07 を返す
     *
     * @param Date|null   $today    基準日。null の場合は今日(Asia/Tokyo)を使う
     * @param string|null $timezone タイムゾーン文字列。null の場合は Asia/Tokyo を使う
     * @return Date 直前編集ウィンドウの最終日
     */
    public function changeBoundaryDate(?Date $today = null, ?string $timezone = null): Date
    {
        $today = $today ?? $this->today($timezone);

        return $today->addDays(self::CHANGE_WINDOW_DAYS);
    }

    /**
     * 通常予約が受け付け可能な最初の日付(最小受付日)を返す。
     *
     * 「今日 + NORMAL_ORDER_MIN_DAYS(15日)」の Date オブジェクトを返す。
     * この日付より前の日付は通常予約として登録できない。
     *
     * 例: 今日が 2026-02-21 の場合 → 2026-03-08 を返す
     *
     * @param Date|null   $today    基準日。null の場合は今日(Asia/Tokyo)を使う
     * @param string|null $timezone タイムゾーン文字列。null の場合は Asia/Tokyo を使う
     * @return Date 通常予約の最小受付日
     */
    public function minimumOrderDate(?Date $today = null, ?string $timezone = null): Date
    {
        $today = $today ?? $this->today($timezone);

        return $today->addDays(self::NORMAL_ORDER_MIN_DAYS);
    }

    /**
     * 指定した予約日が「直前編集フラグ(i_change_flag)」を使うべきかを判定する。
     *
     * 予約日が changeBoundaryDate() 以前であれば true を返す。
     * つまり「今日から14日以内の予約日」は直前編集扱いとなる。
     *
     * @param Date        $targetDate 判定対象の予約日
     * @param Date|null   $today      基準日。null の場合は今日(Asia/Tokyo)を使う
     * @param string|null $timezone   タイムゾーン文字列
     * @return bool true = 直前編集フラグを使う / false = 通常の eat_flag を使う
     */
    public function shouldUseChangeFlag(Date $targetDate, ?Date $today = null, ?string $timezone = null): bool
    {
        return $targetDate <= $this->changeBoundaryDate($today, $timezone);
    }

    /**
     * 指定した予約日が「直前編集ウィンドウ内」かどうかを判定する。
     *
     * 直前編集ウィンドウとは「今日以降〜今日+14日以内」の期間を指す。
     * 過去日は対象外であるため、今日以降 かつ 境界日以前 の両方を満たす必要がある。
     *
     * 使用例: 直前編集フォームへのアクセス可否チェックなど
     *
     * @param Date        $targetDate 判定対象の予約日
     * @param Date|null   $today      基準日。null の場合は今日(Asia/Tokyo)を使う
     * @param string|null $timezone   タイムゾーン文字列
     * @return bool true = 直前編集ウィンドウ内 / false = 対象外
     */
    public function isLastMinuteWindow(Date $targetDate, ?Date $today = null, ?string $timezone = null): bool
    {
        $today    = $today ?? $this->today($timezone);
        $boundary = $this->changeBoundaryDate($today, $timezone);

        // 今日以降 かつ 境界日(今日+14日)以前であれば直前編集ウィンドウ内
        return $targetDate >= $today && $targetDate <= $boundary;
    }

    /**
     * 予約1行が「その日の食数として有効か」を判定する唯一の基準。
     *
     * 判定規則:
     *   - 直前編集ウィンドウ内(今日+14日以内・過去日を含む):
     *       i_change_flag を使う。NULL の行は直前編集を受けていないため eat_flag にフォールバックする。
     *   - 通常予約範囲(今日+15日以降): eat_flag を使う。
     *
     * 集計・画面表示・エクスポートはすべてこのメソッドを通すこと。
     * 判定基準が分かれると「食数予定表の数」と「発注用集計の数」がずれる。
     *
     * @param int|null  $eatFlag    eat_flag の値
     * @param int|null  $changeFlag i_change_flag の値(未設定なら null)
     * @param Date      $targetDate 予約日
     * @param Date|null $today      基準日。null の場合は今日(Asia/Tokyo)を使う
     * @return bool true = その日の食数に計上する
     */
    public function isActiveReservation(
        ?int $eatFlag,
        ?int $changeFlag,
        Date $targetDate,
        ?Date $today = null
    ): bool {
        if ($this->shouldUseChangeFlag($targetDate, $today)) {
            return (int)($changeFlag ?? $eatFlag ?? 0) === 1;
        }

        return (int)($eatFlag ?? 0) === 1;
    }

    /**
     * 予約日が「直前編集ウィンドウ内」の場合に、書き換えてよい列名だけを返す。
     *
     * 直前編集ウィンドウ内では発注済みの eat_flag を保持し、i_change_flag だけを更新する。
     * 通常予約範囲では両方を同じ値にそろえ、日付がウィンドウへ入った後も判定が変わらないようにする。
     *
     * @param bool $on           1=有効化 / 0=無効化
     * @param bool $useChangeFlag shouldUseChangeFlag() の結果
     * @return array<string, int> updateAll()・patchEntity() に渡すフラグ列
     */
    public function flagUpdates(bool $on, bool $useChangeFlag): array
    {
        $value = $on ? 1 : 0;

        return $useChangeFlag
            ? ['i_change_flag' => $value]
            : ['eat_flag' => $value, 'i_change_flag' => $value];
    }

    /**
     * 指定した日付文字列が今日より前(過去日)かどうかを返す。
     *
     * @param string $date 'Y-m-d' 形式の日付文字列
     * @return bool true = 過去日 / false = 今日以降
     */
    public function isPastDate(string $date): bool
    {
        try {
            $target = new Date($date, 'Asia/Tokyo');
        } catch (\Throwable) {
            return false;
        }

        return $target < $this->today();
    }

    /**
     * 予約日に応じて使用すべきDB列名を返す。
     *
     * - 直前編集ウィンドウ内(今日〜+14日): 'i_change_flag' 列を使う
     * - 通常予約範囲(+15日以降):           'eat_flag' 列を使う
     *
     * INSERT/UPDATE 時に動的に列名を切り替えることで、
     * 短期の「直前変更」と長期の「通常予約」を同一テーブルで管理している。
     *
     * @param Date        $targetDate 予約日
     * @param Date|null   $today      基準日。null の場合は今日(Asia/Tokyo)を使う
     * @param string|null $timezone   タイムゾーン文字列
     * @return string 'i_change_flag' または 'eat_flag'
     */
    public function judgeColumn(Date $targetDate, ?Date $today = null, ?string $timezone = null): string
    {
        return $this->shouldUseChangeFlag($targetDate, $today, $timezone)
            ? 'i_change_flag'
            : 'eat_flag';
    }

    /**
     * 今日の Date オブジェクトを返す内部ヘルパー。
     *
     * 引数でタイムゾーンを受け取り、null の場合は 'Asia/Tokyo' を使う。
     * テスト時に $today を外部から注入できるようにするために、
     * 呼び出し元のメソッドが ?Date $today 引数を持つ設計になっている。
     *
     * @param string|null $timezone タイムゾーン文字列
     * @return Date 今日の Date オブジェクト
     */
    private function today(?string $timezone = null): Date
    {
        return Date::today($timezone ?? 'Asia/Tokyo');
    }
}
