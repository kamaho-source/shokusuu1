<?php
declare(strict_types=1);

namespace App\Application\UseCase\BulkReservation;

use App\Application\Port\ReservationCacheInterface;
use App\Domain\Repository\ReservationRepositoryInterface;
use App\Domain\Repository\RoomMembershipRepositoryInterface;

/**
 * 一括登録・直前一括編集で共通の判定（対象者を扱えるか・他の人の更新との競合・キャッシュの破棄）。
 */
final class BulkReservationSupport
{
    public function __construct(
        private readonly ReservationRepositoryInterface $reservations,
        private readonly RoomMembershipRepositoryInterface $rooms,
        private readonly ReservationCacheInterface $cache,
    ) {}

    /**
     * ログインユーザーが対象ユーザーの予約を変更できるかを返す。
     *
     * 管理者・（自分自身 かつ 所属部屋）・（職員→子供）・対象部屋のブロック長のいずれかであれば変更可。
     * 自分自身宛てであっても、所属していない部屋の食数は書き換えられない。
     *
     * @param int      $targetUserId      変更対象ユーザーID
     * @param int      $loginUserId       ログインユーザーID
     * @param bool     $isAdmin           ログインユーザーが管理者かどうか
     * @param bool     $isLoginStaff      ログインユーザーが職員（i_user_level 0 or 7）かどうか
     * @param int      $targetUserLevel   対象ユーザーの i_user_level
     * @param bool     $blockLeaderInRoom 対象部屋に所属するブロック長かどうか
     * @param int|null $roomId            操作対象の部屋ID（null の場合は部屋検証を行わない）
     * @param bool     $isCancel          取消（チェックOFF）か
     * @throws \App\Domain\Exception\PersistenceException 所属の読み取りに失敗したとき
     */
    public function canEditTargetUser(
        int $targetUserId,
        int $loginUserId,
        bool $isAdmin,
        bool $isLoginStaff,
        int $targetUserLevel,
        bool $blockLeaderInRoom,
        ?int $roomId = null,
        bool $isCancel = false
    ): bool {
        if ($targetUserId === $loginUserId) {
            // 自分自身でも、有効に所属していない部屋への登録は操作させない。
            // 取消はここでは所属を要求しないが、グループ一括登録の入口と Policy（canAccessRoom）で
            // 操作者が有効所属していない部屋は先に拒否されるため、退出済みの部屋の自分の予約は取り消せない（#821 で確認済みの仕様）。
            return $isCancel || $roomId === null || $this->rooms->isActiveMember($loginUserId, $roomId);
        }

        if ($roomId !== null) {
            // 他人の予約を扱うには、操作者本人が部屋に有効所属していること
            if (!$this->rooms->isActiveMember($loginUserId, $roomId)) {
                return false;
            }
            // 登録は対象も有効所属していること。取消は、対象が退出済みでも行える。
            if (!$isCancel && !$this->rooms->isActiveMember($targetUserId, $roomId)) {
                return false;
            }
        }

        // 管理者は所属部屋内なら対象の区分を問わず扱える（所属外の部屋は上の所属判定で弾く。#821）
        return $isAdmin || ($isLoginStaff && $targetUserLevel === 1) || $blockLeaderInRoom;
    }

    /**
     * 画面を開いたあとに、他の人が同じ部屋・日付の予約を更新していれば、その日付を返す。
     *
     * @param array<array-key, mixed>  $dayUsers  日付 => 利用者ごとの予約
     * @param array<array-key, mixed>  $snapshots 日付 => 画面を開いたときの最終更新日時
     * @throws \RuntimeException 読み取りに失敗したとき
     */
    public function conflictingSnapshotDate(int $roomId, array $dayUsers, array $snapshots): ?string
    {
        if (!$roomId) {
            return null;
        }
        $dates = array_keys($dayUsers);
        if (empty($dates)) {
            return null;
        }

        $serverMap = $this->reservations->latestUpdateByDate($roomId, $dates);
        foreach ($dates as $date) {
            $clientSnap = $snapshots[$date] ?? null;
            if ($clientSnap === null || $clientSnap === '') {
                continue;
            }
            $serverSnap = $serverMap[$date] ?? null;
            if ($serverSnap !== null && $serverSnap > $clientSnap) {
                return (string)$date;
            }
        }

        return null;
    }

    /**
     * 予約が変わった日付のキャッシュを捨て、レポートのバージョンを1回上げる。
     *
     * @param array<array-key, mixed> $dates
     * @param array<array-key, mixed> $dayUsers 日付 => [利用者ID => …]
     */
    public function forgetCachesForDates(int $roomId, array $dates, array $dayUsers = []): void
    {
        if (!$roomId || empty($dates)) {
            return;
        }
        $userIdsByDate = [];
        foreach ($dates as $date) {
            if (!is_string($date) || $date === '') {
                continue;
            }
            $userIdsByDate[$date] = isset($dayUsers[$date]) && is_array($dayUsers[$date]) ? array_keys($dayUsers[$date]) : [];
        }
        $this->cache->forgetDates($userIdsByDate);
    }
}
