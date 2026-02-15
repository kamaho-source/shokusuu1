<?php
declare(strict_types=1);

namespace App\Service;

use Cake\Cache\Cache;
use Cake\I18n\Date;
use Cake\ORM\Table;

class DashboardService
{
    public function buildHomeContext($user): array
    {
        $today = new \DateTimeImmutable('now');
        $dow = ['日', '月', '火', '水', '木', '金', '土'];
        $todayLabel = $today->format('Y年n月j日') . '(' . $dow[(int)$today->format('w')] . ')';
        $todayParam = $today->format('Y-m-d');

        $thisWeekMonday = $today->modify('monday this week');
        $nextWeekMonday = $thisWeekMonday->modify('+7 days');
        $nextNextWeekMonday = $thisWeekMonday->modify('+14 days');
        $minNormalDate = $today->modify('+15 days');
        $firstNormalWeekMonday = $minNormalDate->modify('monday this week');
        $secondNormalWeekMonday = $firstNormalWeekMonday->modify('+7 days');
        $thirdNormalWeekMonday = $firstNormalWeekMonday->modify('+14 days');

        $fmtWeekRange = function (\DateTimeImmutable $monday) use ($dow): string {
            $fri = $monday->modify('+4 days');
            return $monday->format('n/j') . '(' . $dow[(int)$monday->format('w')] . ')' . ' 〜 ' .
                $fri->format('n/j') . '(' . $dow[(int)$fri->format('w')] . ')';
        };

        return [
            'todayLabel' => $todayLabel,
            'todayParam' => $todayParam,
            'thisWeekMonday' => $thisWeekMonday,
            'nextWeekMonday' => $nextWeekMonday,
            'nextNextWeekMonday' => $nextNextWeekMonday,
            'firstNormalWeekMonday' => $firstNormalWeekMonday,
            'secondNormalWeekMonday' => $secondNormalWeekMonday,
            'thirdNormalWeekMonday' => $thirdNormalWeekMonday,
            'fmtWeekRange' => $fmtWeekRange,
        ];
    }

    public function hasTodayReport(int $userId, Table $reservationTable): bool
    {
        $today = Date::today();
        $cacheKey = sprintf('today_report:%d:%s', $userId, $today->format('Y-m-d'));
        $cached = Cache::read($cacheKey, 'default');
        if ($cached !== false) {
            return (bool)$cached;
        }
        $row = $reservationTable
            ->find()
            ->enableAutoFields(false)
            ->select(['i_id_user'])
            ->where([
                'i_id_user' => $userId,
                'd_reservation_date' => $today,
            ])
            ->limit(1)
            ->first();
        $has = $row !== null;
        Cache::write($cacheKey, $has ? 1 : 0, 'default');
        return $has;
    }
}
