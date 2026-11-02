<?php
declare(strict_types=1);

namespace App\Application\UseCase\ChangePassword;

use App\Application\Port\AuditActor;

/**
 * パスワードの変更の入力。誰が変えてよいか（認可）は呼び出し側で確かめる。
 */
final class ChangePasswordInput
{
    /**
     * @param int|null $userId 変える相手（管理者が選んだ人、または本人）。選ばれていなければ null
     * @param bool $byAdmin 管理者が他の人のパスワードを変えるか（false なら本人が自分のを変える）
     */
    public function __construct(
        public readonly ?int $userId,
        public readonly string $newPassword,
        public readonly string $confirmPassword,
        public readonly AuditActor $actor,
        public readonly bool $byAdmin,
    ) {}
}
