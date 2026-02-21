<?php
declare(strict_types=1);

namespace App\Service;

use Cake\Http\Exception\NotFoundException;
use Cake\ORM\Table;

class ReservationRoomDetailService
{
    public function getRoomDetails(
        int $roomId,
        string $date,
        int $mealType,
        Table $roomTable,
        Table $reservationTable,
        Table $userGroupTable
    ): array {
        try {
            $targetDate   = new \DateTimeImmutable($date);
            $changeBorder = (new \DateTimeImmutable('today'))->modify('+14 days');
        } catch (\Exception $e) {
            throw new \InvalidArgumentException('日付の形式が正しくありません。');
        }

        $useChangeFlag = $targetDate <= $changeBorder;
        $flagField     = $useChangeFlag ? 'i_change_flag' : 'eat_flag';

        $room = $roomTable->find()
            ->select(['c_room_name'])
            ->where(['i_id_room' => $roomId])
            ->first();

        if (!$room) {
            throw new NotFoundException(__('部屋が見つかりません。'));
        }

        $baseConditions = [
            'TIndividualReservationInfo.i_id_room'          => $roomId,
            'TIndividualReservationInfo.d_reservation_date' => $date,
            'TIndividualReservationInfo.i_reservation_type' => $mealType,
            'MUserInfo.i_del_flag'                          => 0,
            'MUserGroup.active_flag'                        => 0,
        ];

        $eaters = $reservationTable->find()
            ->select([
                'TIndividualReservationInfo.i_id_user',
                'TIndividualReservationInfo.i_id_room',
                'MUserInfo.c_user_name',
            ])
            ->contain(['MUserInfo', 'MUserGroup'])
            ->where($baseConditions + ["TIndividualReservationInfo.$flagField" => 1])
            ->all();

        $nonEaters = $reservationTable->find()
            ->select(['TIndividualReservationInfo.i_id_user', 'MUserInfo.c_user_name'])
            ->contain(['MUserInfo', 'MUserGroup'])
            ->where($baseConditions + ["TIndividualReservationInfo.$flagField" => 0])
            ->all();

        $allUsers = $userGroupTable->find()
            ->select(['MUserInfo.i_id_user', 'MUserInfo.c_user_name', 'MUserInfo.dt_create'])
            ->contain(['MUserInfo'])
            ->where([
                'MUserGroup.i_id_room'   => $roomId,
                'MUserInfo.i_del_flag'   => 0,
                'MUserGroup.active_flag' => 0,
            ])
            ->all();

        $allUserIds   = [];
        $allUserNames = [];
        foreach ($allUsers as $user) {
            $userInfo = $user->m_user_info ?? null;
            if ($userInfo) {
                $allUserIds[]                       = $userInfo->i_id_user;
                $allUserNames[$userInfo->i_id_user] = $userInfo->c_user_name;
            }
        }

        $eatUserIds = collection($eaters)->extract('i_id_user')->toArray();
        $noEatUserIds = collection($nonEaters)->extract('i_id_user')->toArray();

        $notRegisteredUserIds = array_diff($allUserIds, array_merge($eatUserIds, $noEatUserIds));

        $eatUsers = [];
        foreach ($eaters as $eater) {
            if ($eater->has('m_user_info')) {
                $eatUsers[] = $eater->m_user_info->c_user_name;
            }
        }

        $noEatUsers = [];
        foreach ($nonEaters as $nonEater) {
            if ($nonEater->has('m_user_info')) {
                $userInfo = $nonEater->m_user_info;
                if (empty($userInfo->dt_create) || $userInfo->dt_create <= $date) {
                    $noEatUsers[] = $userInfo->c_user_name;
                }
            }
        }

        foreach ($notRegisteredUserIds as $userId) {
            if (isset($allUserNames[$userId]) && !in_array($allUserNames[$userId], $noEatUsers, true)) {
                $noEatUsers[] = $allUserNames[$userId];
            }
        }

        $otherRoomEaters = [];
        foreach ($eaters as $eater) {
            if ($eater->has('m_user_info') && $eater->i_id_room !== null && (int)$eater->i_id_room !== (int)$roomId) {
                $otherRoomRoom = $roomTable->find()
                    ->select(['c_room_name'])
                    ->where(['i_id_room' => $eater->i_id_room])
                    ->first();

                $roomName = $otherRoomRoom ? $otherRoomRoom->c_room_name : '不明な部屋';
                $otherRoomEaters[] = [
                    'user_name' => $eater->m_user_info->c_user_name,
                    'room_name' => $roomName,
                ];
            }
        }

        return [
            'room' => $room,
            'eatUsers' => $eatUsers,
            'noEatUsers' => $noEatUsers,
            'otherRoomEaters' => $otherRoomEaters,
            'useChangeFlag' => $useChangeFlag,
        ];
    }
}
