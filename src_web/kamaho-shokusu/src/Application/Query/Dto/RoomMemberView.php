<?php
declare(strict_types=1);

namespace App\Application\Query\Dto;

/**
 * 部屋の画面（詳細・並び替え）に出す、所属メンバー1人分。
 */
final class RoomMemberView
{
    /**
     * @param int|null $level 役職（i_user_level）
     * @param int|null $age 年齢
     * @param int|null $displayNo 表示順（i_disp_no）
     */
    public function __construct(
        public readonly int $userId,
        public readonly ?string $name,
        public readonly ?int $level,
        public readonly ?int $age,
        public readonly ?int $displayNo,
    ) {}
}
