<?php
declare(strict_types=1);

namespace App\Service;

use App\Domain\ValueObject\MealType;
use App\Domain\ValueObject\UserRole;
use App\Exception\ApprovedReservationException;
use App\Exception\DuplicateMealReservationException;
use App\Model\Table\TIndividualReservationInfoTable;
use Cake\Core\Configure;
use Cake\I18n\Date;
use Cake\I18n\DateTime;
use Cake\Cache\Cache;
use Cake\Log\Log;
use Cake\ORM\Exception\PersistenceFailedException;
use Cake\ORM\Table;
use Cake\ORM\TableRegistry;

class ReservationBulkService
{
    private ReservationDatePolicy $datePolicy;
    private RoomAccessService $roomAccessService;

    public function __construct(?ReservationDatePolicy $datePolicy = null, ?RoomAccessService $roomAccessService = null)
    {
        $this->datePolicy = $datePolicy ?? new ReservationDatePolicy();
        $this->roomAccessService = $roomAccessService ?? new RoomAccessService();
    }

    /**
     * 直前一括編集（部屋 × 日付 × 利用者 × 食事）を保存する。
     *
     * 画面は変わっていない行も送るので、数える値が変わる行だけを書く。
     * 次の行は送信全体を止めず、skipped に理由を積んで飛ばす（ok=true のまま返す）。
     *   - 管理者以外が、過去日の行を変えようとした
     *   - 管理者以外が、職員の直前期間の予約を取り消そうとした
     * 職員の直前期間の昼食⇔弁当の入れ替え、権限のない変更、競合、承認済み、昼食と弁当の同時予約は ok=false で返す。
     *
     * @return array{ok: bool, message?: string, updated?: int, created?: int, skipped?: list<string>, conflict_date?: string}
     * @throws \Cake\Database\Exception\DatabaseException 永続化層の照会に失敗した場合
     */
    public function processBulkChangeEdit(
        array $dayUsers,
        int $roomId,
        string $loginName,
        Table $reservationTable,
        Table $userTable,
        array $snapshots = [],
        int $loginUserId = 0,
        bool $isAdmin = false,
        int $loginUserLevel = 0,
        bool $isBlockLeader = false
    ): array {
        if (!$roomId || empty($dayUsers)) {
            return [
                'ok' => false,
                'message' => '部屋または予約内容が指定されていません。',
            ];
        }

        // ログインユーザーを特定できない場合は権限判定できないため拒否する（fail-closed）
        if ($loginUserId <= 0) {
            return [
                'ok'      => false,
                'message' => 'ログインユーザーを特定できないため処理を中断しました。',
            ];
        }

        // 存在しない・削除済みの部屋には、管理者でも書き込めない（管理者以外は有効な所属の判定で弾かれる）
        if ($isAdmin && !$this->roomAccessService->isRoomAvailable((int)$roomId)) {
            return [
                'ok'      => false,
                'message' => '選択された部屋情報が不正です。画面を再読み込みして再実行してください。',
            ];
        }

        // 過ぎた日（実績）は、管理者以外は書き換えさせない（数える値が i_change_flag のため、食数・控除表が変わる）。
        // 画面は変わっていない行も送り、保存しなかった過去日の選択が残ることもある。過去日は画面で直せないので、
        // 送信全体は止めず、実際に変わる行だけを理由付きで飛ばす
        $datePolicy = $this->datePolicy;

        $connection = $reservationTable->getConnection();
        $connection->begin();
        try {
            $updated = 0;
            $created = 0;
            $skipped = [];

            $conflict = $this->checkReservationSnapshots($reservationTable, $roomId, $dayUsers, $snapshots);
            if ($conflict !== null) {
                $connection->rollback();
                return [
                    'ok' => false,
                    'message' => '他のユーザーが同じ日付の予約を更新しました。最新の状態を読み込み直してください。',
                    'conflict_date' => $conflict,
                ];
            }

            $dates = array_keys($dayUsers);
            $userIds = [];
            foreach ($dayUsers as $users) {
                if (!is_array($users)) {
                    continue;
                }
                foreach ($users as $userIdRaw => $_) {
                    $userIds[(int)$userIdRaw] = true;
                }
            }
            $userIds = array_keys($userIds);

            $userLevels = [];
            if (!empty($userIds)) {
                $levelRows = $userTable->find()
                    ->select(['i_id_user', 'i_user_level'])
                    ->where(['i_id_user IN' => $userIds])
                    ->all();
                foreach ($levelRows as $row) {
                    $userLevels[(int)$row->i_id_user] = (int)$row->i_user_level;
                }
            }

            $isLoginStaff = UserRole::isStaffLevel($loginUserLevel);
            $blockLeaderInRoom = false;
            if ($isBlockLeader) {
                $blockLeaderInRoom = $this->isActiveMemberCached($loginUserId, (int)$roomId);
            }
            $existingMap = [];
            if (!empty($dates) && !empty($userIds)) {
                $existingRows = $reservationTable->find()
                    ->where([
                        'd_reservation_date IN' => $dates,
                        'i_id_user IN' => $userIds,
                        'i_id_room' => $roomId,
                        'i_reservation_type IN' => [1, 2, 3, 4],
                    ])
                    ->all();
                foreach ($existingRows as $row) {
                    $dateKey = is_object($row->d_reservation_date) ? $row->d_reservation_date->format('Y-m-d') : (string)$row->d_reservation_date;
                    $existingMap[$dateKey][(int)$row->i_id_user][(int)$row->i_reservation_type] = $row;
                }
            }

            $newRows = [];

            foreach ($dayUsers as $date => $users) {
                if (!is_array($users)) {
                    continue;
                }
                foreach ($users as $userIdRaw => $meals) {
                    $userId = (int)$userIdRaw;
                    if (!is_array($meals)) {
                        continue;
                    }
                    $targetUserLevel = $userLevels[$userId] ?? null;

                    // 取消を先に行う（昼食⇔弁当の入れ替えで、取り消す前の相手と「同時に有効」と判定されないようにする）
                    uasort($meals, static fn($a, $b): int => ((int)$a === 1) <=> ((int)$b === 1));
                    foreach ($meals as $mealTypeRaw => $checked) {
                        $mealType = (int)$mealTypeRaw;
                        if (!in_array($mealType, [1, 2, 3, 4], true)) {
                            continue;
                        }
                        $changeFlag = (int)$checked;

                        $existing = $existingMap[$date][$userId][$mealType] ?? null;

                        if ($existing) {
                            // 職員の直前取消は認めない（toggle・change-room と同じく、管理者は例外）
                            if (!$isAdmin && TIndividualReservationInfoTable::isLastMinute((string)$date)
                                && $targetUserLevel !== null && UserRole::isStaffLevel($targetUserLevel) && $changeFlag === 0
                            ) {
                                // 昼食⇔弁当の入れ替えは取消を伴うので、理由を返して止める
                                $opponent = MealType::counterpartValue((int)$mealType);
                                if ($opponent !== null && (int)($meals[$opponent] ?? 0) === 1 && TIndividualReservationInfoTable::isEffectiveRow($existing)) {
                                    $connection->rollback();
                                    return [
                                        'ok'      => false,
                                        'message' => "利用者ID {$userId} は職員のため、直前期間はお昼とお弁当を入れ替えられません（予約の取消になるため）。",
                                    ];
                                }
                                // 画面は変わっていない行も送るので、実際に取り消そうとした（今は食べる）ときだけ理由を返す
                                if ($datePolicy->countedValue((string)$date, $existing->eat_flag, $existing->i_change_flag) === 1) {
                                    $skipped[] = "{$date} の利用者ID {$userId} は職員のため、直前編集でのキャンセルはできません。";
                                }
                                continue;
                            }
                            // 15日以降は eat_flag で数えるので、eat_flag もそろえる（直前期間・過去日は i_change_flag だけを変える）
                            $isFar = !$datePolicy->shouldUseChangeFlag(new Date((string)$date));
                            // 数える値が変わる行だけを書く（i_change_flag が NULL の古い行を、変わっていないのに書き換えない）
                            if ($datePolicy->countedValue((string)$date, $existing->eat_flag, $existing->i_change_flag) !== $changeFlag) {
                                // 画面は部屋内全ユーザーの既存予約を送信するため、
                                // 権限チェックは値が実際に変わる行に限定する
                                // 取消（チェックOFF）は、対象が退出済みでも行えるよう所属を要求しない
                                if (!$this->canEditTargetUser($userId, $loginUserId, $isAdmin, $isLoginStaff, (int)($targetUserLevel ?? 0), $blockLeaderInRoom, $roomId, $changeFlag === 0)) {
                                    $connection->rollback();
                                    return [
                                        'ok'      => false,
                                        'message' => '他ユーザーの予約を更新する権限がありません。',
                                    ];
                                }
                                // 権限の判定のあとで見る（権限のない操作を「過去日」として ok にしない）
                                if (!$isAdmin && $datePolicy->isPastDate((string)$date)) {
                                    $skipped[] = "{$date} は過去日のため、利用者ID {$userId} の予約は変更できません。";
                                    continue;
                                }
                                $ok = $this->updateReservationRowWithVersion($reservationTable, $existing, [
                                    'i_change_flag' => $changeFlag,
                                    'c_update_user' => $loginName,
                                    'dt_update' => DateTime::now('Asia/Tokyo'),
                                ] + ($isFar ? ['eat_flag' => $changeFlag] : []));
                                if (!$ok) {
                                    throw new \RuntimeException('optimistic_conflict');
                                }
                                $updated++;
                            }
                        } else {
                            if ($changeFlag === 0) {
                                continue;
                            }
                            if (!$this->canEditTargetUser($userId, $loginUserId, $isAdmin, $isLoginStaff, (int)($targetUserLevel ?? 0), $blockLeaderInRoom, $roomId)) {
                                $connection->rollback();
                                return [
                                    'ok'      => false,
                                    'message' => '他ユーザーの予約を更新する権限がありません。',
                                ];
                            }
                            // 権限の判定のあとで見る（権限のない操作を「過去日」として ok にしない）
                            if (!$isAdmin && $datePolicy->isPastDate((string)$date)) {
                                $skipped[] = "{$date} は過去日のため、利用者ID {$userId} の予約は変更できません。";
                                continue;
                            }
                            $newRows[] = [
                                'i_id_user'          => $userId,
                                'd_reservation_date' => $date,
                                'i_reservation_type' => $mealType,
                                'i_id_room'          => $roomId,
                                // 15日以降は eat_flag で数えるので 1 にする（直前期間・過去日は当初予定なしの 0）
                                'eat_flag'           => $datePolicy->shouldUseChangeFlag(new Date((string)$date)) ? 0 : 1,
                                'i_change_flag'      => 1,
                                'i_version'          => 1,
                                'c_create_user'      => $loginName,
                                'dt_create'          => DateTime::now('Asia/Tokyo'),
                            ];
                            $created++;
                        }
                    }
                }
            }

            if (!empty($newRows)) {
                foreach (array_chunk($newRows, 500) as $chunk) {
                    $entities = $reservationTable->newEntities($chunk);
                    $reservationTable->saveManyOrFail($entities);
                }
            }

            $connection->commit();
            $this->invalidateCachesForDates((int)$roomId, array_keys($dayUsers), $dayUsers);
            return [
                'ok' => true,
                'updated' => $updated,
                'created' => $created,
                'skipped' => array_values(array_unique($skipped)),
            ];
        } catch (ApprovedReservationException | DuplicateMealReservationException $e) {
            $connection->rollback();
            return [
                'ok' => false,
                'message' => $e->getMessage(),
            ];
        } catch (PersistenceFailedException $e) {
            // 新しい行の検証ルール（昼食と弁当の同時予約など）に当たった。理由を返す
            $connection->rollback();
            Log::error('bulkChangeEditSubmit validation error: ' . json_encode($e->getEntity()->getErrors(), JSON_UNESCAPED_UNICODE));
            return [
                'ok' => false,
                'message' => TIndividualReservationInfoTable::lunchBentoViolation($e) ?? '直前編集の保存中にエラーが発生しました。',
            ];
        } catch (\RuntimeException $e) {
            $connection->rollback();
            if ($e->getMessage() === 'optimistic_conflict') {
                return [
                    'ok' => false,
                    'message' => '他の操作と競合しました。画面を再読み込みして再実行してください。',
                ];
            }
            Log::error('bulkChangeEditSubmit runtime error: ' . $e->getMessage());
            return [
                'ok' => false,
                'message' => '直前編集の保存中にエラーが発生しました。',
            ];
        } catch (\Throwable $e) {
            $connection->rollback();
            Log::error('bulkChangeEditSubmit error: ' . $e->getMessage());
            return [
                'ok' => false,
                'message' => '直前編集の保存中にエラーが発生しました。',
            ];
        }
    }

