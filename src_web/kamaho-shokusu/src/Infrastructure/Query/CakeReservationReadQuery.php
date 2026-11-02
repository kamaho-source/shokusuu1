<?php
declare(strict_types=1);

namespace App\Infrastructure\Query;

use App\Application\Query\Dto\BulkRoomUser;
use App\Application\Query\Dto\BulkRoomUsers;
use App\Application\Query\Dto\PersonalReservation;
use App\Application\Query\Dto\RoomUserMeals;
use App\Application\Query\ReservationReadQueryInterface;
use App\Application\Query\RoomQueryInterface;
use App\Domain\Service\ReservationDateRule;
use App\Domain\ValueObject\MealType;
use App\Infrastructure\Persistence\Table\MUserGroupTable;
use App\Service\RoomMemberOrderService;
use Cake\Chronos\ChronosDate;
use Cake\I18n\Date;
use Cake\ORM\Table;
use Cake\ORM\TableRegistry;
use Psr\Clock\ClockInterface;

/**
 * ReservationReadQueryInterface の CakePHP 実装（m_user_group・m_user_info・t_individual_reservation_info の読み取り）。
 */
final class CakeReservationReadQuery implements ReservationReadQueryInterface
{
    public function __construct(
        private readonly RoomQueryInterface $rooms,
        private readonly ClockInterface $clock,
    ) {
    }

    private static function table(string $alias): Table
    {
        return TableRegistry::getTableLocator()->get($alias);
    }

    /** 直前期間の判定（境界日以前は i_change_flag を見る）。 */
    private function usesChangeFlag(Date $targetDate): bool
    {
        return ReservationDateRule::usesChangeFlag($targetDate, new ChronosDate($this->clock->now()));
    }

    public function getUsersByRoom(int $roomId, ?string $date): array
    {
        $userGroupTable   = self::table('MUserGroup');
        $reservationTable = self::table('TIndividualReservationInfo');
        $users = $userGroupTable->find()
            ->enableAutoFields(false)
            ->select([
                'MUserGroup.i_id_user',
                'MUserGroup.i_id_room',
                'user_name' => 'MUserInfo.c_user_name',
            ])
            ->innerJoin(
                ['MUserInfo' => 'm_user_info'],
                ['MUserInfo.i_id_user = MUserGroup.i_id_user']
            )
            ->where(['MUserGroup.i_id_room' => $roomId, ...MUserGroupTable::activeCondition()])
            ->andWhere(['MUserInfo.i_del_flag' => 0])
            ->enableHydration(false);
        $users = RoomMemberOrderService::orderMembers($users)->toArray();

        $existingReservations = [];
        $useChangeFlag = false;
        if ($date) {
            try {
                $targetDate = new Date($date);
                $useChangeFlag = $this->usesChangeFlag($targetDate);
            } catch (\Throwable $e) {
                $useChangeFlag = false;
            }

            $existingReservations = $reservationTable->find()
                ->select(['i_id_user', 'i_reservation_type', 'eat_flag', 'i_change_flag'])
                ->where([
                    'i_id_room' => $roomId,
                    'd_reservation_date' => $date
                ])
                ->toArray();
        }

        $reservedMap = [];
        foreach ($existingReservations as $reservation) {
            if ($this->effectiveFlag($reservation->eat_flag, $reservation->i_change_flag, $useChangeFlag) !== 1) {
                continue;
            }
            $reservedMap[(int)$reservation->i_id_user][(int)$reservation->i_reservation_type] = true;
        }

        $usersByRoom = [];
        foreach ($users as $user) {
            $userId = (int)$user['i_id_user'];
            $usersByRoom[] = new RoomUserMeals(
                $userId,
                (string)$user['user_name'],
                !empty($reservedMap[$userId][1]),
                !empty($reservedMap[$userId][2]),
                !empty($reservedMap[$userId][3]),
                !empty($reservedMap[$userId][4]),
            );
        }

        return $usersByRoom;
    }

