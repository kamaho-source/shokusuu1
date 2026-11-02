<?php
declare(strict_types=1);

namespace App\Application\Query;

use App\Application\Query\Dto\DayMealCounts;
use App\Application\Query\Dto\MyReservationDay;
use App\Domain\ValueObject\MealType;
use Cake\Chronos\ChronosDate;

/**
 * 予約カレンダー（FullCalendar）のイベントを組み立てる。DB は読まない。
 */
final class CalendarEventBuilder
{
    /**
     * 予約済みの日（どれか1つでも食べる日）。昇順。
     *
     * @param array<string, MyReservationDay> $days
     * @return list<string>
     */
    public static function reservedDates(array $days): array
    {
        $dates = [];
        foreach ($days as $date => $day) {
            // 0（食べない）はカレンダー上 × 表示であり未予約と同等に扱う
            if ($day->hasAnyMeal()) {
                $dates[] = (string)$date;
            }
        }
        sort($dates);

        return $dates;
    }

    /**
     * 自分の予約（予約済み・未予約）と、日ごとの食数のイベント。
     *
     * @param array<string, DayMealCounts> $mealCounts
     * @param array<string, MyReservationDay> $myDays
     * @return list<array<string, mixed>>
     */
    public static function calendarEvents(array $mealCounts, array $myDays, ChronosDate $startDate, ChronosDate $endDate, bool $showPersonalStatus = true): array
    {
        $events = [];

        if ($showPersonalStatus) {
            $reservedDates = self::reservedDates($myDays);
            $icon = static fn(?int $v): string => $v ? '⚪︎' : '×';

            foreach ($reservedDates as $reservedDate) {
                $day = $myDays[$reservedDate] ?? new MyReservationDay();
                $events[] = [
                    'title'           => sprintf('朝:%s 昼:%s 夜:%s 弁:%s', $icon($day->breakfast), $icon($day->lunch), $icon($day->dinner), $icon($day->bento)),
                    'start'           => $reservedDate,
                    'allDay'          => true,
                    'backgroundColor' => '#28a745',
                    'borderColor'     => '#28a745',
                    'textColor'       => 'white',
                    'extendedProps'   => ['displayOrder' => -2],
                ];
            }

            for ($cursor = $startDate; $cursor->lessThan($endDate); $cursor = $cursor->addDays(1)) {
                $dateStr = $cursor->format('Y-m-d');
                if (!in_array($dateStr, $reservedDates, true)) {
                    $events[] = [
                        'title'           => '未予約',
                        'start'           => $dateStr,
                        'allDay'          => true,
                        'backgroundColor' => '#fd7e14',
                        'borderColor'     => '#fd7e14',
                        'textColor'       => 'white',
                        'extendedProps'   => ['displayOrder' => -10],
                    ];
                }
            }
        }

        foreach ($mealCounts as $date => $day) {
            foreach (MealType::labels(MealType::LABEL_TINY_NIGHT) as $type => $name) {
                if (!empty($day->counts[$type])) {
                    $events[] = [
                        'title'         => "{$name}: {$day->counts[$type]}人",
                        'start'         => (string)$date,
                        'allDay'        => true,
                        'extendedProps' => ['displayOrder' => (int)$type],
                    ];
                }
            }
        }

        return $events;
    }

    /**
     * 日ごとの合計食数のイベント。
     *
     * @param array<string, int> $totals 日付 => 人数
     * @return list<array{title: string, start: string, allDay: bool}>
     */
    public static function totalEvents(array $totals): array
    {
        $events = [];
        foreach ($totals as $date => $count) {
            $events[] = ['title' => '合計食数: ' . $count, 'start' => (string)$date, 'allDay' => true];
        }

        return $events;
    }
}
