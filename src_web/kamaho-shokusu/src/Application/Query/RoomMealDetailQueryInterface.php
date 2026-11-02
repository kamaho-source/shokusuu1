<?php
declare(strict_types=1);

namespace App\Application\Query;

use App\Application\Query\Dto\RoomMealDetail;
use App\Domain\Exception\NotFoundException;

/**
 * 部屋の詳細（ある日・ある食事で、部屋のだれが食べる／食べないか）の読み取り。
 */
interface RoomMealDetailQueryInterface
{
    /**
     * @param string $date 'Y-m-d'
     * @throws \InvalidArgumentException 日付の形式が正しくない場合
     * @throws NotFoundException 部屋が無い場合
     */
    public function getRoomMealDetail(int $roomId, string $date, int $mealType): RoomMealDetail;
}
