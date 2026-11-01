<?php
declare(strict_types=1);

namespace App\Application\UseCase\AssignUserRooms;

/**
 * 所属部屋の入れ替えの結果。
 */
final class AssignUserRoomsOutput
{
    /**
     * @param list<string> $errors 登録できなかった部屋の理由
     */
    public function __construct(
        public readonly int $created,
        public readonly array $errors,
    ) {}
}
