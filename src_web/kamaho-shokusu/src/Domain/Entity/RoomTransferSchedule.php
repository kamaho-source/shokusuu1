<?php
declare(strict_types=1);

namespace App\Domain\Entity;

/**
 * 部屋異動の予約（m_room_transfer_schedule）。
 */
final class RoomTransferSchedule
{
    /** 予約中 */
    public const STATUS_PENDING = 0;
    /** 適用済み */
    public const STATUS_APPLIED = 1;
    /** 取消済み */
    public const STATUS_CANCELLED = 2;

    /**
     * @param int|null $roomFromId 異動元の部屋（null は新しく配属）
     * @param string $effectiveDate 有効開始日（'Y-m-d'）
     */
    public function __construct(
        public readonly int $id,
        public readonly int $userId,
        public readonly ?int $roomFromId,
        public readonly int $roomToId,
        public readonly string $effectiveDate,
        public readonly int $status,
    ) {}

    /** 異動元と異動先が同じ部屋の異動を断るときの理由。 */
    public const SAME_ROOM_MESSAGE = '異動元と異動先が同じ部屋です。';

    /**
     * 異動元と異動先が同じ部屋か。同じ部屋の異動は行えない（適用すると、その部屋の予約を消してしまう。#945）。
     * 異動元なし（新しい配属）は同じ部屋ではない。
     */
    public static function isSameRoom(?int $roomFromId, int $roomToId): bool
    {
        return $roomFromId !== null && $roomFromId === $roomToId;
    }

    public function isSameRoomTransfer(): bool
    {
        return self::isSameRoom($this->roomFromId, $this->roomToId);
    }

    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }
}
