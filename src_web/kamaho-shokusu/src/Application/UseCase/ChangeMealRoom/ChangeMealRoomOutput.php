<?php
declare(strict_types=1);

namespace App\Application\UseCase\ChangeMealRoom;

/**
 * 予約済み食事の部屋変更の Output DTO。
 */
final class ChangeMealRoomOutput
{
    public function __construct(
        public readonly int $meal,
        public readonly int $fromRoomId,
        public readonly int $toRoomId,
    ) {}
}
