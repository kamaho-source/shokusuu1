<?php
declare(strict_types=1);

namespace App\Application\UseCase\BulkAddReservations;

use App\Application\UseCase\BulkReservation\BulkReservationSupport;
use App\Domain\Entity\MealReservation;
use App\Domain\Repository\ReservationRepositoryInterface;
use App\Domain\Repository\RoomMembershipRepositoryInterface;
use App\Domain\Repository\UserRepositoryInterface;
use App\Domain\Service\ChangeEditPermission;
use App\Domain\Service\ReservationDateRule;
use App\Domain\ValueObject\MealType;
use App\Domain\ValueObject\UserRole;
use App\Domain\ValueObject\UserRoleInfo;
use Cake\Chronos\ChronosDate;
use Psr\Clock\ClockInterface;

/**
 * 部屋単位の一括登録で書く行を決める。
 * 新しい画面は day_users（日付 => 利用者 => 食事 => 0/1）で登録と取消を、古い画面は users で登録だけを送る。
 */
final class GroupBulkAddPlanner
{
    /** 古い画面（users）の食事キー => 食事区分 */
    private const MEAL_TIME_MAP = [
        'morning' => 1,
        'noon'    => 2,
        'night'   => 3,
        'bento'   => 4,
    ];

    private const NO_PERMISSION_MESSAGE = '他ユーザーの予約を更新する権限がありません。';
    private const STAFF_LAST_MINUTE_CANCEL_MESSAGE = '職員は直前編集でのキャンセルはできません。';

    public function __construct(
        private readonly ReservationRepositoryInterface $reservations,
        private readonly RoomMembershipRepositoryInterface $rooms,
        private readonly UserRepositoryInterface $users,
        private readonly BulkReservationSupport $support,
        private readonly BulkAddRequestValidator $validator,
        private readonly ClockInterface $clock,
    ) {}

    /**
     * 部屋単位の一括登録で書く行を決める。登録できない理由があれば ok=false の結果を返す。
     *
     * @return array<string, mixed> 結果（ok=false）または書く内容
     */
    public function plan(BulkAddReservationsInput $input): array
    {
        $data   = $input->data;
        $userId = $input->userId;

        $roomId    = $data['i_id_room'] ?? null;
        $dayUsers  = isset($data['day_users']) && is_array($data['day_users']) ? $data['day_users'] : [];
        $dates     = isset($data['dates']) && is_array($data['dates']) ? $data['dates'] : [];
        $snapshots = isset($data['reservation_snapshot']) && is_array($data['reservation_snapshot'])
            ? $data['reservation_snapshot']
            : [];
        $roomValidation = $this->validator->roomError([(int)$roomId]);
        if ($roomValidation !== null) {
            return [
                'ok' => false,
                'message' => $roomValidation,
            ];
        }
        // 操作者自身が有効に所属している部屋でなければ、何も書かずに拒否する（管理者も同じ。#821）。
        // 先に弾かないと、既存予約のスキップ通知で所属外の部屋の利用者名を返してしまう。
        if (!$this->rooms->isActiveMember($userId, (int)$roomId)) {
            return [
                'ok' => false,
                'message' => '所属していない部屋には登録できません。',
            ];
        }
        $candidateDates = !empty($dayUsers) ? array_keys($dayUsers) : array_keys(array_filter($dates));
        $dateValidation = $this->validator->dateError($candidateDates, false);
        if ($dateValidation !== null) {
            return [
                'ok' => false,
                'message' => $dateValidation,
            ];
        }

        $conflict = $this->support->conflictingSnapshotDate((int)$roomId, $dayUsers, $snapshots);
        if ($conflict !== null) {
            return [
                'ok' => false,
                'message' => '他のユーザーが同じ日付の予約を更新しました。最新の状態を読み込み直してください。',
                'conflict_date' => $conflict,
            ];
        }

        $plan = !empty($dayUsers)
            ? $this->planGroupDayUsers($input, (int)$roomId, $dayUsers)
            : $this->planGroupUsers($input, (int)$roomId, $dates);
        if (isset($plan['ok'])) {
            return $plan;
        }

        return $plan + [
            'deactivate' => [],
            'swapped'    => [],
            'roomId'     => $roomId,
            'dayUsers'   => $dayUsers,
            'users'      => isset($data['users']) && is_array($data['users']) ? $data['users'] : [],
            'dates'      => $dates,
        ];
    }

