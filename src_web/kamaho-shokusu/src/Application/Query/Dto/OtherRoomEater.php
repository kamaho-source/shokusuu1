<?php
declare(strict_types=1);

namespace App\Application\Query\Dto;

/**
 * 別の部屋で食べる人（部屋の詳細）。
 */
final class OtherRoomEater
{
    public function __construct(
        public readonly ?string $userName,
        public readonly string $roomName,
    ) {}
}
