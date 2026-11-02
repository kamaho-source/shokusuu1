<?php
declare(strict_types=1);

namespace App\Application\UseCase\DeleteRoom;

/**
 * 部屋の削除の結果。
 */
final class DeleteRoomOutput
{
    /**
     * @param bool $deleted 削除できたか
     */
    public function __construct(
        public readonly bool $deleted,
    ) {}
}
