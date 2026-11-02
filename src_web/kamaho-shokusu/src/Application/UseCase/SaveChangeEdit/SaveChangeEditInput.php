<?php
declare(strict_types=1);

namespace App\Application\UseCase\SaveChangeEdit;

/**
 * 直前編集（1部屋 × 1日）の保存の Input DTO。
 */
final class SaveChangeEditInput
{
    /**
     * @param array<int|string, array<int|string, array{i_change_flag?: int|string|null}>> $usersData 利用者ID => 食事 => ['i_change_flag' => 0|1|2]（2 は取消）
     * @param list<int> $memberIds 部屋の利用者（これ以外の利用者は飛ばす）
     */
    public function __construct(
        public readonly array $usersData,
        public readonly array $memberIds,
        public readonly string $date,
        public readonly int $roomId,
        public readonly int $loginUserId,
        public readonly string $loginUserName,
        public readonly int $loginAdmin,
        public readonly int $loginLevel,
    ) {}
}
