<?php
declare(strict_types=1);

namespace App\Application\UseCase\EditUser;

use App\Application\Port\AuditActor;

/**
 * 利用者の編集の入力。どの項目を変えてよいかは、呼び出し側が認可で確かめた結果を渡す。
 */
final class EditUserInput
{
    /**
     * @param array<array-key, mixed> $posted 送られた値
     * @param bool $canChangeRole 役職・職員IDを変えられるか（管理者が他のユーザーを編集するとき）
     * @param bool $canEditRooms ログインID・性別・所属部屋を変えられるか（管理者・本人）。false なら氏名のみ
     * @param bool $actorIsAdmin 操作者が管理者か（年代を変えられるのは管理者だけ）
     */
    public function __construct(
        public readonly int $userId,
        public readonly array $posted,
        public readonly AuditActor $actor,
        public readonly bool $canChangeRole,
        public readonly bool $canEditRooms,
        public readonly bool $actorIsAdmin,
    ) {}
}
