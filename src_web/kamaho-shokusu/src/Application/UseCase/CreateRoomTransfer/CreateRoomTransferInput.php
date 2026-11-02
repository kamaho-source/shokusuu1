<?php
declare(strict_types=1);

namespace App\Application\UseCase\CreateRoomTransfer;

/**
 * 部屋異動の予約の登録の入力。誰が登録してよいか（認可）は呼び出し側で確かめる。
 */
final class CreateRoomTransferInput
{
    /**
     * @param int|null $roomFromId 異動元（null は新しく配属）
     * @param string $effectiveDate 有効開始日（送られたまま）
     */
    public function __construct(
        public readonly ?int $userId,
        public readonly ?int $roomFromId,
        public readonly ?int $roomToId,
        public readonly string $effectiveDate,
        public readonly string $actorName,
    ) {}
}