    /**
     * 部屋単位の一括登録（day_users: 日付 => 利用者 => 食事 => 0/1。登録と取消）。
     *
     * @param array<array-key, mixed> $dayUsers
     * @return array<string, mixed> 結果（ok=false）または書く内容
     */
    private function planGroupDayUsers(BulkAddReservationsInput $input, int $roomId, array $dayUsers): array
    {
        $userId           = $input->userId;
        $reservations     = [];
        $skippedMessages  = [];
        $rowsToActivate   = [];
        $rowsToDeactivate = [];

        $dates   = array_keys($dayUsers);
        $userIds = [];
        foreach ($dayUsers as $usersByDate) {
            if (!is_array($usersByDate)) {
                continue;
            }
            foreach ($usersByDate as $targetUserId => $_) {
                $userIds[(int)$targetUserId] = true;
            }
        }
        $userIds = array_keys($userIds);

        $today = new ChronosDate($this->clock->now());
        ['active' => $existingActiveMap, 'byRoom' => $existingRoomMap] = $this->existingMaps($dates, $userIds, $today);

        $userNameMap      = $this->users->findNames($userIds);
        $userLevelMapBulk = array_map(static fn(UserRoleInfo $info): int => (int)$info->userLevel, $this->users->findRoleInfos($userIds));

        $isLoginStaff      = UserRole::isStaffLevel($input->loginUserLevel);
        $blockLeaderInRoom = $input->isBlockLeader && $this->rooms->isActiveMember($userId, $roomId);

        foreach ($dayUsers as $date => $usersByDate) {
            if (!is_array($usersByDate)) {
                continue;
            }
            foreach ($usersByDate as $targetUserId => $mealData) {
                if (!is_array($mealData)) {
                    continue;
                }
                // 画面は部屋内全ユーザーの既存予約を送信するため、
                // 権限チェックは実際に書き込みが発生する行に限定する
                $targetLevel   = (int)($userLevelMapBulk[(int)$targetUserId] ?? 0);
                $canEditTarget = $this->support->canEditTargetUser((int)$targetUserId, $userId, $input->isAdmin, $isLoginStaff, $targetLevel, $blockLeaderInRoom, $roomId);
                // 取消（チェックOFF）は、対象が退出済みでも行えるよう所属を要求しない
                $canCancelTarget = $this->support->canEditTargetUser((int)$targetUserId, $userId, $input->isAdmin, $isLoginStaff, $targetLevel, $blockLeaderInRoom, $roomId, true);
                foreach ($mealData as $mealType => $checked) {
                    $mealType = (int)$mealType;
                    if (MealType::tryFrom($mealType) === null) {
                        continue;
                    }

                    // チェックOFF: 既存の有効予約を非活性化（eat_flag=0, i_change_flag=0）
                    // 後続行の権限拒否時に部分更新が残らないよう、書き込みは
                    // 走査完了後のトランザクション内でまとめて行う
                    if (!(int)$checked) {
                        // 取消は、認可した部屋（$roomId）の行だけを対象にする（他部屋の予約を巻き込まない）。
                        // 同じ利用者・日付・食事の有効行が複数の部屋にあるときは、その部屋の行を引き直す。
                        $activeRow = $existingRoomMap[$date][(int)$targetUserId][$mealType][$roomId] ?? null;
                        if ($activeRow !== null && !$activeRow->isEffective($today)) {
                            $activeRow = null;
                        }
                        if ($activeRow === null) {
                            continue;
                        }
                        if (!$canCancelTarget) {
                            return [
                                'ok'      => false,
                                'message' => self::NO_PERMISSION_MESSAGE,
                            ];
                        }
                        // 日ごとのボタン（ToggleMealUseCase）と同じく、直前期間の職員の予約は管理者しか取り消せない
                        if (ChangeEditPermission::isStaffLockedInLastMinute($input->isAdmin, ReservationDateRule::isLastMinuteWindow(new ChronosDate((string)$date), $today), $targetLevel)) {
                            return [
                                'ok'      => false,
                                'message' => self::STAFF_LAST_MINUTE_CANCEL_MESSAGE,
                            ];
                        }
                        $rowsToDeactivate[implode(':', [$date, (int)$targetUserId, $mealType, $activeRow->roomId])] = $activeRow;
                        continue;
                    }

                    $disp = MealType::labels(MealType::LABEL_SHORT_NIGHT)[$mealType];

                    $activeRow = $existingActiveMap[$date][(int)$targetUserId][$mealType] ?? null;
                    $roomRow   = $existingRoomMap[$date][(int)$targetUserId][$mealType][$roomId] ?? null;
                    if ($activeRow !== null) {
                        $skippedMessages[] = sprintf(
                            '日付 %s "%s"は %s の予約が既に存在していたためスキップしました。',
                            $date,
                            $disp,
                            $userNameMap[(int)$targetUserId] ?? '不明なユーザー'
                        );
                        continue;
                    }
                    if (!$canEditTarget) {
                        return [
                            'ok'      => false,
                            'message' => self::NO_PERMISSION_MESSAGE,
                        ];
                    }
                    if ($roomRow !== null) {
                        $rowsToActivate[implode(':', [$date, (int)$targetUserId, $mealType, $roomId])] = $roomRow;
                        continue;
                    }
                    // 直前期間（今日〜14日後）の新しい行は、当初の予定（eat_flag）を 0 にし、直前の変更（i_change_flag）だけ 1 にする
                    $eatFlag = ReservationDateRule::isLastMinuteWindow(new ChronosDate((string)$date), $today) ? 0 : 1;
                    $reservations[] = new MealReservation((int)$targetUserId, (string)$date, $roomId, $mealType, $eatFlag, 1);
                }
            }
        }

        return [
            'create'        => $reservations,
            'activate'      => $rowsToActivate,
            'deactivate'    => $rowsToDeactivate,
            'skipped'       => $skippedMessages,
            'selectedDates' => [],
        ];
    }

