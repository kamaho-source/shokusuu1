<?php
declare(strict_types=1);

namespace App\Infrastructure\Query;

use App\Application\Query\Dto\HistoryLogEntry;
use App\Application\Query\Dto\HistoryViewer;
use App\Application\Query\ReservationHistoryQueryInterface;
use App\Application\Query\RoomQueryInterface;
use App\Domain\Service\ReservationDateRule;
use App\Domain\ValueObject\MealType;
use App\Domain\ValueObject\UserRole;
use App\Infrastructure\Persistence\Table\MUserGroupTable;
use Cake\Chronos\ChronosDate;
use Cake\ORM\Table;
use Cake\ORM\TableRegistry;
use Psr\Clock\ClockInterface;

/**
 * ReservationHistoryQueryInterface の CakePHP 実装（m_user_info・m_user_group・t_individual_reservation_info・t_audit_log の読み取り）。
 */
final class CakeReservationHistoryQuery implements ReservationHistoryQueryInterface
{
    /** 経緯の取得件数の上限。ponytail: 対象者1人の監査ログ上限。超える場合は期間を絞ってから探す作りに変える */
    private const LOG_LIMIT = 2000;

    public function __construct(
        private readonly RoomQueryInterface $rooms,
        private readonly ClockInterface $clock,
    ) {
    }

    private static function table(string $alias): Table
    {
        return TableRegistry::getTableLocator()->get($alias);
    }

    public function findViewer(int $userId): ?HistoryViewer
    {
        $u = self::table('MUserInfo')->find()
            ->select(['i_id_user', 'c_user_name', 'i_admin', 'i_user_level'])
            ->where(['i_id_user' => $userId, 'i_del_flag' => 0])
            ->first();
        if ($u === null) {
            return null;
        }

        return new HistoryViewer(
            (int)$u->i_id_user,
            (int)$u->i_admin,
            $u->i_user_level === null ? null : (int)$u->i_user_level,
            (string)$u->c_user_name,
        );
    }

    public function selectableTargets(HistoryViewer $viewer): array
    {
        $out = [$viewer->id => $viewer->name];

        if (UserRole::isAdmin($viewer->admin)) {
            $users = self::table('MUserInfo')->find()
                ->select(['i_id_user', 'c_user_name'])
                ->where(['i_del_flag' => 0])
                ->orderBy(['i_disp_no' => 'ASC', 'i_id_user' => 'ASC'])
                ->enableHydration(false);
            foreach ($users as $u) {
                $out[(int)$u['i_id_user']] ??= (string)$u['c_user_name'];
            }

            return $out;
        }

        $isBlockLeader = UserRole::isBlockLeader($viewer->admin);
        $isStaff = $viewer->level !== null && UserRole::isStaffLevel($viewer->level);
        if (!$isBlockLeader && !$isStaff) {
            return $out;
        }

        $groups = self::table('MUserGroup');
        $roomIds = $groups->find()
            ->select(['i_id_room'])
            ->where(['i_id_user' => $viewer->id, ...MUserGroupTable::activeCondition('')]);
        $members = $groups->find()
            ->enableAutoFields(false)
            ->select(['id' => 'MUserInfo.i_id_user', 'name' => 'MUserInfo.c_user_name', 'level' => 'MUserInfo.i_user_level'])
            ->innerJoin(['MUserInfo' => 'm_user_info'], ['MUserInfo.i_id_user = MUserGroup.i_id_user'])
            ->where([
                'MUserGroup.i_id_room IN' => $roomIds,
                ...MUserGroupTable::activeCondition(),
                'MUserInfo.i_del_flag' => 0,
            ])
            ->orderBy(['MUserInfo.i_disp_no' => 'ASC', 'MUserInfo.i_id_user' => 'ASC'])
            ->enableHydration(false);
        foreach ($members as $m) {
            // ブロック長は大人も子どもも、職員は子どもだけ
            if (!$isBlockLeader && (int)($m['level'] ?? -1) !== 1) {
                continue;
            }
            $out[(int)$m['id']] ??= (string)$m['name'];
        }

        return $out;
    }

