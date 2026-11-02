<?php
declare(strict_types=1);

namespace App\Application\Query;

use App\Application\Query\Dto\HistoryLogEntry;
use App\Application\Query\Dto\HistoryViewer;

/**
 * 予約履歴の画面の読み取り（予約状況・部屋集計・変更の経緯）。何も書き込まない。
 *
 * 実食機能はオフのため「食べた」ではなく「予約済み」かどうかを出す。判定は ReservationDateRule::countedValue
 * （食数の報告・カレンダーと同じ基準）。過去日は直前編集の i_change_flag（NULL なら eat_flag）で決まる。
 */
interface ReservationHistoryQueryInterface
{
    /** 利用者1人（権限の判定用）。退職・削除済みは null。 */
    public function findViewer(int $userId): ?HistoryViewer;

    /**
     * 見る人が対象者として選べる人（id => 名前）。先頭は本人。
     *   - 管理者・システム管理者 … 全員
     *   - ブロック長 … 自分が所属している部屋の大人と子ども
     *   - 職員 … 自分が所属している部屋の子ども（i_user_level=1）
     * 所属は有効な所属だけ。退職・削除済みの人は出さない。
     *
     * @return array<int, string>
     */
    public function selectableTargets(HistoryViewer $viewer): array;

    /**
     * 日×食事の予約済み。予約済みの食事だけを返す（予約済みが1つも無い日は含めない）。部屋をまたいで同じ食事の行があれば、どれかが予約済みなら予約済み。
     *
     * @return array<string, array<int, true>> 日付 => 食事 => true（日付順）
     */
    public function bookedMeals(int $userId, string $from, string $to): array;

    /**
     * 部屋別・日別の予約済み人数。個人は出さない。予約済みが1人もいない日は含めない。
     *
     * @return array<string, array<int, int>> 日付 => 食事 => 人数（日付順）
     */
    public function roomSummary(int $roomId, string $from, string $to): array;

    /**
     * 変更の経緯（新しい順）。監査ログのうち、対象者の予約の操作（reservation_toggle）だけ。実食の操作は含めない。
     * 対象者は detail.user_id。無い古い記録は、操作者＝対象者の本人操作とみなす。代理の予約登録などは対象者が記録されていないため含まれない。
     * 操作者の名前は $withActorName が真のときだけ含める（IP・失敗理由は含めない）。
     *
     * @return list<HistoryLogEntry>
     */
    public function changeLog(int $userId, string $from, string $to, bool $withActorName): array;

    /** 変更の経緯の記録が始まった日（監査ログで最も古い日）。記録が1件も無ければ null。 */
    public function logStartDate(): ?string;

    /**
     * 部屋集計を見られる部屋（id => 名前）。事務所ユーザーは有効な全部屋、それ以外は有効な所属部屋（#821 と同じ）。
     *
     * @return array<int, string>
     */
    public function summaryRooms(int $userId): array;
}
