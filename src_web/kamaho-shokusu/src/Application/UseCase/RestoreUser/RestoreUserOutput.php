<?php
declare(strict_types=1);

namespace App\Application\UseCase\RestoreUser;

/**
 * 利用者の復元の結果。
 */
final class RestoreUserOutput
{
    /**
     * @param int $restoredRoomCount 所属中に戻した部屋の数（0 なら、削除前の所属が分からず所属なしで復元した）
     */
    public function __construct(
        public readonly ?string $userName,
        public readonly int $restoredRoomCount,
    ) {}
}
