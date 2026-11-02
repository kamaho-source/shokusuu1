<?php
declare(strict_types=1);

namespace App\Application\Query\Dto;

use App\Domain\ValueObject\UserRole;

/**
 * 直前編集の画面に出す、部屋の利用者1人。
 */
final class ChangeEditMember
{
    /**
     * @param int $level i_user_level（NULL は 0）
     * @param bool $allowEdit 見る人がこの人の予約を変えられるか
     */
    public function __construct(
        public readonly int $id,
        public readonly string $name,
        public readonly int $level,
        public readonly bool $allowEdit,
    ) {}

    /**
     * @return array{id: int, name: string, i_user_level: int, userLevel: int, isStaff: bool, allowEdit: bool} 画面（JSON）の形
     */
    public function toJson(): array
    {
        return [
            'id'           => $this->id,
            'name'         => $this->name,
            'i_user_level' => $this->level,
            'userLevel'    => $this->level,
            'isStaff'      => UserRole::isStaffLevel($this->level),
            'allowEdit'    => $this->allowEdit,
        ];
    }

    /**
     * @return array{id: int, name: string, i_user_level: int} テンプレートの形
     */
    public function toArray(): array
    {
        return ['id' => $this->id, 'name' => $this->name, 'i_user_level' => $this->level];
    }
}