    public function processBulkAdd(
        array $data,
        int $userId,
        string $userName,
        Table $reservationTable,
        Table $userTable,
        Table $roomTable,
        bool $isAdmin = false,
        int $loginUserLevel = 0,
        bool $isBlockLeader = false
    ): array {
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

        $reservations = [];
        $mealTimeMap = [
            1 => 'morning',
            2 => 'noon',
            3 => 'night',
            4 => 'bento',
        ];
        $mealTimeRevMap = array_flip($mealTimeMap);
        $mealTimeDisplayNames = [
            'morning' => '朝',
            'noon' => '昼',
            'night' => '夜',
            'bento' => '弁当',
        ];
        $skippedMessages = [];
        $swappedMessages = []; // 昼食⇔弁当の入れ替えで取り消した予約（黙って取り消さず、応答で伝える）
        $rowsToActivate = [];
        $rowsToDeactivate = [];
        $dayUsers = [];
        $roomId = 0;
        $users = [];
        $dates = [];
        $selectedDates = [];

        if ($reservationType === 'personal') {
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
            $selectedRoomIds = [];
            foreach ($meals as $mealType => $roomList) {
                foreach ($roomList as $roomId => $checkedMeal) {
                    if ($checkedMeal) {
                        $selectedMealTypes[(int)$mealType] = true;
                        $selectedRoomIds[(int)$roomId] = true;
                    }
                }
            }
            $selectedMealTypes = array_keys($selectedMealTypes);
            $selectedRoomIds = array_keys($selectedRoomIds);
            $roomValidation = $this->validateRoomIds($roomTable, $selectedRoomIds);
            if ($roomValidation !== true) {
                return [
                    'ok' => false,
                    'message' => (string)$roomValidation,
                ];
            }
            // 自分が有効に所属している部屋にしか登録できない（管理者も同じ。#821）
            foreach ($selectedRoomIds as $selectedRoomId) {
                if (!$this->isActiveMemberCached($userId, (int)$selectedRoomId)) {
                    return [
                        'ok' => false,
                        'message' => '所属していない部屋には登録できません。',
                    ];
                }
            }
            $dateValidation = $this->validateNormalReservationDates($selectedDates, true);
            if ($dateValidation !== true) {
                return [
                    'ok' => false,
                    'message' => (string)$dateValidation,
                ];
            }

            $existingActiveMap = [];
            $existingRoomMap = [];
            if (!empty($selectedDates) && !empty($selectedMealTypes)) {
                $existingRows = $reservationTable->find()
                    ->enableAutoFields(false)
                    ->select(['i_id_user', 'd_reservation_date', 'i_reservation_type', 'i_id_room', 'eat_flag', 'i_change_flag', 'i_version'])
                    ->where([
                        'd_reservation_date IN' => $selectedDates,
                        // 昼食と弁当を入れ替えるときに相手の行も要るので、どちらかを選んだら両方を読む
                        'i_reservation_type IN' => array_values(array_unique(array_merge(
                            $selectedMealTypes,
                            array_intersect($selectedMealTypes, MealType::EXCLUSIVE_VALUES) !== [] ? MealType::EXCLUSIVE_VALUES : []
                        ))),
                        'i_id_user' => $userId,
                    ])
                    ->all();
                foreach ($existingRows as $row) {
                    $dateKey = is_object($row->d_reservation_date) ? $row->d_reservation_date->format('Y-m-d') : (string)$row->d_reservation_date;
                    $mealTypeKey = (int)$row->i_reservation_type;
                    $roomKey = (int)$row->i_id_room;
                    $existingRoomMap[$dateKey][$mealTypeKey][$roomKey] = $row;
                    if (TIndividualReservationInfoTable::isEffectiveRow($row)) {
                        $existingActiveMap[$dateKey][$mealTypeKey] = $row;
                    }
                }
            }

            $roomNameMap = [];
            if (!empty($selectedRoomIds)) {
                $roomRows = $roomTable->find()
                    ->enableAutoFields(false)
                    ->select(['i_id_room', 'c_room_name'])
                    ->where(['i_id_room IN' => $selectedRoomIds])
                    ->all();
                foreach ($roomRows as $row) {
                    $roomNameMap[(int)$row->i_id_room] = $row->c_room_name;
                }
            }

            foreach ($dates as $date => $checkedDate) {
                if (!$checkedDate) {
                    continue;
                }
                foreach ($meals as $mealType => $roomList) {
                    $mealType = (string)$mealType;
                    foreach ($roomList as $roomId => $checkedMeal) {
                        if (!$checkedMeal) {
                            continue;
                        }
                        $mealTypeInt = (int)$mealType;
                        $roomIdInt = (int)$roomId;
                        $activeRow = $existingActiveMap[$date][$mealTypeInt] ?? null;
                        $roomRow = $existingRoomMap[$date][$mealTypeInt][$roomIdInt] ?? null;
                        if ($activeRow !== null) {
                            $roomName = $roomNameMap[(int)$roomId] ?? '';
                            $skippedMessages[] = sprintf(
                                '日付 %s（%s）の"%s"は %s の予約が既に存在していたためスキップしました。',
                                $date,
                                $roomName,
                                $mealTimeDisplayNames[$mealTimeMap[$mealTypeInt]],
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
                                if (TIndividualReservationInfoTable::isEffectiveRow($oppRow)) {
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
                            $activateKey = implode(':', [$date, $userId, $mealTypeInt, $roomIdInt]);
                            $rowsToActivate[$activateKey] = $roomRow;
                            continue;
                        }
                        $reservation = $reservationTable->newEmptyEntity();
                        $reservation->d_reservation_date = $date;
                        $reservation->i_id_room = $roomIdInt;
                        $reservation->i_reservation_type = $mealTypeInt;
                        $reservation->i_id_user = $userId;
                        $reservation->eat_flag = 1;
                        $reservation->i_change_flag = 1;
                        $reservation->i_version = 1;
                        $reservation->c_create_user = $userName;
                        $reservation->dt_create = date('Y-m-d H:i:s');
                        $reservations[] = $reservation;
                    }
                }
            }
        } elseif ($reservationType === 'group') {
            $roomId = $data['i_id_room'] ?? null;
            $dayUsers = isset($data['day_users']) && is_array($data['day_users']) ? $data['day_users'] : [];
            $dates = isset($data['dates']) && is_array($data['dates']) ? $data['dates'] : [];
            $snapshots = isset($data['reservation_snapshot']) && is_array($data['reservation_snapshot'])
                ? $data['reservation_snapshot']
                : [];
            $roomValidation = $this->validateRoomIds($roomTable, [(int)$roomId]);
            if ($roomValidation !== true) {
                return [
                    'ok' => false,
                    'message' => (string)$roomValidation,
                ];
            }
            // 操作者自身が有効に所属している部屋でなければ、何も書かずに拒否する（管理者も同じ。#821）。
            // 先に弾かないと、既存予約のスキップ通知で所属外の部屋の利用者名を返してしまう。
            if (!$this->isActiveMemberCached($userId, (int)$roomId)) {
                return [
                    'ok' => false,
                    'message' => '所属していない部屋には登録できません。',
                ];
            }
            $candidateDates = !empty($dayUsers) ? array_keys($dayUsers) : array_keys(array_filter($dates));
            $dateValidation = $this->validateNormalReservationDates($candidateDates, false);
            if ($dateValidation !== true) {
                return [
                    'ok' => false,
                    'message' => (string)$dateValidation,
                ];
            }

            $conflict = $this->checkReservationSnapshots($reservationTable, (int)$roomId, $dayUsers, $snapshots);
            if ($conflict !== null) {
                return [
                    'ok' => false,
                    'message' => '他のユーザーが同じ日付の予約を更新しました。最新の状態を読み込み直してください。',
                    'conflict_date' => $conflict,
                ];
            }

            $existingActiveMap = [];
            $existingRoomMap = [];
            $userNameMap = [];
            if (!empty($dayUsers)) {
                $dates = array_keys($dayUsers);
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

                if (!empty($dates) && !empty($userIds)) {
                    $rows = $reservationTable->find()
                        ->enableAutoFields(false)
                        ->select(['i_id_user', 'd_reservation_date', 'i_reservation_type', 'i_id_room', 'eat_flag', 'i_change_flag', 'i_version'])
                        ->where([
                            'd_reservation_date IN' => $dates,
                            'i_id_user IN' => $userIds,
                            'i_reservation_type IN' => [1, 2, 3, 4],
                        ])
                        ->all();
                    foreach ($rows as $row) {
                        $dateKey = is_object($row->d_reservation_date) ? $row->d_reservation_date->format('Y-m-d') : (string)$row->d_reservation_date;
                        $uid = (int)$row->i_id_user;
                        $mealTypeKey = (int)$row->i_reservation_type;
                        $roomKey = (int)$row->i_id_room;
                        $existingRoomMap[$dateKey][$uid][$mealTypeKey][$roomKey] = $row;
                        if (TIndividualReservationInfoTable::isEffectiveRow($row)) {
                            $existingActiveMap[$dateKey][$uid][$mealTypeKey] = $row;
                        }
                    }
                }

                $userLevelMapBulk = [];
                if (!empty($userIds)) {
                    $userRows = $userTable->find()
                        ->select(['i_id_user', 'c_user_name', 'i_user_level'])
                        ->where(['i_id_user IN' => $userIds])
                        ->all();
                    foreach ($userRows as $row) {
                        $userNameMap[(int)$row->i_id_user]    = $row->c_user_name;
                        $userLevelMapBulk[(int)$row->i_id_user] = (int)$row->i_user_level;
                    }
                }

                $isLoginStaff = UserRole::isStaffLevel($loginUserLevel);
                $blockLeaderInRoom = false;
                if ($isBlockLeader && $roomId !== null) {
                    $blockLeaderInRoom = $this->isActiveMemberCached($userId, (int)$roomId);
                }
            }

            if (!empty($dayUsers)) {
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
                        $canEditTarget = $this->canEditTargetUser(
                            (int)$targetUserId,
                            $userId,
                            $isAdmin,
                            $isLoginStaff,
                            (int)($userLevelMapBulk[(int)$targetUserId] ?? 0),
                            $blockLeaderInRoom,
                            $roomId !== null ? (int)$roomId : null
                        );
                        // 取消（チェックOFF）は、対象が退出済みでも行えるよう所属を要求しない
                        $canCancelTarget = $this->canEditTargetUser(
                            (int)$targetUserId,
                            $userId,
                            $isAdmin,
                            $isLoginStaff,
                            (int)($userLevelMapBulk[(int)$targetUserId] ?? 0),
                            $blockLeaderInRoom,
                            $roomId !== null ? (int)$roomId : null,
                            true
                        );
                        foreach ($mealData as $mealType => $checked) {
                            $mealType = (int)$mealType;
                            if (!in_array($mealType, [1, 2, 3, 4], true)) {
                                continue;
                            }

                            // チェックOFF: 既存の有効予約を非活性化（eat_flag=0, i_change_flag=0）
                            // 後続行の権限拒否時に部分更新が残らないよう、書き込みは
                            // 走査完了後のトランザクション内でまとめて行う
                            if (!(int)$checked) {
                                $activeRow = $existingActiveMap[$date][(int)$targetUserId][$mealType] ?? null;
                                // 取消は、認可した部屋（$roomId）の行だけを対象にする（他部屋の予約を巻き込まない）。
                                // 同じ利用者・日付・食事の有効行が複数の部屋にあるときは、その部屋の行を引き直す
                                // （$existingActiveMap は最後に読んだ行で上書きされるため、取消が空振りしてしまう）。
                                if ($roomId !== null) {
                                    $activeRow = $existingRoomMap[$date][(int)$targetUserId][$mealType][(int)$roomId] ?? null;
                                    if ($activeRow !== null && !TIndividualReservationInfoTable::isEffectiveRow($activeRow)) {
                                        $activeRow = null;
                                    }
                                }
                                if ($activeRow !== null) {
                                    if (!$canCancelTarget) {
                                        return [
                                            'ok'      => false,
                                            'message' => '他ユーザーの予約を更新する権限がありません。',
                                        ];
                                    }
                                    $deactivateKey = implode(':', [$date, (int)$targetUserId, $mealType, (int)$activeRow->i_id_room]);
                                    $rowsToDeactivate[$deactivateKey] = $activeRow;
                                }
                                continue;
                            }

                            $disp = $mealTimeDisplayNames[$mealTimeMap[$mealType] ?? ''] ?? '食事';

                            $activeRow = $existingActiveMap[$date][(int)$targetUserId][$mealType] ?? null;
                            $roomRow = $existingRoomMap[$date][(int)$targetUserId][$mealType][(int)$roomId] ?? null;
                            if ($activeRow !== null) {
                                $userNameDisp = $userNameMap[(int)$targetUserId] ?? '不明なユーザー';
                                $skippedMessages[] = sprintf(
                                    '日付 %s "%s"は %s の予約が既に存在していたためスキップしました。',
                                    $date,
                                    $disp,
                                    $userNameDisp
                                );
                                continue;
                            }
                            if (!$canEditTarget) {
                                return [
                                    'ok'      => false,
                                    'message' => '他ユーザーの予約を更新する権限がありません。',
                                ];
                            }
                            if ($roomRow !== null) {
                                $activateKey = implode(':', [$date, (int)$targetUserId, $mealType, (int)$roomId]);
                                $rowsToActivate[$activateKey] = $roomRow;
                                continue;
                            }
                            $reservation = $reservationTable->newEmptyEntity();
                            $reservation->d_reservation_date = $date;
                            $reservation->i_id_room = $roomId;
                            $reservation->i_reservation_type = $mealType;
                            $reservation->i_id_user = $targetUserId;
                            $reservation->eat_flag = 1;
                            $reservation->i_change_flag = 1;
                            $reservation->i_version = 1;
                            $reservation->c_create_user = $userName;
                            $reservation->dt_create = date('Y-m-d H:i:s');
                            $reservations[] = $reservation;
                        }
                    }
                }
            } else {
                $users = isset($data['users']) && is_array($data['users']) ? $data['users'] : [];
                $userIds = array_keys($users);
                $selectedDates = [];
                foreach ($dates as $date => $checkedDate) {
                    if ($checkedDate) {
                        $selectedDates[] = $date;
                    }
                }
                $existingActiveMap = [];
                $existingRoomMap = [];
                $userNameMap = [];
                if (!empty($selectedDates) && !empty($userIds)) {
                    $rows = $reservationTable->find()
                        ->enableAutoFields(false)
                        ->select(['i_id_user', 'd_reservation_date', 'i_reservation_type', 'i_id_room', 'eat_flag', 'i_change_flag', 'i_version'])
                        ->where([
                            'd_reservation_date IN' => $selectedDates,
                            'i_id_user IN' => $userIds,
                            'i_reservation_type IN' => array_values($mealTimeRevMap),
                        ])
                        ->all();
                    foreach ($rows as $row) {
                        $dateKey = is_object($row->d_reservation_date) ? $row->d_reservation_date->format('Y-m-d') : (string)$row->d_reservation_date;
                        $uid = (int)$row->i_id_user;
                        $mealTypeKey = (int)$row->i_reservation_type;
                        $roomKey = (int)$row->i_id_room;
                        $existingRoomMap[$dateKey][$uid][$mealTypeKey][$roomKey] = $row;
                        if (TIndividualReservationInfoTable::isEffectiveRow($row)) {
                            $existingActiveMap[$dateKey][$uid][$mealTypeKey] = $row;
                        }
                    }
                }
                $userLevelMapBulk = [];
                if (!empty($userIds)) {
                    $userRows = $userTable->find()
                        ->enableAutoFields(false)
                        ->select(['i_id_user', 'c_user_name', 'i_user_level'])
                        ->where(['i_id_user IN' => $userIds])
                        ->all();
                    foreach ($userRows as $row) {
                        $userNameMap[(int)$row->i_id_user]    = $row->c_user_name;
                        $userLevelMapBulk[(int)$row->i_id_user] = (int)$row->i_user_level;
                    }
                }

                $isLoginStaff = UserRole::isStaffLevel($loginUserLevel);
                $blockLeaderInRoom = false;
                if ($isBlockLeader && $roomId !== null) {
                    $blockLeaderInRoom = $this->isActiveMemberCached($userId, (int)$roomId);
                }
                foreach ($dates as $date => $checkedDate) {
                    if (!$checkedDate) {
                        continue;
                    }
                    foreach ($users as $targetUserId => $mealData) {
                        // 画面は部屋内全ユーザーの既存予約を送信するため、
                        // 権限チェックは実際に書き込みが発生する行に限定する
                        $canEditTarget = $this->canEditTargetUser(
                            (int)$targetUserId,
                            $userId,
                            $isAdmin,
                            $isLoginStaff,
                            (int)($userLevelMapBulk[(int)$targetUserId] ?? 0),
                            $blockLeaderInRoom,
                            $roomId !== null ? (int)$roomId : null
                        );
                        foreach ($mealTimeDisplayNames as $mealTime => $disp) {
                            if (isset($mealData[$mealTime]) && (int)$mealData[$mealTime] === 1) {
                                $mealType = (int)$mealTimeRevMap[$mealTime];
                                $activeRow = $existingActiveMap[$date][(int)$targetUserId][$mealType] ?? null;
                                $roomRow = $existingRoomMap[$date][(int)$targetUserId][$mealType][(int)$roomId] ?? null;
                                if ($activeRow !== null) {
                                    $userNameDisp = $userNameMap[(int)$targetUserId] ?? '不明なユーザー';
                                    $skippedMessages[] = sprintf(
                                        '日付 %s "%s"は %s の予約が既に存在していたためスキップしました。',
                                        $date,
                                        $disp,
                                        $userNameDisp
                                    );
                                    continue;
                                }
                                if (!$canEditTarget) {
                                    return [
                                        'ok'      => false,
                                        'message' => '他ユーザーの予約を更新する権限がありません。',
                                    ];
                                }
                                if ($roomRow !== null) {
                                    $activateKey = implode(':', [$date, (int)$targetUserId, $mealType, (int)$roomId]);
                                    $rowsToActivate[$activateKey] = $roomRow;
                                    continue;
                                }
                                $reservation = $reservationTable->newEmptyEntity();
                                $reservation->d_reservation_date = $date;
                                $reservation->i_id_room = $roomId;
                                $reservation->i_reservation_type = $mealType;
                                $reservation->i_id_user = $targetUserId;
                                $reservation->eat_flag = 1;
                                $reservation->i_change_flag = 1;
                                $reservation->i_version = 1;
                                $reservation->c_create_user = $userName;
                                $reservation->dt_create = date('Y-m-d H:i:s');
                                $reservations[] = $reservation;
                            }
                        }
                    }
                }
            }
        } else {
            return [
                'ok' => false,
                'message' => '無効な予約タイプが選択されました。',
            ];
        }

        if (!empty($rowsToDeactivate) || !empty($rowsToActivate) || !empty($reservations)) {
            $connection = $reservationTable->getConnection();
            $connection->begin();
            try {
                foreach ($rowsToDeactivate as $row) {
                    $ok = $this->updateReservationRowWithVersion($reservationTable, $row, [
                        'eat_flag'       => 0,
                        'i_change_flag'  => 0,
                        'c_update_user'  => $userName,
                        'dt_update'      => DateTime::now('Asia/Tokyo'),
                    ]);
                    if (!$ok) {
                        throw new \RuntimeException('optimistic_conflict');
                    }
                }

                foreach ($rowsToActivate as $row) {
                    $ok = $this->updateReservationRowWithVersion($reservationTable, $row, [
                        'eat_flag' => 1,
                        'i_change_flag' => 1,
                        'c_update_user' => $userName,
                        'dt_update' => DateTime::now('Asia/Tokyo'),
                    ]);
                    if (!$ok) {
                        throw new \RuntimeException('optimistic_conflict');
                    }
                }

                if (!empty($reservations)) {
                    foreach (array_chunk($reservations, 500) as $chunk) {
                        $reservationTable->saveManyOrFail($chunk);
                    }
                }
                $connection->commit();
            } catch (ApprovedReservationException | DuplicateMealReservationException $e) {
                $connection->rollback();
                return [
                    'ok' => false,
                    'message' => $e->getMessage(),
                ];
            } catch (PersistenceFailedException $e) {
                // 新しい行の検証ルール（昼食と弁当の同時予約など）に当たった。理由を返す
                $connection->rollback();
                Log::error('bulkAdd validation error: ' . json_encode($e->getEntity()->getErrors(), JSON_UNESCAPED_UNICODE));
                return [
                    'ok' => false,
                    'message' => TIndividualReservationInfoTable::lunchBentoViolation($e) ?? '予約の保存中にエラーが発生しました。',
                ];
            } catch (\RuntimeException $e) {
                $connection->rollback();
                if ($e->getMessage() === 'optimistic_conflict') {
                    return [
                        'ok' => false,
                        'message' => '他の操作と競合しました。画面を再読み込みして再実行してください。',
                    ];
                }
                Log::error('bulkAdd runtime error: ' . $e->getMessage());
                return [
                    'ok' => false,
                    'message' => '予約の保存中にエラーが発生しました。',
                ];
            } catch (\Throwable $e) {
                $connection->rollback();
                Log::error('bulkAdd error: ' . $e->getMessage());
                return [
                    'ok' => false,
                    'message' => '予約の保存中にエラーが発生しました。',
                ];
            }
        }

        if (!empty($dayUsers)) {
            $this->invalidateCachesForDates((int)$roomId, array_keys($dayUsers), $dayUsers);
        }

        // today_report cache invalidation for personal/group bulk add
        $today = \Cake\I18n\Date::today('Asia/Tokyo')->format('Y-m-d');
        $targetDates = [];
        $targetUserIds = [];
        if ($reservationType === 'personal') {
            $targetDates = $selectedDates ?? [];
            $targetUserIds = [$userId];
        } elseif ($reservationType === 'group') {
            if (!empty($dayUsers)) {
                $targetDates = array_keys($dayUsers);
                foreach ($dayUsers as $usersByDate) {
                    if (!is_array($usersByDate)) {
                        continue;
                    }
                    foreach ($usersByDate as $uid => $_) {
                        $targetUserIds[] = (int)$uid;
                    }
                }
            } elseif (!empty($users) && !empty($dates)) {
                foreach ($dates as $dateKey => $checked) {
                    if ($checked) {
                        $targetDates[] = $dateKey;
                    }
                }
                $targetUserIds = array_map('intval', array_keys($users));
            }
        }
        if (in_array($today, $targetDates, true)) {
            $targetUserIds = array_values(array_unique(array_filter($targetUserIds)));
            foreach ($targetUserIds as $uid) {
                Cache::delete(sprintf('today_report:%d:%s', (int)$uid, $today), 'default');
            }
        }

        return [
            'ok' => true,
            'message' => (!empty($skippedMessages)
                ? "一部の予約は既に存在していたためスキップされました。\n" . implode("\n", $skippedMessages)
                : "すべての予約が正常に登録されました。")
                . ($swappedMessages !== [] ? "\n" . implode("\n", $swappedMessages) : ''),
            'redirect_url' => './',
        ];
    }

