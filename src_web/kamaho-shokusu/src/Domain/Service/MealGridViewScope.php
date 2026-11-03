<?php
declare(strict_types=1);

namespace App\Domain\Service;

use App\Domain\ValueObject\UserRole;

/**
 * エクセル食数予約で、誰が何を見られ・どの部屋を編集できるか。
 *
 * - 表示モードは individual（個人）・room（部屋）・all（全部屋）。読めない値は individual
 * - 全員・全部屋を見られる（canViewAll）のは管理者・システム管理者と事務所ユーザー
 * - 部屋・全部屋モードを使えるのは canViewAll・ブロック長・職員。それ以外は個人モードだけ
 * - 部屋・全部屋モードで扱う部屋は所属部屋だけ。管理者だけは全部屋（#821）
 * - 個人モードで選べる利用者は canViewAll なら全員、それ以外は本人だけ
 * - 編集できる部屋は所属部屋。管理者は表示している全部屋（エクセル食数予約のみ）
 *
 * 所属部屋・事務所ユーザーかどうかは DB を見るため、呼び出し側で引いて渡す。
 */
final class MealGridViewScope
{
    public const MODE_INDIVIDUAL = 'individual';
    public const MODE_ROOM = 'room';
    public const MODE_ALL = 'all';

    private const MODES = [self::MODE_INDIVIDUAL, self::MODE_ROOM, self::MODE_ALL];

    private function __construct(
        public readonly bool $isAdmin,
        public readonly bool $canViewAll,
        public readonly bool $canViewRoom,
        public readonly bool $canUseAllMode,
    ) {}

    /**
     * ログインユーザーの権限から作る。
     *
     * @param int $iAdmin i_admin
     * @param int $userLevel i_user_level（不明は -1）
     * @param bool $isOfficeUser 事務所ユーザーか
     */
    public static function forUser(int $iAdmin, int $userLevel, bool $isOfficeUser): self
    {
        $isAdmin      = UserRole::isAdmin($iAdmin);
        $canViewAll   = $isAdmin || $isOfficeUser;
        $canViewRoom  = $canViewAll || UserRole::isBlockLeader($iAdmin) || UserRole::isStaffLevel($userLevel);

        // 全部屋モードを使える人は部屋モードを使える人と同じ
        return new self($isAdmin, $canViewAll, $canViewRoom, $canViewRoom);
    }

    /** 指定の表示モード（null・読めない値は個人）を、使えるモードに収めて返す。 */
    public function resolveMode(?string $requested): string
    {
        // 部屋モードを使えない人は全部屋モードも使えない（canUseAllMode と canViewRoom は同じ）
        if (!$this->canViewRoom || !in_array($requested, self::MODES, true)) {
            return self::MODE_INDIVIDUAL;
        }

        return $requested;
    }

    /**
     * 扱う部屋。部屋・全部屋モードは所属部屋だけに絞る（管理者は絞らない）。
     *
     * @param array<int, string> $rooms 部屋ID => 部屋名（権限で引いた候補）
     * @param list<int> $userRoomIds ログインユーザーの有効な所属部屋
     * @return array<int, string>
     */
    public function visibleRooms(array $rooms, array $userRoomIds, string $mode): array
    {
        if ($this->isAdmin || $mode === self::MODE_INDIVIDUAL) {
            return $rooms;
        }

        return array_intersect_key($rooms, array_flip($userRoomIds));
    }

    /**
     * 選んでいる部屋。指定（無ければ前回の部屋）が扱えなければ先頭の部屋。部屋が無ければ null。
     *
     * @param array<int, string> $rooms visibleRooms の結果
     */
    public function resolveRoomId(?int $requested, ?int $previous, array $rooms): ?int
    {
        $roomId = $requested ?? $previous;
        if ($roomId !== null && isset($rooms[$roomId])) {
            return $roomId;
        }

        return $rooms === [] ? null : (int)array_key_first($rooms);
    }

    /**
     * 個人モードで選んでいる利用者。canViewAll でなければ本人。選べない利用者なら本人。
     *
     * @param array<int, string> $selectableUsers 選べる利用者（canViewAll のときだけ渡す）
     */
    public function resolveUserId(?int $requested, ?int $previous, int $loginUserId, array $selectableUsers): int
    {
        if (!$this->canViewAll) {
            return $loginUserId;
        }
        $userId = $requested ?? $previous ?? $loginUserId;

        return isset($selectableUsers[$userId]) ? $userId : $loginUserId;
    }

    /**
     * 表に出す部屋。個人は選んだ利用者の所属部屋、部屋は選んだ部屋、全部屋は扱う部屋すべて。
     *
     * @param array<int, string> $rooms visibleRooms の結果
     * @param list<int> $selectedUserRoomIds 選んだ利用者の所属部屋（個人モードのときだけ使う）
     * @return array<int, string>
     */
    public function targetRooms(string $mode, array $rooms, ?int $selectedRoomId, array $selectedUserRoomIds): array
    {
        if ($mode === self::MODE_INDIVIDUAL) {
            return array_intersect_key($rooms, array_flip($selectedUserRoomIds));
        }
        if ($mode === self::MODE_ROOM) {
            return ($selectedRoomId !== null && isset($rooms[$selectedRoomId]))
                ? [$selectedRoomId => $rooms[$selectedRoomId]]
                : [];
        }

        return $rooms;
    }

    /**
     * 表の行を1人に絞るときの利用者ID。個人モードは権限にかかわらず選んだ利用者だけ（部屋内の子どもは部屋モードで扱う）。
     * 絞らないときは null。
     */
    public function rowUserId(string $mode, int $selectedUserId): ?int
    {
        return $mode === self::MODE_INDIVIDUAL ? $selectedUserId : null;
    }

    /**
     * 個人モードの利用者の選択肢。canViewAll は選べる利用者全員、それ以外は本人だけ。個人モード以外は空。
     *
     * @param array<int, string> $selectableUsers
     * @return array<int, string>
     */
    public function nameList(string $mode, array $selectableUsers, int $loginUserId, string $loginName): array
    {
        if ($mode !== self::MODE_INDIVIDUAL) {
            return [];
        }

        return $this->canViewAll ? $selectableUsers : [$loginUserId => $loginName];
    }

    /**
     * 編集できる部屋。管理者は扱う部屋すべて、それ以外は所属部屋。
     *
     * @param array<int, string> $rooms visibleRooms の結果
     * @param list<int> $userRoomIds ログインユーザーの有効な所属部屋
     * @return list<int>
     */
    public function editableRoomIds(array $rooms, array $userRoomIds): array
    {
        return $this->isAdmin ? array_keys($rooms) : $userRoomIds;
    }
}
