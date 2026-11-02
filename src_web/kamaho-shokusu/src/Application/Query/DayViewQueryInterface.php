<?php
declare(strict_types=1);

namespace App\Application\Query;

use App\Application\Query\Dto\DayView;

/**
 * 日別の食数状況（ある日の、部屋の利用者ごとの食事）の読み取り。
 */
interface DayViewQueryInterface
{
    /**
     * @param int $userId ログインしている人（0 ならログインしていない。選べる部屋なし）
     * @param string|null $date 'Y-m-d'。空・':date' なら今日
     * @param int|null $requestedRoomId 見たい部屋。選べない部屋なら、選べる部屋の先頭
     */
    public function getDayView(int $userId, ?string $date, ?int $requestedRoomId): DayView;
}
