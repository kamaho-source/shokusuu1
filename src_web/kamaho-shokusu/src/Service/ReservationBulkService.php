<?php
declare(strict_types=1);

namespace App\Service;

use Cake\Core\Configure;
use Cake\I18n\Date;
use Cake\I18n\DateTime;
use Cake\Cache\Cache;
use Cake\Log\Log;
use Cake\ORM\Table;

class ReservationBulkService
{
    private ReservationDatePolicy $datePolicy;

    public function __construct(?ReservationDatePolicy $datePolicy = null)
    {
        $this->datePolicy = $datePolicy ?? new ReservationDatePolicy();
    }

    public function processBulkChangeEdit(
        array $dayUsers,
        int $roomId,
        string $loginName,
        Table $reservationTable,
        Table $userTable,
        array $snapshots = []
    ): array {
        if (!$roomId || empty($dayUsers)) {
            return [
                'ok' => false,
                'message' => '部屋または予約内容が指定されていません。',
            ];
        }

        $connection = $reservationTable->getConnection();
        $connection->begin();
        try {
            $updated = 0;
            $created = 0;

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

            $existingMap = [];
            if (!empty($dates) && !empty($userIds)) {
                $existingRows = $reservationTable->find()
                    ->where([
                        'd_reservation_date IN' => $dates,
                        'i_id_user IN' => $userIds,
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

                    foreach ($meals as $mealTypeRaw => $checked) {
                        $mealType = (int)$mealTypeRaw;
                        if (!in_array($mealType, [1, 2, 3, 4], true)) {
                            continue;
                        }
                        $changeFlag = (int)$checked;

                        $existing = $existingMap[$date][$userId][$mealType] ?? null;

                        if ($existing) {
                            if ($targetUserLevel === 0 && $changeFlag === 0) {
                                continue;
                            }
                            if ((int)$existing->i_change_flag !== $changeFlag) {
                                $ok = $this->updateReservationRowWithVersion($reservationTable, $existing, [
                                    'i_change_flag' => $changeFlag,
                                    'c_update_user' => $loginName,
                                    'dt_update' => DateTime::now('Asia/Tokyo'),
                                ]);
                                if (!$ok) {
                                    throw new \RuntimeException('optimistic_conflict');
                                }
                                $updated++;
                            }
                        } else {
                            if ($changeFlag === 0) {
                                continue;
                            }
                            $newRows[] = [
                                'i_id_user'          => $userId,
                                'd_reservation_date' => $date,
                                'i_reservation_type' => $mealType,
                                'i_id_room'          => $roomId,
                                'eat_flag'           => 0,
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
        Table $roomTable
    ): array {
        $reservationType = $data['reservation_type'] ?? null;
        if (!$reservationType) {
            return [
                'ok' => false,
                'message' => '予約タイプが選択されていません。',
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
        $rowsToActivate = [];
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
                    ->select(['i_id_user', 'd_reservation_date', 'i_reservation_type', 'i_id_room', 'eat_flag', 'i_version'])
                    ->where([
                        'd_reservation_date IN' => $selectedDates,
                        'i_reservation_type IN' => $selectedMealTypes,
                        'i_id_user' => $userId,
                    ])
                    ->all();
                foreach ($existingRows as $row) {
                    $dateKey = is_object($row->d_reservation_date) ? $row->d_reservation_date->format('Y-m-d') : (string)$row->d_reservation_date;
                    $mealTypeKey = (int)$row->i_reservation_type;
                    $roomKey = (int)$row->i_id_room;
                    $existingRoomMap[$dateKey][$mealTypeKey][$roomKey] = $row;
                    if ((int)$row->eat_flag === 1) {
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
                        if ((int)$row->eat_flag === 1) {
                            $existingActiveMap[$dateKey][$uid][$mealTypeKey] = $row;
                        }
                    }
                }

                if (!empty($userIds)) {
                    $userRows = $userTable->find()
                        ->select(['i_id_user', 'c_user_name'])
                        ->where(['i_id_user IN' => $userIds])
                        ->all();
                    foreach ($userRows as $row) {
                        $userNameMap[(int)$row->i_id_user] = $row->c_user_name;
                    }
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
                        foreach ($mealData as $mealType => $checked) {
                            $mealType = (int)$mealType;
                            if (!in_array($mealType, [1, 2, 3, 4], true)) {
                                continue;
                            }

                            // チェックOFF: 既存の有効予約を非活性化（eat_flag=0, i_change_flag=0）
                            if (!(int)$checked) {
                                $activeRow = $existingActiveMap[$date][(int)$targetUserId][$mealType] ?? null;
                                if ($activeRow !== null) {
                                    $ok = $this->updateReservationRowWithVersion($reservationTable, $activeRow, [
                                        'eat_flag'       => 0,
                                        'i_change_flag'  => 0,
                                        'c_update_user'  => $userName,
                                        'dt_update'      => DateTime::now('Asia/Tokyo'),
                                    ]);
                                    if (!$ok) {
                                        throw new \RuntimeException('optimistic_conflict');
                                    }
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
                        ->select(['i_id_user', 'd_reservation_date', 'i_reservation_type', 'i_id_room', 'eat_flag', 'i_version'])
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
                        if ((int)$row->eat_flag === 1) {
                            $existingActiveMap[$dateKey][$uid][$mealTypeKey] = $row;
                        }
                    }
                }
                if (!empty($userIds)) {
                    $userRows = $userTable->find()
                        ->enableAutoFields(false)
                        ->select(['i_id_user', 'c_user_name'])
                        ->where(['i_id_user IN' => $userIds])
                        ->all();
                    foreach ($userRows as $row) {
                        $userNameMap[(int)$row->i_id_user] = $row->c_user_name;
                    }
                }

                foreach ($dates as $date => $checkedDate) {
                    if (!$checkedDate) {
                        continue;
                    }
                    foreach ($users as $targetUserId => $mealData) {
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

        if (!empty($rowsToActivate) || !empty($reservations)) {
            $connection = $reservationTable->getConnection();
            $connection->begin();
            try {
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
            'message' => !empty($skippedMessages)
                ? "一部の予約は既に存在していたためスキップされました。\n" . implode("\n", $skippedMessages)
                : "すべての予約が正常に登録されました。",
            'redirect_url' => './',
        ];
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
            try {
                $targetDate = new Date($date, 'Asia/Tokyo');
                $today = Date::today('Asia/Tokyo');
            } catch (\Throwable $e) {
                return '無効な日付が含まれています。';
            }

            if ($targetDate < $today) {
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

        $found = (int)$roomTable->find()->where(['i_id_room IN' => $roomIds])->count();
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
            Cache::delete('meal_counts:' . $date, 'default');
            Cache::delete(sprintf('users_by_room_edit:%d:%s', $roomId, $date), 'default');
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
                    $snapshotQuery->newExpr('COALESCE(dt_update, dt_create)')
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

    private function updateReservationRowWithVersion(Table $reservationTable, object $row, array $updateFields): bool
    {
        $expectedVersion = (int)($row->i_version ?? 1);
        $set = $updateFields;
        $set['i_version'] = $expectedVersion + 1;

        $dateValue = $row->d_reservation_date;
        if (is_object($dateValue)) {
            $dateValue = $dateValue->format('Y-m-d');
        } else {
            $dateValue = (string)$dateValue;
        }

        $affected = $reservationTable->updateAll(
            $set,
            [
                'i_id_user' => (int)$row->i_id_user,
                'd_reservation_date' => $dateValue,
                'i_reservation_type' => (int)$row->i_reservation_type,
                'i_id_room' => (int)$row->i_id_room,
                'i_version' => $expectedVersion,
            ]
        );

        return $affected === 1;
    }
}
