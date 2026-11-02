<?php
declare(strict_types=1);

namespace App\Application\UseCase\BulkToggleMeals;

/**
 * 予約トグルの一括処理の入力。
 */
final class BulkToggleMealsInput
{
    /**
     * @param array<int, array{roomId?:int,i_id_room?:int,userId?:int,date?:string,meal?:int,value?:int}> $items
     */
    public function __construct(
        public readonly array $items,
        public readonly int $loginUserId,
        public readonly string $loginUserName,
    ) {}
}
