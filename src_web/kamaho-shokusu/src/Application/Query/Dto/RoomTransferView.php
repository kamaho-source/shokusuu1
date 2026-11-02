<?php
declare(strict_types=1);

namespace App\Application\Query\Dto;

/**
 * 部屋異動の予約1件（一覧に出す形）。
 */
final class RoomTransferView
{
    /**
     * @param string $effectiveDate 異動日（Y-m-d）
     * @param int $status 0=予約中・1=適用済み・2=取消
     * @param string|null $createdAt 登録日時（Y-m-d H:i:s）
     */
    public function __construct(
        public readonly int $id,
        public readonly ?string $userName,
        public readonly ?string $roomFromName,
        public readonly ?string $roomToName,
        public readonly string $effectiveDate,
        public readonly int $status,
        public readonly ?string $createdBy,
        public readonly ?string $createdAt,
    ) {}
}
