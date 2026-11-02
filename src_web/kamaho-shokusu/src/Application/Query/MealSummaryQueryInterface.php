<?php
declare(strict_types=1);

namespace App\Application\Query;

use App\Application\Query\Dto\StaffMealCount;

/**
 * 控除表のための、職員ごとの月の食数。
 */
interface MealSummaryQueryInterface
{
    /**
     * 職員（職員ID のある人）ごとの、その月の食数（食数として数える予約。承認機能が有効なら承認済みだけ）。
     * 削除（退職）済みの職員は、その月に食数がある人だけ入れる。
     *
     * @return list<StaffMealCount>
     */
    public function staffMealCounts(int $year, int $month): array;
}
