<?php
declare(strict_types=1);

namespace App\Application\Query;

/**
 * 承認画面の読み取り（一覧・集計・件数・承認履歴）。
 *
 * 一覧に出すのは大人（職員、または i_user_level=7）の予約だけ。並びは日付・部屋・利用者の表示順・食事区分。
 * 一覧・履歴の行は画面がそのまま読む予約・承認ログの行（m_user_info・m_room_info の名前つき）。
 * 日付は YYYY-MM-DD。null は絞らない。
 */
interface ApprovalQueryInterface
{
    /**
     * ブロック長の一覧（担当部屋＝所属中で削除されていない部屋の予約だけ）。担当部屋が無ければ空。
     *
     * @param int|null $filterRoomId 担当部屋のうち1部屋に絞る。担当外の部屋は無視して担当部屋全体を返す
     * @param int|null $filterStatus 承認状態。null は未承認だけ
     * @return list<object>
     */
    public function getBlockLeaderList(
        int $userId,
        ?int $filterRoomId = null,
        ?string $dateFrom = null,
        ?string $dateTo = null,
        ?int $filterStatus = null,
    ): array;

    /**
     * 管理者の一覧（全部屋）。
     *
     * @param int|null $filterStatus 承認状態。null は全部
     * @return list<object>
     */
    public function getAdminList(
        ?int $filterRoomId = null,
        ?string $dateFrom = null,
        ?string $dateTo = null,
        ?int $filterStatus = null,
    ): array;

    /**
     * 管理者の集計（最終承認済みで「食べる」予約の数。日付・部屋ごと、日付・部屋名の順）。
     * 「食べる」は食数の報告・カレンダーと同じ基準（直前期間の変更も含む）。削除済みの利用者も数える。
     *
     * @return list<array{reservation_date: string, room_name: string, breakfast: int, lunch: int, dinner: int, bento: int}>
     */
    public function getAdminSummary(?string $dateFrom = null, ?string $dateTo = null): array;

    /** ブロック長の承認待ち（担当部屋の大人の未承認）の件数。 */
    public function countBlockLeaderPending(int $userId, ?string $dateFrom = null, ?string $dateTo = null): int;

    /** 管理者の最終承認待ち（大人のブロック長承認済）の件数。 */
    public function countAdminPending(?string $dateFrom = null, ?string $dateTo = null): int;

    /**
     * 承認履歴（新しい順・1ページ 50 件。ページ送りの情報つきで、画面のページ送りにそのまま渡せる）。
     * 管理者は全部。それ以外（ブロック長）は担当部屋の履歴と、自分が承認・差し戻しした履歴。
     *
     * @param array<string, mixed> $queryParams リクエストのクエリ（page・sort など）
     * @return iterable<object>
     * @throws \RuntimeException ページが範囲外のとき（404）
     */
    public function approvalLogs(int $viewerId, bool $isAdmin, array $queryParams): iterable;
}
