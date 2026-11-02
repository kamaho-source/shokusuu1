<?php
declare(strict_types=1);

namespace App\Domain\Entity;

/**
 * 部屋。
 */
final class Room
{
    /**
     * @param int|null $delFlag i_del_flg（1 なら削除済み）
     */
    public function __construct(
        public readonly int $id,
        public readonly ?string $name,
        public readonly ?int $delFlag,
    ) {}
}
