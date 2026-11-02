<?php
declare(strict_types=1);

namespace App\Application\Query;

/**
 * ダッシュボードの読み取り。
 */
interface DashboardQueryInterface
{
    /** 利用者の今の i_admin（ログイン後に権限が変わっても反映するため DB から読む）。見つからなければ null。 */
    public function adminLevel(int $userId): ?int;

    /**
     * その日の予約の行があるか（食数の報告済みか）。
     * 「食べる」「食べない」どちらの報告でも行ができるため、行の有無だけで判定する。
     */
    public function hasReservationOn(int $userId, string $date): bool;
}
