<?php
declare(strict_types=1);

namespace App\Application\Port;

/**
 * 監査ログに残す操作者。
 */
final class AuditActor
{
    /** 操作者のアカウントが見つからないとき（存在しないログインIDでのログイン失敗など）の名前 */
    public const UNKNOWN_ACCOUNT_NAME = '（該当アカウントなし）';

    public function __construct(
        public readonly string $name,
        public readonly int $id,
        public readonly string $loginId,
        public readonly string $ipAddress,
    ) {}
}