    /**
     * ログインユーザーが対象ユーザーの予約を変更できるかを返す。
     *
     * 管理者・（自分自身 かつ 所属部屋）・（職員→子供）・対象部屋のブロック長のいずれかであれば変更可。
     * 自分自身宛てであっても、所属していない部屋の食数は書き換えられない。
     *
     * @param int      $targetUserId      変更対象ユーザーID
     * @param int      $loginUserId       ログインユーザーID
     * @param bool     $isAdmin           ログインユーザーが管理者かどうか
     * @param bool     $isLoginStaff      ログインユーザーが職員（i_user_level 0 or 7）かどうか
     * @param int      $targetUserLevel   対象ユーザーの i_user_level
     * @param bool     $blockLeaderInRoom 対象部屋に所属するブロック長かどうか
     * @param int|null $roomId            操作対象の部屋ID（null の場合は部屋検証を行わない）
     * @return bool
     */
    private function canEditTargetUser(
        int $targetUserId,
        int $loginUserId,
        bool $isAdmin,
        bool $isLoginStaff,
        int $targetUserLevel,
        bool $blockLeaderInRoom,
        ?int $roomId = null,
        bool $isCancel = false
    ): bool {
        if ($targetUserId === $loginUserId) {
            // 自分自身でも、有効に所属していない部屋への登録は操作させない。
            // 取消はここでは所属を要求しないが、グループ一括登録の入口と Policy（canAccessRoom）で
            // 操作者が有効所属していない部屋は先に拒否されるため、退出済みの部屋の自分の予約は取り消せない（#821 で確認済みの仕様）。
            return $isCancel || $roomId === null || $this->isActiveMemberCached($loginUserId, $roomId);
        }

        if ($roomId !== null) {
            // 他人の予約を扱うには、操作者本人が部屋に有効所属していること
            if (!$this->isActiveMemberCached($loginUserId, $roomId)) {
                return false;
            }
            // 登録は対象も有効所属していること。取消は、対象が退出済みでも行える。
            if (!$isCancel && !$this->isActiveMemberCached($targetUserId, $roomId)) {
                return false;
            }
        }

        // 管理者は所属部屋内なら対象の区分を問わず扱える（所属外の部屋は上の所属判定で弾く。#821）
        return $isAdmin || ($isLoginStaff && $targetUserLevel === 1) || $blockLeaderInRoom;
    }

