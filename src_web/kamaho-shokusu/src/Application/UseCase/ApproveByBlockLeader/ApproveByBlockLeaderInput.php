<?php
declare(strict_types=1);

namespace App\Application\UseCase\ApproveByBlockLeader;

use App\Application\Port\AuditActor;

/**
 * ブロック長の承認の Input DTO。承認者は $actor（id が承認者の利用者ID）。
 */
final class ApproveByBlockLeaderInput
{
    /**
     * @param array<array-key, array<array-key, int|string|null>|string|int|null> $keys 画面から受け取った対象のキー（形は UseCase で確かめる）
     * @param bool $approverIsAdmin 承認者が管理者か。管理者でなければ担当部屋の予約だけを対象にする。
     *                              省略できない（渡し忘れで担当外まで承認しないため。#989）
     */
    public function __construct(
        public readonly array $keys,
        public readonly AuditActor $actor,
        public readonly bool $approverIsAdmin,
    ) {}
}
