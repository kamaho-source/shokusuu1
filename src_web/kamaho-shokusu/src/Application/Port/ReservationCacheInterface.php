<?php
declare(strict_types=1);

namespace App\Application\Port;

/**
 * 予約が変わったときのキャッシュの破棄（実装は Infrastructure）。
 */
interface ReservationCacheInterface
{
    /**
     * 予約が変わった日付のキャッシュを捨てる（食数・当日の報告済み印・レポート）。
     *
     * @param list<int> $userIds 予約が変わった利用者
     */
    public function forgetDate(string $date, array $userIds): void;

    /**
     * 複数の日付のキャッシュを捨てる。
     *
     * @param array<string, list<int>> $userIdsByDate 日付 => 予約が変わった利用者
     * @param bool $bumpVersion true ならレポートのバージョンを最後に1回だけ上げる
     */
    public function forgetDates(array $userIdsByDate, bool $bumpVersion = true): void;

    /**
     * 部屋異動を適用したあとのキャッシュを捨てる（基準日の食数・その利用者の基準日の報告済み印を消し、レポートのバージョンを上げる）。
     * 基準日が今日でなくても報告済み印を消す（従来どおり）。
     */
    public function forgetAfterRoomTransfer(string $date, int $userId): void;
}