    /** @var array<string, bool> 同一リクエスト内の所属判定キャッシュ（行ごとの再問い合わせを避ける） */
    private array $activeMemberCache = [];

    private function isActiveMemberCached(int $userId, int $roomId): bool
    {
        $key = $userId . ':' . $roomId;

        return $this->activeMemberCache[$key] ??= $this->roomAccessService->isActiveMember($userId, $roomId);
    }

    private function validateNormalReservationDates(array $dates, bool $enforceNormalRule): string|bool
    {
        if (empty($dates)) {
            return '日付が選択されていません。';
        }

        foreach ($dates as $date) {
            if (!is_string($date) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
                return '無効な日付が含まれています。';
            }

            if ($this->datePolicy->isPastDate($date)) {
                return (string)Configure::read(
                    'App.messages.pastDateUnavailable',
                    '過去日の内容はこの画面では表示できません。修正が必要な場合は管理者にお問い合わせください。'
                );
            }

            if ($enforceNormalRule) {
                $policyResult = $this->datePolicy->validateReservationDate($date);
                if ($policyResult !== true) {
                    return (string)$policyResult;
                }
            }
        }

        return true;
    }

    private function validateRoomIds(Table $roomTable, array $roomIds): string|bool
    {
        $roomIds = array_values(array_unique(array_filter(array_map('intval', $roomIds), static fn($v) => $v > 0)));
        if (empty($roomIds)) {
            return '部屋が選択されていません。';
        }

        $found = (int)$roomTable->find()->where(['i_id_room IN' => $roomIds, 'i_del_flg' => 0])->count();
        if ($found !== count($roomIds)) {
            return '選択された部屋情報が不正です。画面を再読み込みして再実行してください。';
        }

        return true;
    }

