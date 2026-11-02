<?php
declare(strict_types=1);

namespace App\Application\Query\Dto;

/**
 * 一括登録の画面に出す部屋の利用者1人。
 */
final class BulkRoomUser
{
    /**
     * @param int $level 役職（i_user_level。未設定は 0）
     * @param bool $isStaff 職員IDを持っているか
     */
    public function __construct(
        public readonly int $userId,
        public readonly string $name,
        public readonly int $level,
        public readonly bool $isStaff,
    ) {}

    /**
     * @return array{id: int, name: string, i_user_level: int, is_staff: bool}
     */
    public function toArray(): array
    {
        return ['id' => $this->userId, 'name' => $this->name, 'i_user_level' => $this->level, 'is_staff' => $this->isStaff];
    }
}
