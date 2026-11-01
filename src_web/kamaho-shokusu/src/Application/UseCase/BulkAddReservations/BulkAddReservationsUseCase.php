<?php
declare(strict_types=1);

namespace App\Application\UseCase\BulkAddReservations;

use App\Application\Port\ReservationCacheInterface;
use App\Application\Port\ReservationDatePolicyInterface;
use App\Application\Port\TransactionManagerInterface;
use App\Application\UseCase\BulkReservation\BulkReservationSupport;
use App\Domain\Entity\MealReservation;
use App\Domain\Exception\ConflictException;
use App\Domain\Exception\ReservationValidationException;
use App\Domain\Repository\ReservationRepositoryInterface;
use App\Domain\Repository\RoomMembershipRepositoryInterface;
use App\Domain\Repository\UserRepositoryInterface;
use App\Domain\ValueObject\MealType;
use App\Domain\ValueObject\UserRole;
use App\Domain\ValueObject\UserRoleInfo;
use Cake\Chronos\ChronosDate;
use Psr\Log\LoggerInterface;

/**
 * 一括登録を保存する。
 *
 * - personal: 自分の予約を、複数日 × 食事 × 部屋でまとめて登録する（通常予約の日付ルールを守る）。
 * - group: 部屋の利用者の予約を、複数日 × 食事でまとめて登録・取消する（day_users）。古い画面は users で登録だけを送る。
 * すでに有効な予約はスキップして伝える。昼食⇔弁当は、相手のチェックを明示的に外したときだけ入れ替える。
 */
final class BulkAddReservationsUseCase
{
    private const MEAL_TIME_MAP = [
        1 => 'morning',
        2 => 'noon',
        3 => 'night',
        4 => 'bento',
    ];

    private const MEAL_TIME_DISPLAY_NAMES = [
        'morning' => '朝',
        'noon' => '昼',
        'night' => '夜',
        'bento' => '弁当',
    ];

    private const NO_PERMISSION_MESSAGE = '他ユーザーの予約を更新する権限がありません。';

    private const SAVE_ERROR_MESSAGE = '予約の保存中にエラーが発生しました。';

    public function __construct(
        private readonly ReservationRepositoryInterface $reservations,
        private readonly RoomMembershipRepositoryInterface $rooms,
        private readonly UserRepositoryInterface $users,
        private readonly TransactionManagerInterface $transaction,
        private readonly ReservationCacheInterface $cache,
        private readonly ReservationDatePolicyInterface $datePolicy,
        private readonly BulkReservationSupport $support,
        private readonly LoggerInterface $logger,
        /** 過去日を選んだときの文言（設定 App.messages.pastDateUnavailable） */
        private readonly string $pastDateMessage = '過去日の内容はこの画面では表示できません。修正が必要な場合は管理者にお問い合わせください。',
    ) {}

    /**
     * @return array{ok: bool, message: string, redirect_url?: string, conflict_date?: string}
     * @throws \RuntimeException 保存前の読み取りに失敗した場合
     */
    public function execute(BulkAddReservationsInput $input): array
    {
        $data            = $input->data;
        $userId          = $input->userId;
        $reservationType = $data['reservation_type'] ?? null;
        if (!$reservationType) {
            return [
                'ok' => false,
                'message' => '予約タイプが選択されていません。',
            ];
        }

        // ログインユーザーを特定できない場合は権限判定できないため拒否する（fail-closed）
        if ($userId <= 0) {
            return [
                'ok'      => false,
                'message' => 'ログインユーザーを特定できないため処理を中断しました。',
            ];
        }

        $plan = match ($reservationType) {
            'personal' => $this->planPersonal($input),
            'group'    => $this->planGroup($input),
            default    => [
                'ok' => false,
                'message' => '無効な予約タイプが選択されました。',
            ],
        };
        if (isset($plan['ok'])) {
            return $plan;
        }

        $saveError = $this->save($plan, $input->userName);
        if ($saveError !== null) {
            return $saveError;
        }

        $this->forgetCaches($reservationType, $plan, $userId);

        return [
            'ok' => true,
            'message' => ($plan['skipped'] !== []
                ? "一部の予約は既に存在していたためスキップされました。\n" . implode("\n", $plan['skipped'])
                : 'すべての予約が正常に登録されました。')
                . ($plan['swapped'] !== [] ? "\n" . implode("\n", $plan['swapped']) : ''),
            'redirect_url' => './',
        ];
    }

    /**
     * 個人の一括登録で書く行を決める。登録できない理由があれば ok=false の結果を返す。
     *
     * @return array<string, mixed> 結果（ok=false）または書く内容
     */
    private function planPersonal(BulkAddReservationsInput $input): array
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
        $roomValidation    = $this->validateRoomIds($selectedRoomIds);
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
        $dateValidation = $this->validateNormalReservationDates($selectedDates, true);
        if ($dateValidation !== null) {
            return [
                'ok' => false,
                'message' => $dateValidation,
            ];
        }

