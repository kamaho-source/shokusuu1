<?php
declare(strict_types=1);

namespace App\Infrastructure\Query;

use App\Application\Query\Dto\DailyChildCount;
use App\Application\Query\Dto\LoginReport;
use App\Application\Query\Dto\ReportUserOption;
use App\Application\Query\Dto\RoomUsageStat;
use App\Application\Query\SystemReportQueryInterface;
use App\Domain\Service\DatePeriodRule;
use App\Domain\Service\MealUsageRate;
use App\Domain\Service\ReservationDateRule;
use App\Infrastructure\Persistence\Table\MUserGroupTable;
use App\Service\AuditLogService;
use Cake\Chronos\ChronosDate;
use Cake\ORM\Table;
use Cake\ORM\TableRegistry;
use Psr\Clock\ClockInterface;

/**
 * SystemReportQueryInterface の CakePHP 実装（m_user_group・m_user_info・m_room_info・t_individual_reservation_info・t_audit_log の読み取り）。
 */
final class CakeSystemReportQuery implements SystemReportQueryInterface
{
    private const CHILD_LEVEL = 1;

    public function __construct(private readonly ClockInterface $clock)
    {
    }

    private static function table(string $alias): Table
    {
        return TableRegistry::getTableLocator()->get($alias);
    }

    public function roomStats(array $excludeUserIds, string $dateFrom, string $dateTo): array
    {
        $memberships = self::table('MUserGroup')->find()
            ->contain(['MUserInfo', 'MRoomInfo'])
            ->where([
                ...MUserGroupTable::activeCondition(),
                'MUserInfo.i_del_flag' => 0,
                'MRoomInfo.i_del_flg'  => 0,
            ])
            ->all();

        $rooms = [];     // [roomId] = ['name' => …, 'users' => ['child' => n, 'adult' => n], 'reservations' => 同じ形]
        $groupIn = [];   // [roomId][userId] = 'child' | 'adult'
        foreach ($memberships as $row) {
            $roomId   = (int)$row->i_id_room;
            $userId   = (int)$row->i_id_user;
            $roomName = (string)($row->m_room_info?->c_room_name ?? '');
            if ($roomId <= 0 || $userId <= 0 || $roomName === '' || in_array($userId, $excludeUserIds, true)) {
                continue;
            }
            $group = (int)($row->m_user_info?->i_user_level ?? 0) === self::CHILD_LEVEL ? 'child' : 'adult';
            $rooms[$roomId] ??= ['name' => $roomName, 'users' => ['child' => 0, 'adult' => 0], 'reservations' => ['child' => 0, 'adult' => 0]];
            $rooms[$roomId]['users'][$group]++;
            $groupIn[$roomId][$userId] = $group;
        }
        if ($rooms === []) {
            return [];
        }

        foreach ($this->countedReservations($dateFrom, $dateTo) as $row) {
            $group = $groupIn[(int)$row->i_id_room][(int)$row->i_id_user] ?? null;
            if ($group !== null) {
                $rooms[(int)$row->i_id_room]['reservations'][$group]++;
            }
        }

        $days = max(1, (int)(new \DateTimeImmutable($dateFrom))->diff(new \DateTimeImmutable($dateTo))->days + 1);
        $stats = [];
        foreach ($rooms as $roomId => $r) {
            $stats[] = new RoomUsageStat(
                $roomId,
                $r['name'],
                $r['users']['child'],
                $r['users']['adult'],
                $r['reservations']['child'],
                $r['reservations']['adult'],
                MealUsageRate::percent($r['reservations']['child'], $r['users']['child'], $days),
                MealUsageRate::percent($r['reservations']['adult'], $r['users']['adult'], $days),
            );
        }
        usort($stats, static fn(RoomUsageStat $a, RoomUsageStat $b): int => strcmp($a->roomName, $b->roomName));

        return $stats;
    }