    public function getUsersByRoomForBulk(int $roomId, ?string $date, int $page, int $limit): BulkRoomUsers
    {
        $userGroupTable   = self::table('MUserGroup');
        $reservationTable = self::table('TIndividualReservationInfo');
        $baseConditions = [
            'MUserGroup.i_id_room' => $roomId,
            ...MUserGroupTable::activeCondition()
        ];

        $total = (int)$userGroupTable->find()
            ->innerJoin(
                ['MUserInfo' => 'm_user_info'],
                ['MUserInfo.i_id_user = MUserGroup.i_id_user']
            )
            ->where($baseConditions)
            ->andWhere(['MUserInfo.i_del_flag' => 0])
            ->count();

        $users = $userGroupTable->find()
            ->enableAutoFields(false)
            ->select([
                'MUserGroup.i_id_user',
                'MUserGroup.i_id_room',
                'user_name' => 'MUserInfo.c_user_name',
                'user_level' => 'MUserInfo.i_user_level',
                'staff_id' => 'MUserInfo.i_id_staff',
            ])
            ->innerJoin(
                ['MUserInfo' => 'm_user_info'],
                ['MUserInfo.i_id_user = MUserGroup.i_id_user']
            )
            ->where($baseConditions)
            ->andWhere(['MUserInfo.i_del_flag' => 0])
            ->limit($limit)
            ->page($page)
            ->enableHydration(false);
        // ページ送りで重複・抜けが出ないよう、並びを一意に決める
        $users = RoomMemberOrderService::orderMembers($users)->all();

        $userData = [];
        foreach ($users as $user) {
            $staffId = $user['staff_id'] ?? null;
            $userData[] = new BulkRoomUser(
                (int)$user['i_id_user'],
                (string)$user['user_name'],
                (int)($user['user_level'] ?? 0),
                $staffId !== null && $staffId !== '',
            );
        }

        $reservations = [];
        $otherRoomReservations = [];
        $snapshot = null;
        if (!empty($date) && !empty($userData)) {
            $userIds = array_map(static fn(BulkRoomUser $u) => $u->userId, $userData);
            try {
                // 他サービス（getUsersByRoom/getPersonalReservationData 等）と判定基準を揃える。
                // 下限のない shouldUseChangeFlag（i_change_flag が非NULLなら日付を問わず優先）を用いる。
                $targetDate = new Date($date, 'Asia/Tokyo');
                $useChangeFlag = $this->usesChangeFlag($targetDate);
            } catch (\Throwable $e) {
                $useChangeFlag = false;
            }

            $snapshotQuery = $reservationTable->find();
            $snapshotRow = $snapshotQuery
                ->enableAutoFields(false)
                ->select([
                    'max_dt' => $snapshotQuery->func()->max(
                        $snapshotQuery->newExpr('COALESCE(dt_update, dt_create)'),
                        // 型を渡さないと float 扱いになり '2026-10-24 15:04:05' が 2026 になる（年が同じだと競合を検出できない）
                        ['string']
                    )
                ])
                ->where([
                    'd_reservation_date' => $date,
                    'i_id_room' => $roomId,
                ])
                ->first();
            if ($snapshotRow && $snapshotRow->max_dt) {
                $snapshot = (string)$snapshotRow->max_dt;
            }

            $rows = $reservationTable->find()
                ->enableAutoFields(false)
                ->select(['i_id_user', 'i_reservation_type', 'eat_flag', 'i_change_flag', 'i_id_room'])
                ->where([
                    'd_reservation_date' => $date,
                    'i_id_user IN' => $userIds,
                    'i_reservation_type IN' => MealType::values(),
                ])
                ->all();

            foreach ($rows as $r) {
                $uid = (int)$r->i_id_user;
                $type = (int)$r->i_reservation_type;
                if ($this->effectiveFlag($r->eat_flag, $r->i_change_flag, $useChangeFlag) !== 1) {
                    continue;
                }
                $rid = (int)$r->i_id_room;
                if ($rid === $roomId) {
                    $reservations[$uid][$type] = true;
                } else {
                    $otherRoomReservations[$uid][$type] = $rid;
                }
            }
        }

        return new BulkRoomUsers($userData, $reservations, $otherRoomReservations, $total, $page, $limit, $snapshot);
    }

