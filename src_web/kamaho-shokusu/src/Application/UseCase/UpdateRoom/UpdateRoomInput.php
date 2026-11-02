<?php
declare(strict_types=1);

namespace App\Application\UseCase\UpdateRoom;

use App\Application\Port\AuditActor;

/**
 * 部屋の編集（部屋名・所属メンバーの並び順）の入力。どちらを変えてよいかは、呼び出し側が認可で確かめた結果を渡す。
 */
final class UpdateRoomInput
{
    /**
     * @param array<array-key, mixed> $input 送られた値（部屋名に使うのは c_room_name だけ）
     * @param list<int>|null $memberOrder 並べた順の利用者ID（部屋の所属メンバー全員）。並び替えないなら null
     * @param bool $canRename 部屋名を変えられるか（管理者）
     * @param bool $canReorder 並び順を変えられるか（管理者・所属部屋のブロック長。削除済みの部屋は不可）
     */
    public function __construct(
        public readonly int $roomId,
        public readonly array $input,
        public readonly ?array $memberOrder,
        public readonly AuditActor $actor,
        public readonly bool $canRename,
        public readonly bool $canReorder,
    ) {}
}
