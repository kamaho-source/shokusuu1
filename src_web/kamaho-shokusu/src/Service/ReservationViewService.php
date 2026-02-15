<?php
declare(strict_types=1);

namespace App\Service;

use Cake\I18n\Date;
use Cake\ORM\Table;

class ReservationViewService
{
    private ReservationDatePolicy $datePolicy;

    public function __construct(?ReservationDatePolicy $datePolicy = null)
    {
        $this->datePolicy = $datePolicy ?? new ReservationDatePolicy();
    }

    public function buildViewContext(
        $user,
        ?string $dateParam,
        ?int $requestedRoomId,
        Table $roomTable,
        Table $userGroupTable,
        Table $reservationTable,
        ReservationQueryService $queryService
    ): array {
        $userRoomId = null;
        $isAdmin = false;

        if ($user !== null) {
            $userRoomId = $user->get('i_id_room');
            if ($userRoomId === null && $user->get('m_user_info')) {
                $userRoomId = $user->get('m_user_info')->get('i_id_room');
            }
            if ($userRoomId === null) {
                $userId = $user->get('i_id_user');
                if ($userId) {
                    $row = $userGroupTable->find()
                        ->select(['i_id_room'])
                        ->where([
                            'i_id_user' => $userId,
                            'active_flag' => 0,
                        ])
                        ->disableHydration()
                        ->first();
                    if ($row && isset($row['i_id_room'])) {
                        $userRoomId = (int)$row['i_id_room'];
                    }
                }
            }

            if ($userRoomId !== null) {
                $userRoomId = (int)$userRoomId;
            }
            $isAdmin = ((int)$user->get('i_admin') === 1);
        }

        $date = $dateParam;
        if ($date === ':date') {
            $date = null;
        }
        if ($date === null || $date === '') {
            $date = Date::today('Asia/Tokyo')->format('Y-m-d');
        }

        $targetDate = new Date($date, 'Asia/Tokyo');
        $today = Date::today('Asia/Tokyo');
        $diffDays = $today->diff($targetDate)->days;
        $judgeColumn = $this->datePolicy->judgeColumn($targetDate, $today, 'Asia/Tokyo');

        $rooms = $roomTable->find('list', [
            'keyField' => 'i_id_room',
            'valueField' => 'c_room_name',
        ])->toArray();

        $authorizedRooms = [];
        if ($user !== null) {
            $userId = $user->get('i_id_user');
            if ($isAdmin) {
                $authorizedRooms = $rooms;
            } elseif ($userId) {
                $authorizedRooms = $queryService->getAuthorizedRooms(
                    $roomTable,
                    $userGroupTable,
                    (int)$userId
                );
            }
        }

        $activeRoomId = (int)($requestedRoomId ?? 0);
        if ($activeRoomId === 0 || !isset($authorizedRooms[$activeRoomId])) {
            if (!empty($authorizedRooms)) {
                $activeRoomId = (int)array_key_first($authorizedRooms);
            } elseif ($userRoomId !== null) {
                $activeRoomId = (int)$userRoomId;
            }
        }

        $activeRoomName = $authorizedRooms[$activeRoomId] ?? ($rooms[$activeRoomId] ?? '');

        $roomUsers = [];
        $userMealMap = [];
        $otherRoomMealMap = [];
        if ($activeRoomId > 0) {
            $users = $userGroupTable->find()
                ->select([
                    'MUserGroup.i_id_user',
                    'user_name' => 'MUserInfo.c_user_name',
                    'staff_id' => 'MUserInfo.i_id_staff',
                ])
                ->enableAutoFields(false)
                ->innerJoin(
                    ['MUserInfo' => 'm_user_info'],
                    ['MUserInfo.i_id_user = MUserGroup.i_id_user']
                )
                ->where([
                    'MUserGroup.i_id_room' => $activeRoomId,
                    'MUserGroup.active_flag' => 0,
                    'MUserInfo.i_del_flag' => 0,
                ])
                ->order(['MUserGroup.i_id_user' => 'ASC'])
                ->enableHydration(false)
                ->all();

            $activeUserIds = [];
            foreach ($users as $u) {
                $userId = (int)$u['i_id_user'];
                $activeUserIds[] = $userId;
                $roomUsers[] = [
                    'user_id' => $userId,
                    'name' => (string)$u['user_name'],
                    'staff_id' => (string)($u['staff_id'] ?? ''),
                ];
            }

            $isLastMinute = $this->datePolicy->shouldUseChangeFlag($targetDate, $today, 'Asia/Tokyo');
            $effective = static function ($eatFlag, $chgFlag) use ($isLastMinute): int {
                if ($isLastMinute && $chgFlag !== null) {
                    return (int)$chgFlag;
                }
                return (int)($eatFlag ?? 0);
            };

            if (!empty($activeUserIds)) {
                $rows = $reservationTable->find()
                    ->enableAutoFields(false)
                    ->select(['i_id_user', 'i_id_room', 'i_reservation_type', 'eat_flag', 'i_change_flag'])
                    ->where([
                        'i_id_user IN' => $activeUserIds,
                        'd_reservation_date' => $targetDate->format('Y-m-d'),
                        'i_reservation_type IN' => [1, 2, 3, 4],
                    ])
                    ->all();

                foreach ($rows as $r) {
                    $uid = (int)$r->i_id_user;
                    $type = (int)$r->i_reservation_type;
                    $val = $effective($r->eat_flag, $r->i_change_flag) === 1;
                    if (!$val) {
                        continue;
                    }
                    $rid = (int)$r->i_id_room;
                    if ($rid === $activeRoomId) {
                        $userMealMap[$uid][$type] = true;
                    } else {
                        $otherRoomMealMap[$uid][$type] = $rooms[$rid] ?? ('部屋ID:' . $rid);
                    }
                }
            }
        }

        $mealTypes = [1 => '朝', 2 => '昼', 3 => '夜', 4 => '弁当'];
        $mealDataArray = [];

        $totalUsersPerRoom = [];
        $userCounts = $userGroupTable->find()
            ->enableAutoFields(false)
            ->select([
                'room_id' => 'MUserGroup.i_id_room',
                'user_count' => $userGroupTable->find()->func()->count('DISTINCT MUserGroup.i_id_user'),
            ])
            ->innerJoin(
                ['MUserInfo' => 'm_user_info'],
                ['MUserInfo.i_id_user = MUserGroup.i_id_user']
            )
            ->where([
                'MUserGroup.active_flag' => 0,
                'MUserInfo.i_del_flag' => 0,
            ])
            ->andWhere(function ($exp) use ($targetDate) {
                return $exp->lte('MUserInfo.dt_create', $targetDate->format('Y-m-d'));
            })
            ->group(['MUserGroup.i_id_room'])
            ->all();
        foreach ($userCounts as $row) {
            $totalUsersPerRoom[(int)$row->room_id] = (int)$row->user_count;
        }

        foreach ($mealTypes as $mealType => $mealLabel) {
            $mealDataArray[$mealLabel] = [];

            $roomRows = $reservationTable->find()
                ->enableAutoFields(false)
                ->select(['room_id' => 'TIndividualReservationInfo.i_id_room'])
                ->where([
                    'd_reservation_date' => $targetDate->format('Y-m-d'),
                    'i_reservation_type' => $mealType,
                ])
                ->group(['TIndividualReservationInfo.i_id_room'])
                ->all();

            $roomsToShow = [];
            foreach ($roomRows as $row) {
                $rid = (int)$row->room_id;
                if (isset($rooms[$rid])) {
                    $roomsToShow[$rid] = true;
                }
            }

            if (empty($roomsToShow)) {
                continue;
            }

            $eaterCounts = [];
            $eaterRows = $reservationTable->find()
                ->enableAutoFields(false)
                ->select([
                    'room_id' => 'TIndividualReservationInfo.i_id_room',
                    'cnt' => $reservationTable->find()->func()->count('DISTINCT TIndividualReservationInfo.i_id_user'),
                ])
                ->where([
                    'd_reservation_date' => $targetDate->format('Y-m-d'),
                    'i_reservation_type' => $mealType,
                    "TIndividualReservationInfo.{$judgeColumn}" => 1,
                ])
                ->group(['TIndividualReservationInfo.i_id_room'])
                ->all();
            foreach ($eaterRows as $row) {
                $eaterCounts[(int)$row->room_id] = (int)$row->cnt;
            }

            foreach (array_keys($roomsToShow) as $roomId) {
                $totalUsers = $totalUsersPerRoom[$roomId] ?? 0;
                $eaters = $eaterCounts[$roomId] ?? 0;
                $mealDataArray[$mealLabel][$roomId] = [
                    'room_name' => $rooms[$roomId],
                    'taberu_ninzuu' => $eaters,
                    'tabenai_ninzuu' => max(0, $totalUsers - $eaters),
                    'room_id' => $roomId,
                ];
            }
        }

        return [
            'mealDataArray' => $mealDataArray,
            'date' => $date,
            'userRoomId' => $userRoomId,
            'isAdmin' => $isAdmin,
            'diffDays' => $diffDays,
            'judgeColumn' => $judgeColumn,
            'authorizedRooms' => $authorizedRooms,
            'activeRoomId' => $activeRoomId,
            'activeRoomName' => $activeRoomName,
            'roomUsers' => $roomUsers,
            'userMealMap' => $userMealMap,
            'otherRoomMealMap' => $otherRoomMealMap,
        ];
    }
}
