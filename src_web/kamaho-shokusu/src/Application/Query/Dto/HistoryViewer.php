<?php
declare(strict_types=1);

namespace App\Application\Query\Dto;

/**
 * 予約履歴を見る人・見られる人（権限の判定に使う）。退職・削除済みの人は作らない。
 */
final class HistoryViewer
{
    /**
     * @param int $admin i_admin
     * @param int|null $level i_user_level
     */
    public function __construct(
        public readonly int $id,
        public readonly int $admin,
        public readonly ?int $level,
        public readonly string $name,
    ) {}
}
