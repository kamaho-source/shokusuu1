<?php
declare(strict_types=1);

namespace App\Application\UseCase\BulkChangeEditReservations;

/**
 * 直前一括編集（部屋 × 日付 × 利用者 × 食事）の Input DTO。
 */
final class BulkChangeEditReservationsInput
{
    /**
     * @param array<array-key, mixed> $dayUsers  日付 => [利用者ID => [食事区分 => 0/1]]（画面は変わっていない行も送る）
     * @param array<array-key, mixed> $snapshots 日付 => 画面を開いたときの最終更新日時
     */
    public function __construct(
        public readonly array $dayUsers,
        public readonly int $roomId,
        public readonly string $loginName,
        public readonly array $snapshots = [],
        public readonly int $loginUserId = 0,
        public readonly bool $isAdmin = false,
        public readonly int $loginUserLevel = 0,
        public readonly bool $isBlockLeader = false,
    ) {}
}
