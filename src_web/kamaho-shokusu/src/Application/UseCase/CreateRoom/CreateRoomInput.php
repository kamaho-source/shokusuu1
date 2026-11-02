<?php
declare(strict_types=1);

namespace App\Application\UseCase\CreateRoom;

use App\Application\Port\AuditActor;

/**
 * 部屋の作成の入力。誰が作ってよいか（認可）は呼び出し側で確かめる。
 */
final class CreateRoomInput
{
    /**
     * @param array<array-key, mixed> $input 送られた値（使うのは c_room_name だけ）
     * @param bool $loggedIn ログインしているか（していなければ作成者を空にする）
     */
    public function __construct(
        public readonly array $input,
        public readonly AuditActor $actor,
        public readonly bool $loggedIn = true,
    ) {}
}
