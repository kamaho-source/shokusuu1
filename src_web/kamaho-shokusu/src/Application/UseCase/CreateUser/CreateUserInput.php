<?php
declare(strict_types=1);

namespace App\Application\UseCase\CreateUser;

use App\Application\Port\AuditActor;

/**
 * 利用者を新しく登録する依頼。
 */
final class CreateUserInput
{
    /**
     * @param array<array-key, mixed> $data 送られた値（c_login_account・c_login_passwd・c_user_name・role・age・age_group・i_user_gender・staff_id・MUserGroup）
     * @param bool $childOnly 管理者以外（職員・ブロック長）の登録で、児童しか登録できないか
     * @param list<int> $allowedRoomIds 登録できる部屋（操作する人の所属部屋。管理者も同じ）
     */
    public function __construct(
        public readonly array $data,
        public readonly bool $childOnly,
        public readonly array $allowedRoomIds,
        public readonly AuditActor $actor,
    ) {}
}