        $today             = ChronosDate::today('Asia/Tokyo');
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
                            self::MEAL_TIME_DISPLAY_NAMES[self::MEAL_TIME_MAP[$mealTypeInt]],
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

    /**
     * 部屋単位の一括登録で書く行を決める。登録できない理由があれば ok=false の結果を返す。
     *
     * @return array<string, mixed> 結果（ok=false）または書く内容
     */
    private function planGroup(BulkAddReservationsInput $input): array
    {
        $data   = $input->data;
        $userId = $input->userId;

        $roomId    = $data['i_id_room'] ?? null;
        $dayUsers  = isset($data['day_users']) && is_array($data['day_users']) ? $data['day_users'] : [];
        $dates     = isset($data['dates']) && is_array($data['dates']) ? $data['dates'] : [];
        $snapshots = isset($data['reservation_snapshot']) && is_array($data['reservation_snapshot'])
            ? $data['reservation_snapshot']
            : [];
        $roomValidation = $this->validateRoomIds([(int)$roomId]);
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
        $dateValidation = $this->validateNormalReservationDates($candidateDates, false);
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

        $today = ChronosDate::today('Asia/Tokyo');
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
                        $rowsToDeactivate[implode(':', [$date, (int)$targetUserId, $mealType, $activeRow->roomId])] = $activeRow;
                        continue;
                    }

                    $disp = self::MEAL_TIME_DISPLAY_NAMES[self::MEAL_TIME_MAP[$mealType] ?? ''] ?? '食事';

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
                    $reservations[] = new MealReservation((int)$targetUserId, (string)$date, $roomId, $mealType, 1, 1);
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
        $userIds = array_keys($users);
        $selectedDates = [];
        foreach ($dates as $date => $checkedDate) {
            if ($checkedDate) {
                $selectedDates[] = $date;
            }
        }

        $today = ChronosDate::today('Asia/Tokyo');
        ['active' => $existingActiveMap, 'byRoom' => $existingRoomMap] = $this->existingMaps($selectedDates, $userIds, $today);

        $userNameMap      = $this->users->findNames($userIds);
        $userLevelMapBulk = array_map(static fn(UserRoleInfo $info): int => (int)$info->userLevel, $this->users->findRoleInfos($userIds));