    public function bookedMeals(int $userId, string $from, string $to): array
    {
        $out = [];
        foreach ($this->bookedRows(['i_id_user' => $userId], $from, $to) as [$date, $type]) {
            $out[$date][$type] = true;
        }
        ksort($out);

        return $out;
    }

    public function roomSummary(int $roomId, string $from, string $to): array
    {
        $out = [];
        foreach ($this->bookedRows(['i_id_room' => $roomId], $from, $to) as [$date, $type]) {
            $out[$date][$type] = ($out[$date][$type] ?? 0) + 1;
        }
        ksort($out);

        return $out;
    }

    /**
     * 予約済みの行（日付・食事）。
     *
     * @param array<string, int> $where
     * @return iterable<array{string, int}>
     */
    private function bookedRows(array $where, string $from, string $to): iterable
    {
        $today = new ChronosDate($this->clock->now());
        $rows = self::table('TIndividualReservationInfo')->find()
            ->select(['d_reservation_date', 'i_reservation_type', 'eat_flag', 'i_change_flag'])
            ->where([...$where, 'd_reservation_date >=' => $from, 'd_reservation_date <=' => $to])
            ->all();
        foreach ($rows as $r) {
            $date = $r->d_reservation_date->format('Y-m-d');
            $counted = ReservationDateRule::countedValue(
                new ChronosDate($date),
                $r->eat_flag === null ? null : (int)$r->eat_flag,
                $r->i_change_flag === null ? null : (int)$r->i_change_flag,
                $today,
            );
            if ($counted === 1) {
                yield [$date, (int)$r->i_reservation_type];
            }
        }
    }

    public function changeLog(int $userId, string $from, string $to, bool $withActorName): array
    {
        $logs = self::table('TAuditLog')->find()
            ->where([
                'i_result' => 1,
                'OR' => [
                    ['c_action' => 'reservation_toggle', 'c_detail LIKE' => '%"user_id":' . $userId . ',%'],
                    ['c_action' => 'reservation_toggle', 'c_detail LIKE' => '%"user_id":' . $userId . '}%'],
                    ['c_action' => 'reservation_toggle', 'i_actor_user_id' => $userId, 'c_detail NOT LIKE' => '%"user_id":%'],
                ],
            ])
            ->orderBy(['dt_create' => 'DESC', 'i_id_audit' => 'DESC'])
            ->limit(self::LOG_LIMIT)
            ->all();

        /** @var array<int, bool> $isAdmin */
        $isAdmin = [];
        $out = [];
        foreach ($logs as $log) {
            $detail = json_decode((string)$log->c_detail, true);
            if (!is_array($detail)) {
                continue;
            }
            $date = (string)($detail['date'] ?? '');
            $meal = (int)($detail['meal'] ?? 0);
            if ($date < $from || $date > $to || MealType::tryFrom($meal) === null) {
                continue;
            }
            $actorId = (int)$log->i_actor_user_id;
            if ($actorId !== $userId && $actorId > 0) {
                $isAdmin[$actorId] ??= UserRole::isAdmin($this->findViewer($actorId)?->admin ?? 0);
            }
            $role = match (true) {
                $actorId === $userId     => '本人',
                $isAdmin[$actorId] ?? false => '管理者',
                default                  => '代理',
            };
            $out[] = new HistoryLogEntry(
                $log->dt_create->format('Y-m-d H:i'),
                $date,
                $meal,
                (int)($detail['value'] ?? 0) === 1 ? HistoryLogEntry::RESERVE : HistoryLogEntry::CANCEL,
                $withActorName ? $role . '（' . $log->c_actor_user_name . '）' : $role,
            );
        }

        return $out;
    }

    public function logStartDate(): ?string
    {
        $row = self::table('TAuditLog')->find()
            ->select(['first' => 'MIN(dt_create)'])
            ->enableHydration(false)
            ->first();
        $first = $row['first'] ?? null;

        return $first === null ? null : substr((string)$first, 0, 10);
    }

    public function summaryRooms(int $userId): array
    {
        if ($this->rooms->isOfficeUser($userId)) {
            return $this->rooms->getAllActiveRooms();
        }

        return $this->rooms->getAccessibleRooms($userId);
    }
}