    public function dailyChildCounts(array $excludeUserIds, string $dateFrom, string $dateTo): array
    {
        $children = [];
        $users = self::table('MUserInfo')->find()
            ->select(['i_id_user', 'i_user_level'])
            ->where(['i_del_flag' => 0])
            ->all();
        foreach ($users as $user) {
            $userId = (int)$user->i_id_user;
            if ((int)($user->i_user_level ?? 0) === self::CHILD_LEVEL && !in_array($userId, $excludeUserIds, true)) {
                $children[$userId] = true;
            }
        }

        $daily = array_fill_keys(DatePeriodRule::dates($dateFrom, $dateTo), 0);
        foreach ($this->countedReservations($dateFrom, $dateTo) as $row) {
            $date = $row->d_reservation_date->format('Y-m-d');
            if (isset($children[(int)$row->i_id_user], $daily[$date])) {
                $daily[$date]++;
            }
        }

        $out = [];
        foreach ($daily as $date => $count) {
            $out[] = new DailyChildCount((string)$date, $count);
        }

        return $out;
    }

    /**
     * 期間の、食数として数える予約の行。
     *
     * @return iterable<\Cake\Datasource\EntityInterface>
     */
    private function countedReservations(string $dateFrom, string $dateTo): iterable
    {
        $today = new ChronosDate($this->clock->now());
        $rows = self::table('TIndividualReservationInfo')->find()
            ->select(['i_id_user', 'i_id_room', 'eat_flag', 'i_change_flag', 'd_reservation_date'])
            ->where(['d_reservation_date >=' => $dateFrom, 'd_reservation_date <=' => $dateTo])
            ->all();
        foreach ($rows as $row) {
            $counted = ReservationDateRule::countedValue(
                // Date を文字列にするとロケール依存の形（例 10/29/26）になるため、Y-m-d で取り出す
                new ChronosDate($row->d_reservation_date->format('Y-m-d')),
                $row->eat_flag === null ? null : (int)$row->eat_flag,
                $row->i_change_flag === null ? null : (int)$row->i_change_flag,
                $today,
            );
            if ($counted === 1) {
                yield $row;
            }
        }
    }

    public function loginReport(string $dateFrom, string $dateTo): LoginReport
    {
        $rows = self::table('TAuditLog')->find()
            ->select(['c_action', 'i_actor_user_id', 'c_actor_user_name', 'c_actor_login_id', 'i_result', 'c_ip_address', 'dt_create'])
            ->where([
                'c_action IN'  => ['user_login', 'user_login_failed'],
                'dt_create >=' => $dateFrom . ' 00:00:00',
                'dt_create <=' => $dateTo . ' 23:59:59',
            ])
            ->orderBy(['dt_create' => 'DESC'])
            ->all();

        $daily = array_fill_keys(DatePeriodRule::dates($dateFrom, $dateTo), ['success' => 0, 'failed' => 0]);
        $failedLoginNames = AuditLogService::failedLoginActorNames($rows);
        $logs = [];
        foreach ($rows as $row) {
            $dt = $row->dt_create instanceof \DateTimeInterface ? $row->dt_create : new \DateTimeImmutable((string)$row->dt_create);
            $date = $dt->format('Y-m-d');
            $result = (int)$row->i_result;
            if (isset($daily[$date])) {
                $daily[$date][$result === 1 ? 'success' : 'failed']++;
            }
            $logs[] = [
                'dt'        => $dt->format('Y-m-d H:i:s'),
                'user_name' => AuditLogService::actorDisplayName($row, $failedLoginNames),
                'login_id'  => (string)($row->c_actor_login_id ?? ''),
                'result'    => $result,
                'ip'        => (string)($row->c_ip_address ?? ''),
            ];
        }

        $dailyList = [];
        foreach ($daily as $date => $c) {
            $dailyList[] = ['date' => (string)$date, 'success' => $c['success'], 'failed' => $c['failed']];
        }

        return new LoginReport($dailyList, $logs);
    }

    public function userOptions(): array
    {
        $out = [];
        $rows = self::table('MUserInfo')->find()
            ->select(['i_id_user', 'c_user_name', 'i_user_level'])
            ->where(['i_del_flag' => 0])
            ->orderBy(['c_user_name' => 'ASC'])
            ->all();
        foreach ($rows as $row) {
            $out[] = new ReportUserOption(
                (int)$row->i_id_user,
                (string)($row->c_user_name ?? ''),
                (int)($row->i_user_level ?? 0) === self::CHILD_LEVEL,
            );
        }

        // 大人を先に、続けて子ども（それぞれ名前順）
        usort($out, static fn(ReportUserOption $a, ReportUserOption $b): int => $a->isChild !== $b->isChild
            ? ($a->isChild ? 1 : -1)
            : strcmp($a->userName, $b->userName));

        return $out;
    }
}
