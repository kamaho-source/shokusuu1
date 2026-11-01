<?php
declare(strict_types=1);

namespace App\Application\UseCase\AssignUserRooms;

/**
 * 利用者の所属部屋を、部屋名で入れ替える依頼（外部の取込用 API）。
 */
final class AssignUserRoomsInput
{
    /**
     * @param list<string> $roomNames 部屋名（先頭の2つまで使う）
     */
    public function __construct(
        public readonly int $userId,
        public readonly array $roomNames,
        public readonly string $actorName,
    ) {}
}
