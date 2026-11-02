<?php
declare(strict_types=1);

namespace App\Infrastructure\Query;

use App\Application\Query\Dto\OtherRoomEater;
use App\Application\Query\Dto\RoomMealDetail;
use App\Application\Query\RoomMealDetailQueryInterface;
use App\Domain\Exception\NotFoundException;
use App\Domain\Service\ReservationDateRule;
use App\Infrastructure\Persistence\Table\MUserGroupTable;
use App\Service\RoomMemberOrderService;
use Cake\Chronos\ChronosDate;
use Cake\I18n\Date;
use Cake\ORM\TableRegistry;
use Psr\Clock\ClockInterface;

/**
 * RoomMealDetailQueryInterface の CakePHP 実装。
 */
final class CakeRoomMealDetailQuery implements RoomMealDetailQueryInterface
{
    public function __construct(private readonly ClockInterface $clock)
    {
    }

    public function getRoomMealDetail(int $roomId, string $date, int $mealType): RoomMealDetail
    {
        $locator          = TableRegistry::getTableLocator();
        $roomTable        = $locator->get('MRoomInfo');
        $reservationTable = $locator->get('TIndividualReservationInfo');
        $userGroupTable   = $locator->get('MUserGroup');
        $today            = new ChronosDate($this->clock->now());

        try {
            $targetDate = new Date($date);
        } catch (\Exception $e) {
            throw new \InvalidArgumentException('日付の形式が正しくありません。');
        }

        // 境界日（今日+14日）以前は i_change_flag を見る（予約の日付ルール）
        $useChangeFlag = ReservationDateRule::usesChangeFlag($targetDate, $today);

        $room = $roomTable->find()
            ->select(['c_room_name'])
            ->where(['i_id_room' => $roomId])
            ->first();

        if (!$room) {
            throw new NotFoundException('部屋が見つかりません。');
        }

        $baseConditions = [
            'TIndividualReservationInfo.i_id_room'          => $roomId,
            'TIndividualReservationInfo.d_reservation_date' => $date,
            'TIndividualReservationInfo.i_reservation_type' => $mealType,
            'MUserInfo.i_del_flag'                          => 0,
            ...MUserGroupTable::activeCondition(),
        ];

        // 食べる・食べないは ReservationDateRule::countedValue で分ける（境界日以前は i_change_flag、
        // NULL なら eat_flag。列だけで絞ると、i_change_flag が NULL の行が「未登録」に落ちるため）
        $eaters = [];
        $nonEaters = [];
        $rows = $reservationTable->find()
            ->select([
                'TIndividualReservationInfo.i_id_user',
                'TIndividualReservationInfo.i_id_room',
                'TIndividualReservationInfo.eat_flag',
                'TIndividualReservationInfo.i_change_flag',
                'MUserInfo.c_user_name',
            ])
            ->contain(['MUserInfo', 'MUserGroup'])
            ->where($baseConditions);
        $rows = RoomMemberOrderService::orderMembers($rows)->all();
        foreach ($rows as $row) {
            if (ReservationDateRule::countedValue($targetDate, $row->eat_flag === null ? null : (int)$row->eat_flag, $row->i_change_flag === null ? null : (int)$row->i_change_flag, $today) === 1) {
                $eaters[] = $row;
                continue;
            }
            $nonEaters[] = $row;
        }

        $allUsers = $userGroupTable->find()
            ->select(['MUserInfo.i_id_user', 'MUserInfo.c_user_name', 'MUserInfo.dt_create'])
            ->contain(['MUserInfo'])
            ->where([
                'MUserGroup.i_id_room'   => $roomId,
                'MUserInfo.i_del_flag'   => 0,
                ...MUserGroupTable::activeCondition(),
            ]);
        $allUsers = RoomMemberOrderService::orderMembers($allUsers)->all();

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
                $otherRoomEaters[] = new OtherRoomEater($eater->m_user_info->c_user_name, (string)$roomName);
            }
        }

        return new RoomMealDetail(
            $room->c_room_name === null ? null : (string)$room->c_room_name,
            array_values($eatUsers),
            array_values($noEatUsers),
            $otherRoomEaters,
            $useChangeFlag,
        );
    }
}
