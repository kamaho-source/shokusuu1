<?php
declare(strict_types=1);

namespace App\Application\UseCase\DeleteRoom;

use App\Application\Port\AuditActor;

/**
 * 部屋の削除の入力。誰が削除してよいか（認可）は呼び出し側で確かめる。
 */
final class DeleteRoomInput
{
    /**
     * @param bool $loggedIn ログインしているか（していなければ更新者を空にする）
     */
    public function __construct(
        public readonly int $roomId,
        public readonly AuditActor $actor,
        public readonly bool $loggedIn = true,
    ) {}
}