    private function invalidateCachesForDates(int $roomId, array $dates, array $dayUsers = []): void
    {
        if (!$roomId || empty($dates)) {
            return;
        }
        $today = \Cake\I18n\Date::today('Asia/Tokyo')->format('Y-m-d');
        foreach ($dates as $date) {
            if (!is_string($date) || $date === '') {
                continue;
            }
            ReservationReportService::invalidateMealCountsCache($date);
            if ($date === $today && isset($dayUsers[$date]) && is_array($dayUsers[$date])) {
                foreach (array_keys($dayUsers[$date]) as $uid) {
                    Cache::delete(sprintf('today_report:%d:%s', (int)$uid, $date), 'default');
                }
            }
        }
        $this->bumpReportCacheVersion();
    }

    private function bumpReportCacheVersion(): void
    {
        $current = Cache::read('reservation_version', 'default');
        $next = (is_int($current) && $current > 0) ? $current + 1 : 2;
        Cache::write('reservation_version', $next, 'default');
    }

    private function checkReservationSnapshots(
        Table $reservationTable,
        int $roomId,
        array $dayUsers,
        array $snapshots
    ): ?string {
        if (!$roomId) {
            return null;
        }
        $dates = array_keys($dayUsers);
        if (empty($dates)) {
            return null;
        }

        $snapshotQuery = $reservationTable->find();
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

        $serverMap = [];
        foreach ($rows as $row) {
            $dateKey = is_object($row->d_reservation_date) ? $row->d_reservation_date->format('Y-m-d') : (string)$row->d_reservation_date;
            $serverMap[$dateKey] = $row->max_dt ? (string)$row->max_dt : null;
        }

        foreach ($dates as $date) {
            $clientSnap = $snapshots[$date] ?? null;
            if ($clientSnap === null || $clientSnap === '') {
                continue;
            }
            $serverSnap = $serverMap[$date] ?? null;
            if ($serverSnap !== null && $serverSnap > $clientSnap) {
                return (string)$date;
            }
        }
        return null;
    }

    /**
     * 承認済み保護つきの共通更新（TIndividualReservationInfoTable に集約）。
     *
     * @param array{eat_flag?: int, i_change_flag?: int, i_id_room?: int, c_update_user?: string, dt_update?: \Cake\I18n\DateTime} $updateFields
     * @return bool false = 楽観的ロック競合
     * @throws \App\Exception\ApprovedReservationException 承認済み行を更新しようとした場合
     * @throws \App\Exception\DuplicateMealReservationException 更新で有効になる行が、他の部屋の有効な予約と重なる場合
     */
    private function updateReservationRowWithVersion(Table $reservationTable, object $row, array $updateFields): bool
    {
        return $reservationTable->updateRowWithVersion($row, $updateFields);
    }
}
