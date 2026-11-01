<?php
declare(strict_types=1);

namespace App\Application\UseCase\BulkAddReservations;

use App\Domain\Entity\MealReservation;
use App\Domain\Repository\ReservationRepositoryInterface;
use App\Domain\Repository\RoomMembershipRepositoryInterface;
use App\Domain\ValueObject\MealType;
use Cake\Chronos\ChronosDate;
use Psr\Clock\ClockInterface;

/**
 * 個人の一括登録（自分の予約を、複数日 × 食事 × 部屋でまとめて登録する）で書く行を決める。
 * 通常予約の日付ルールを守る。昼食⇔弁当は、相手のチェックを明示的に外したときだけ入れ替える。
 */
final class PersonalBulkAddPlanner
{
    public function __construct(
        private readonly ReservationRepositoryInterface $reservations,
        private readonly RoomMembershipRepositoryInterface $rooms,
        private readonly BulkAddRequestValidator $validator,
        private readonly ClockInterface $clock,
    ) {}

    /**
     * 個人の一括登録で書く行を決める。登録できない理由があれば ok=false の結果を返す。
     *
     * @return array<string, mixed> 結果（ok=false）または書く内容
     */
    public function plan(BulkAddReservationsInput $input): array
    {
        $data     = $input->data;
        $userId   = $input->userId;
        $userName = $input->userName;

        $reservations     = [];
        $skippedMessages  = [];
        $swappedMessages  = []; // 昼食⇔弁当の入れ替えで取り消した予約（黙って取り消さず、応答で伝える）
        $rowsToActivate   = [];
        $rowsToDeactivate = [];

        $dates = isset($data['dates']) && is_array($data['dates']) ? $data['dates'] : [];
        $meals = isset($data['meals']) && is_array($data['meals']) ? $data['meals'] : [];

        $hasSelectedDate = false;
        foreach ($dates as $checkedDate) {
            if ($checkedDate) {
                $hasSelectedDate = true;
                break;
            }
        }
        if (!$hasSelectedDate) {
            return [
                'ok' => false,
                'message' => '日付が選択されていません。',
            ];
        }

        $hasSelectedMeal = false;
        foreach ($meals as $roomList) {
            foreach ($roomList as $checkedMeal) {
                if ($checkedMeal) {
                    $hasSelectedMeal = true;
                    break 2;
                }
            }
        }
        if (!$hasSelectedMeal) {
            return [
                'ok' => false,
                'message' => '食事が選択されていません。',
            ];
        }

        $selectedDates = [];
        foreach ($dates as $date => $checkedDate) {
            if ($checkedDate) {
                $selectedDates[] = $date;
            }
        }
        $selectedMealTypes = [];
        $selectedRoomIds   = [];
        foreach ($meals as $mealType => $roomList) {
            foreach ($roomList as $roomId => $checkedMeal) {
                if ($checkedMeal) {
                    $selectedMealTypes[(int)$mealType] = true;
                    $selectedRoomIds[(int)$roomId]     = true;
                }
            }
        }
        $selectedMealTypes = array_keys($selectedMealTypes);
        $selectedRoomIds   = array_keys($selectedRoomIds);
        $roomValidation    = $this->validator->roomError($selectedRoomIds);
        if ($roomValidation !== null) {
            return [
                'ok' => false,
                'message' => $roomValidation,
            ];
        }
        // 自分が有効に所属している部屋にしか登録できない（管理者も同じ。#821）
        foreach ($selectedRoomIds as $selectedRoomId) {
            if (!$this->rooms->isActiveMember($userId, (int)$selectedRoomId)) {
                return [
                    'ok' => false,
                    'message' => '所属していない部屋には登録できません。',
                ];
            }
        }
        $dateValidation = $this->validator->dateError($selectedDates, true);
        if ($dateValidation !== null) {
            return [
                'ok' => false,
                'message' => $dateValidation,
            ];
        }

        $today             = new ChronosDate($this->clock->now());
        $existingActiveMap = [];
        $existingRoomMap   = [];
        $existingRows      = $this->reservations->findByUsersAndDates(
            $selectedDates,
            [$userId],
            // 昼食と弁当を入れ替えるときに相手の行も要るので、どちらかを選んだら両方を読む
            array_values(array_unique(array_merge(
                $selectedMealTypes,
                array_intersect($selectedMealTypes, MealType::EXCLUSIVE_VALUES) !== [] ? MealType::EXCLUSIVE_VALUES : []
            )))
        );
        foreach ($existingRows as $row) {
            $existingRoomMap[$row->date][$row->mealType][$row->roomId] = $row;
            if ($row->isEffective($today)) {
                $existingActiveMap[$row->date][$row->mealType] = $row;
            }
        }

        $roomNameMap = $this->rooms->roomNames($selectedRoomIds);

        foreach ($dates as $date => $checkedDate) {
            if (!$checkedDate) {
                continue;
            }
            foreach ($meals as $mealType => $roomList) {
                foreach ($roomList as $roomId => $checkedMeal) {
                    if (!$checkedMeal) {
                        continue;
                    }
                    $mealTypeInt = (int)$mealType;
                    $roomIdInt   = (int)$roomId;
                    $activeRow   = $existingActiveMap[$date][$mealTypeInt] ?? null;
                    $roomRow     = $existingRoomMap[$date][$mealTypeInt][$roomIdInt] ?? null;
                    if ($activeRow !== null) {
                        $skippedMessages[] = sprintf(
                            '日付 %s（%s）の"%s"は %s の予約が既に存在していたためスキップしました。',
                            $date,
                            $roomNameMap[$roomIdInt] ?? '',
                            MealType::labels(MealType::LABEL_SHORT_NIGHT)[$mealTypeInt],
                            $userName
                        );
                        continue;
                    }
                    // 昼食⇔弁当: 相手のチェックを明示的に外して送られたときは、相手の予約を取り消して入れ替える
                    // （取消は有効化・新規保存より先に実行される）。外していなければ、保存時に理由付きで拒否される
                    $opponent = MealType::counterpartValue($mealTypeInt);
                    if ($opponent !== null && isset($meals[$opponent]) && is_array($meals[$opponent])
                        && array_filter($meals[$opponent]) === []
                    ) {
                        foreach ($existingRoomMap[$date][$opponent] ?? [] as $oppRoomId => $oppRow) {
                            if ($oppRow->isEffective($today)) {
                                $rowsToDeactivate[implode(':', [$date, $userId, $opponent, (int)$oppRoomId])] = $oppRow;
                                $swappedMessages[$date . ':' . $opponent] = sprintf(
                                    '日付 %s は%sを取り消して%sにしました。',
                                    $date,
                                    MealType::labelOf($opponent),
                                    MealType::labelOf($mealTypeInt)
                                );
                            }
                        }
                    }
                    if ($roomRow !== null) {
                        $rowsToActivate[implode(':', [$date, $userId, $mealTypeInt, $roomIdInt])] = $roomRow;
                        continue;
                    }
                    $reservations[] = new MealReservation($userId, (string)$date, $roomIdInt, $mealTypeInt, 1, 1);
                }
            }
        }

        return [
            'create'        => $reservations,
            'activate'      => $rowsToActivate,
            'deactivate'    => $rowsToDeactivate,
            'skipped'       => $skippedMessages,
            'swapped'       => $swappedMessages,
            'roomId'        => 0,
            'dayUsers'      => [],
            'users'         => [],
            'dates'         => $dates,
            'selectedDates' => $selectedDates,
        ];
    }
}
