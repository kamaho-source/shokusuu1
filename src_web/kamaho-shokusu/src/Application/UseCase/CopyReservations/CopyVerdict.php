<?php
declare(strict_types=1);

namespace App\Application\UseCase\CopyReservations;

/**
 * 予約コピーで、コピー元の1行をどうするか（ReservationCopyPlanner::verdict の結果）。
 */
enum CopyVerdict
{
    /** 写す */
    case Copy;
    /** スキップする */
    case Skip;
    /** コピー先の日が無い・今日より前 */
    case InvalidDate;
}