    public function getReservationSnapshots(int $roomId, array $dates): array
    {
        if (!$roomId || empty($dates)) {
            return [];
        }
        $snapshotQuery = self::table('TIndividualReservationInfo')->find();
        $rows = $snapshotQuery
            ->enableAutoFields(false)
            ->select([
                'd_reservation_date',
                'max_dt' => $snapshotQuery->func()->max(
                    $snapshotQuery->newExpr('COALESCE(dt_update, dt_create)'),
                    // 型を渡さないと float 扱いになり '2026-10-24 15:04:05' が 2026 になる（年が同じだと競合を検出できない）
                    ['string']
                )
            ])
            ->where([
                'i_id_room' => $roomId,
                'd_reservation_date IN' => $dates,
            ])
            ->group(['d_reservation_date'])
            ->all();

        $map = [];
        foreach ($rows as $row) {
            // Date を文字列にするとロケール依存の形（例 10/27/26）になり、画面の Y-m-d のキーで引けず競合チェックが効かない。
            // サーバー側の比較（ReservationBulkService::checkReservationSnapshots）と同じく Y-m-d で取り出す
            $dateKey = is_object($row->d_reservation_date) ? $row->d_reservation_date->format('Y-m-d') : (string)$row->d_reservation_date;
            $map[$dateKey] = $row->max_dt ? (string)$row->max_dt : null;
        }
        return $map;
    }

    /**
     * 予約の有効値を返す。
     *
     * $useChangeFlag（直前期間）が真で
     * i_change_flag が非NULLの場合は i_change_flag を優先し、それ以外は eat_flag を用いる。
     *
     */
    private function effectiveFlag(?int $eatFlag, ?int $chgFlag, bool $useChangeFlag): int
    {
        if ($useChangeFlag && $chgFlag !== null) {
            return (int)$chgFlag;
        }
        return (int)($eatFlag ?? 0);
    }

    public function getPersonalReservation(int $userId, string $userName, string $date): PersonalReservation
    {
        $reservationTable = self::table('TIndividualReservationInfo');
        try {
            $targetDate = new Date($date, 'Asia/Tokyo');
            $isLastMinute = $this->usesChangeFlag($targetDate);
        } catch (\Throwable $e) {
            $isLastMinute = false;
        }

        $query = $reservationTable->find()
            ->select(['i_reservation_type', 'i_id_room', 'eat_flag', 'i_change_flag'])
            ->where([
                'i_id_user'          => $userId,
                'd_reservation_date' => $date,
            ]);

        if (!$isLastMinute) {
            $query->andWhere(['eat_flag' => 1]);
        }

        $reservations = $query->toArray();

        // reservation[roomId][mealType] = true の形で返す
        $result = [];
        foreach ($reservations as $reservation) {
            if ($this->effectiveFlag($reservation->eat_flag, $reservation->i_change_flag, $isLastMinute) !== 1) {
                continue;
            }
            $roomId = (string)$reservation->i_id_room;
            $type   = (string)$reservation->i_reservation_type;
            if (!isset($result[$roomId])) {
                $result[$roomId] = [];
            }
            $result[$roomId][$type] = true;
        }

        return new PersonalReservation($userId, $userName, $result, $this->rooms->getAccessibleRooms($userId));
    }

    public function hasDuplicateReservation(string $date, int $roomId, int $mealType): bool
    {
        return self::table('TIndividualReservationInfo')->exists([
            'd_reservation_date' => $date,
            'i_id_room' => $roomId,
            'i_reservation_type' => $mealType,
        ]);
    }
}