    /**
     * 部屋単位の一括登録（古い画面: users: 利用者 => [morning|noon|night|bento => 1] を、選んだ日付すべてに登録）。
     *
     * @param array<array-key, mixed> $dates 日付 => 選んだか
     * @return array<string, mixed> 結果（ok=false）または書く内容
     */
    private function planGroupUsers(BulkAddReservationsInput $input, int $roomId, array $dates): array
    {
        $userId          = $input->userId;
        $reservations    = [];
        $skippedMessages = [];
        $rowsToActivate  = [];

        $users   = isset($input->data['users']) && is_array($input->data['users']) ? $input->data['users'] : [];
        // 利用者キーは day_users と同じく int にそろえる（数字でないキーをそのまま DB の検索に渡すと 500 になる。#989）
        $userIds = array_values(array_unique(array_map('intval', array_keys($users))));
        $selectedDates = [];
        foreach ($dates as $date => $checkedDate) {
            if ($checkedDate) {
                $selectedDates[] = $date;
            }
        }

        $today = new ChronosDate($this->clock->now());
        ['active' => $existingActiveMap, 'byRoom' => $existingRoomMap] = $this->existingMaps($selectedDates, $userIds, $today);

        $userNameMap      = $this->users->findNames($userIds);
        $userLevelMapBulk = array_map(static fn(UserRoleInfo $info): int => (int)$info->userLevel, $this->users->findRoleInfos($userIds));

        $isLoginStaff      = UserRole::isStaffLevel($input->loginUserLevel);
        $blockLeaderInRoom = $input->isBlockLeader && $this->rooms->isActiveMember($userId, $roomId);
        foreach ($dates as $date => $checkedDate) {
            if (!$checkedDate) {
                continue;
            }
            foreach ($users as $targetUserId => $mealData) {
                // 画面は部屋内全ユーザーの既存予約を送信するため、
                // 権限チェックは実際に書き込みが発生する行に限定する
                $canEditTarget = $this->support->canEditTargetUser(
                    (int)$targetUserId,
                    $userId,
                    $input->isAdmin,
                    $isLoginStaff,
                    (int)($userLevelMapBulk[(int)$targetUserId] ?? 0),
                    $blockLeaderInRoom,
                    $roomId
                );
                foreach (self::MEAL_TIME_MAP as $mealTime => $mealType) {
                    if (!isset($mealData[$mealTime]) || (int)$mealData[$mealTime] !== 1) {
                        continue;
                    }
                    $disp      = MealType::labels(MealType::LABEL_SHORT_NIGHT)[$mealType];
                    $activeRow = $existingActiveMap[$date][(int)$targetUserId][$mealType] ?? null;
                    $roomRow   = $existingRoomMap[$date][(int)$targetUserId][$mealType][$roomId] ?? null;
                    if ($activeRow !== null) {
                        $skippedMessages[] = sprintf(
                            '日付 %s "%s"は %s の予約が既に存在していたためスキップしました。',
                            $date,
                            $disp,
                            $userNameMap[(int)$targetUserId] ?? '不明なユーザー'
                        );
                        continue;
                    }
                    if (!$canEditTarget) {
                        return [
                            'ok'      => false,
                            'message' => self::NO_PERMISSION_MESSAGE,
                        ];
                    }
                    if ($roomRow !== null) {
                        $rowsToActivate[implode(':', [$date, (int)$targetUserId, $mealType, $roomId])] = $roomRow;
                        continue;
                    }
                    // 直前期間（今日〜14日後）の新しい行は、当初の予定（eat_flag）を 0 にし、直前の変更（i_change_flag）だけ 1 にする
                    $eatFlag = ReservationDateRule::isLastMinuteWindow(new ChronosDate((string)$date), $today) ? 0 : 1;
                    $reservations[] = new MealReservation((int)$targetUserId, (string)$date, $roomId, $mealType, $eatFlag, 1);
                }
            }
        }

        return [
            'create'        => $reservations,
            'activate'      => $rowsToActivate,
            'skipped'       => $skippedMessages,
            'selectedDates' => [],
        ];
    }

    /**
     * 利用者・日付の既存予約を、有効な行（日付・利用者・食事ごと）と部屋ごとの行の2種のマップにする。
     *
     * @param list<array-key> $dates 検証済みの日付（Y-m-d）
     * @param array<array-key, mixed> $userIds
     * @return array{active: array<string, array<int, array<int, MealReservation>>>, byRoom: array<string, array<int, array<int, array<int, MealReservation>>>>}
     */
    private function existingMaps(array $dates, array $userIds, ChronosDate $today): array
    {
        $active = [];
        $byRoom = [];
        foreach ($this->reservations->findByUsersAndDates(array_map('strval', $dates), $userIds, MealType::values()) as $row) {
            $byRoom[$row->date][$row->userId][$row->mealType][$row->roomId] = $row;
            if ($row->isEffective($today)) {
                $active[$row->date][$row->userId][$row->mealType] = $row;
            }
        }

        return ['active' => $active, 'byRoom' => $byRoom];
    }
}
