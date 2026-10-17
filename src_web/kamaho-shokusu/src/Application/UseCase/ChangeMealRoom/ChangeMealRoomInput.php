<?php
declare(strict_types=1);

namespace App\Application\UseCase\ChangeMealRoom;

/**
 * 予約済み食事の部屋変更の Input DTO。
 *
 * @throws \InvalidArgumentException 食事区分が1-4以外、部屋IDが不正、変更前後が同じ部屋の場合
 */
final class ChangeMealRoomInput
{
    public function __construct(
        public readonly string $date,
        public readonly int    $meal,
        public readonly int    $fromRoomId,
        public readonly int    $toRoomId,
        public readonly int    $loginUserId,
        public readonly string $loginUserName,
        public readonly int    $targetUserId,
    ) {
        if (!in_array($meal, [1, 2, 3, 4], true)) {
            throw new \InvalidArgumentException("Invalid meal index: {$meal}. Must be 1, 2, 3, or 4.");
        }
        if ($fromRoomId <= 0 || $toRoomId <= 0) {
            throw new \InvalidArgumentException('fromRoomId and toRoomId are required.');
        }
        if ($fromRoomId === $toRoomId) {
            throw new \InvalidArgumentException('変更前と変更後の部屋が同じです。');
        }
    }
}
