<?php
declare(strict_types=1);

namespace App\Application\UseCase\BulkAddReservations;

/**
 * 一括登録（個人: 複数日 × 食事 × 部屋 / 部屋単位: 部屋 × 複数日 × 利用者 × 食事）の Input DTO。
 */
final class BulkAddReservationsInput
{
    /**
     * @param array<array-key, mixed> $data 画面の送信内容（reservation_type: personal|group と、その形に応じた dates・meals・i_id_room・day_users・users・reservation_snapshot）
     */
    public function __construct(
        public readonly array $data,
        public readonly int $userId,
        public readonly string $userName,
        public readonly bool $isAdmin = false,
        public readonly int $loginUserLevel = 0,
        public readonly bool $isBlockLeader = false,
    ) {}
}
