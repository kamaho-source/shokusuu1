<?php
declare(strict_types=1);

namespace App\Application\UseCase\BulkImportUsers;

use App\Application\Port\AuditActor;

/**
 * 利用者の一括登録の入力。誰が登録してよいか（認可）は呼び出し側で確かめる。
 */
final class BulkImportUsersInput
{
    /**
     * @param array<array-key, mixed> $records クライアントで読み取った行（login_id・name・role・password・staff_id・age・gender・age_group・room_name1・room_name2・_row）
     * @param bool $actorIsAdmin 管理者か。管理者以外は、児童だけを、自分が所属している部屋にだけ登録できる
     */
    public function __construct(
        public readonly array $records,
        public readonly AuditActor $actor,
        public readonly bool $actorIsAdmin,
    ) {}
}
