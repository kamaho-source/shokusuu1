<?php
declare(strict_types=1);

namespace App\Application\UseCase\RejectApproval;

use App\Application\Port\AuditActor;

/**
 * 差し戻しの Input DTO。ブロック長の差し戻し（byBlockLeader）と管理者の差し戻し（byAdmin）で作り分ける。
 */
final class RejectApprovalInput
{
    /**
     * @param array<array-key, array<array-key, int|string|null>|string|int|null> $keys
     */
    private function __construct(
        public readonly array $keys,
        public readonly AuditActor $actor,
        public readonly ?string $reason,
        public readonly bool $byBlockLeader,
        public readonly bool $approverIsAdmin,
    ) {}

    /**
     * ブロック長の差し戻し。自分の予約は除く。承認者が管理者でなければ担当部屋の予約だけを対象にする（#989）。
     *
     * @param array<array-key, array<array-key, int|string|null>|string|int|null> $keys 画面から受け取った対象のキー
     */
    public static function byBlockLeader(array $keys, AuditActor $actor, ?string $reason, bool $approverIsAdmin): self
    {
        return new self($keys, $actor, $reason, true, $approverIsAdmin);
    }

    /**
     * 管理者の差し戻し。自分の予約も含め、全部屋を対象にする。
     *
     * @param array<array-key, array<array-key, int|string|null>|string|int|null> $keys 画面から受け取った対象のキー
     */
    public static function byAdmin(array $keys, AuditActor $actor, ?string $reason): self
    {
        return new self($keys, $actor, $reason, false, true);
    }
}