        $isLoginStaff      = UserRole::isStaffLevel($input->loginUserLevel);
        $blockLeaderInRoom = $input->isBlockLeader && $this->rooms->isActiveMember($userId, $roomId);
        $mealTimeRevMap    = array_flip(self::MEAL_TIME_MAP);
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
                foreach (self::MEAL_TIME_DISPLAY_NAMES as $mealTime => $disp) {
                    if (!isset($mealData[$mealTime]) || (int)$mealData[$mealTime] !== 1) {
                        continue;
                    }
                    $mealType  = (int)$mealTimeRevMap[$mealTime];
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
                    $reservations[] = new MealReservation((int)$targetUserId, (string)$date, $roomId, $mealType, 1, 1);
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
     * @param array<array-key, mixed> $dates
     * @param array<array-key, mixed> $userIds
     * @return array{active: array<string, array<int, array<int, MealReservation>>>, byRoom: array<string, array<int, array<int, array<int, MealReservation>>>>}
     */
    private function existingMaps(array $dates, array $userIds, ChronosDate $today): array
    {
        $active = [];
        $byRoom = [];
        foreach ($this->reservations->findByUsersAndDates($dates, $userIds, MealType::values()) as $row) {
            $byRoom[$row->date][$row->userId][$row->mealType][$row->roomId] = $row;
            if ($row->isEffective($today)) {
                $active[$row->date][$row->userId][$row->mealType] = $row;
            }
        }

        return ['active' => $active, 'byRoom' => $byRoom];
    }

    /**
     * 取消 → 有効化 → 新規保存を1トランザクションで行う。失敗したら取り消して ok=false の結果を返す。
     *
     * @param array<string, mixed> $plan
     * @return array{ok: false, message: string}|null 成功なら null
     */
    private function save(array $plan, string $userName): ?array
    {
        if ($plan['deactivate'] === [] && $plan['activate'] === [] && $plan['create'] === []) {
            return null;
        }
        $this->transaction->begin();
        try {
            foreach ($plan['deactivate'] as $row) {
                if (!$this->reservations->updateWithVersion($row, 0, 0, $userName)) {
                    throw new \RuntimeException('optimistic_conflict');
                }
            }

            foreach ($plan['activate'] as $row) {
                if (!$this->reservations->updateWithVersion($row, 1, 1, $userName)) {
                    throw new \RuntimeException('optimistic_conflict');
                }
            }

            if ($plan['create'] !== []) {
                // 送られた部屋 ID が数でない（"1abc" など）ときは、従来は保存時の型変換で失敗していた。同じく保存の失敗にする
                if (!is_numeric($plan['roomId']) && !is_bool($plan['roomId'])) {
                    throw new \InvalidArgumentException('部屋 ID が数ではありません（' . get_debug_type($plan['roomId']) . '）。');
                }
                // 一括登録は、従来どおり入力の検証（型変換）をせずに保存する
                $this->reservations->addMany($plan['create'], $userName, false);
            }
            $this->transaction->commit();
        } catch (ConflictException $e) {
            // 承認済み・別の部屋の予約と重なる・昼食と弁当の同時予約（有効にする更新）
            $this->transaction->rollback();
            return [
                'ok' => false,
                'message' => $e->getMessage(),
            ];
        } catch (ReservationValidationException $e) {
            // 新しい行の検証ルール（昼食と弁当の同時予約など）に当たった。理由を返す
            $this->transaction->rollback();
            $this->logger->error('bulkAdd validation error: ' . $e->getMessage());
            return [
                'ok' => false,
                'message' => $e->lunchBentoMessage ?? self::SAVE_ERROR_MESSAGE,
            ];
        } catch (\RuntimeException $e) {
            $this->transaction->rollback();
            if ($e->getMessage() === 'optimistic_conflict') {
                return [
                    'ok' => false,
                    'message' => '他の操作と競合しました。画面を再読み込みして再実行してください。',
                ];
            }
            $this->logger->error('bulkAdd runtime error: ' . $e->getMessage());
            return [
                'ok' => false,
                'message' => self::SAVE_ERROR_MESSAGE,
            ];
        } catch (\Throwable $e) {
            $this->transaction->rollback();
            $this->logger->error('bulkAdd error: ' . $e->getMessage());
            return [
                'ok' => false,
                'message' => self::SAVE_ERROR_MESSAGE,
            ];
        }

        return null;
    }

    /**
     * 予約が変わった日付のキャッシュを捨てる（day_users の日付）。今日を含むなら、対象者の当日の報告済み印も捨てる。
     *
     * @param array<string, mixed> $plan
     */
    private function forgetCaches(string $reservationType, array $plan, int $userId): void
    {
        $dayUsers = $plan['dayUsers'];
        if (!empty($dayUsers)) {
            $this->support->forgetCachesForDates((int)$plan['roomId'], array_keys($dayUsers), $dayUsers);
        }

        $today         = ChronosDate::today('Asia/Tokyo')->format('Y-m-d');
        $targetDates   = [];
        $targetUserIds = [];
        if ($reservationType === 'personal') {
            $targetDates   = $plan['selectedDates'];
            $targetUserIds = [$userId];
        }
        if ($reservationType === 'group' && !empty($dayUsers)) {
            $targetDates = array_keys($dayUsers);
            foreach ($dayUsers as $usersByDate) {
                if (!is_array($usersByDate)) {
                    continue;
                }
                foreach ($usersByDate as $uid => $_) {
                    $targetUserIds[] = (int)$uid;
                }
            }
        }
        if ($reservationType === 'group' && empty($dayUsers) && !empty($plan['users']) && !empty($plan['dates'])) {
            foreach ($plan['dates'] as $dateKey => $checked) {
                if ($checked) {
                    $targetDates[] = $dateKey;
                }
            }
            $targetUserIds = array_map('intval', array_keys($plan['users']));
        }
        if (in_array($today, $targetDates, true)) {
            // 当日の報告済み印だけを捨てる（レポートのバージョンはここでは上げない。従来どおり）
            $this->cache->forgetDates([$today => array_values(array_unique(array_filter($targetUserIds)))], false);
        }
    }

    /**
     * 選んだ日付の検証。通らなければ理由、通れば null。
     *
     * @param array<array-key, mixed> $dates
     * @param bool $enforceNormalRule true なら通常予約の日付ルール（15日目以降）も守らせる
     */
    private function validateNormalReservationDates(array $dates, bool $enforceNormalRule): ?string
    {
        if (empty($dates)) {
            return '日付が選択されていません。';
        }

        foreach ($dates as $date) {
            if (!is_string($date) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
                return '無効な日付が含まれています。';
            }

            if ($this->datePolicy->isPastDate($date)) {
                return $this->pastDateMessage;
            }

            if ($enforceNormalRule) {
                $policyResult = $this->datePolicy->validateReservationDate($date);
                if ($policyResult !== true) {
                    return (string)$policyResult;
                }
            }
        }

        return null;
    }

    /**
     * 選んだ部屋の検証（存在し、削除されていないこと）。通らなければ理由、通れば null。
     *
     * @param list<int> $roomIds
     */
    private function validateRoomIds(array $roomIds): ?string
    {
        $roomIds = array_values(array_unique(array_filter(array_map('intval', $roomIds), static fn($v) => $v > 0)));
        if (empty($roomIds)) {
            return '部屋が選択されていません。';
        }

        foreach ($roomIds as $roomId) {
            if (!$this->rooms->isRoomAvailable($roomId)) {
                return '選択された部屋情報が不正です。画面を再読み込みして再実行してください。';
            }
        }

        return null;
    }
}
