<?php
declare(strict_types=1);

namespace App\Service;

use Cake\I18n\Date;
use Cake\I18n\DateTime;
use Cake\Cache\Cache;
use Cake\Core\Configure;
use Cake\Log\Log;
use Cake\ORM\Table;

class ReservationWriteService
{
    public function __construct(
        private Table $reservationTable,
        private Table $userTable,
        private Table $roomTable,
        private string $webroot
    ) {}

    public function processIndividualReservation(
        string $reservationDate,
        array|string $jsonData,
        array $rooms,
        int $userId,
        string $userName,
        callable $dateValidator
    ): array {
        if (empty($jsonData) || (!is_string($jsonData) && !is_array($jsonData))) {
            Log::error('入力データが無効です。空文字列または期待しない形式です。');
            return $this->err('入力データが無効です。', 400);
        }

        $data = is_string($jsonData) ? json_decode($jsonData, true) : $jsonData;
        if (is_null($data) || json_last_error() !== JSON_ERROR_NONE) {
            Log::error('JSONデコードエラー: ' . json_last_error_msg());
            return $this->err('データの形式が不正です。', 400);
        }

        $dateValidation = $dateValidator($reservationDate);
        if ($dateValidation !== true) {
            Log::error('日付検証エラー: ' . $dateValidation);
            return $this->err((string)$dateValidation, 422);
        }

        if (!isset($data['meals']) || !is_array($data['meals'])) {
            Log::error('データ構造が不正: "meals" キーが存在しない、または配列ではありません。データ: ' . json_encode($data));
            return $this->err('データ構造が不正です。', 422);
        }

        $reservationsToSave  = [];
        $operationPerformed  = false;
        $selectedRoomPerMeal = [];
        $duplicates          = [];
        $connection = $this->reservationTable->getConnection();
        $connection->begin();
        try {
            $existingRows = $this->reservationTable->find()
                ->enableAutoFields(false)
                ->select(['i_id_user', 'd_reservation_date', 'i_reservation_type', 'i_id_room', 'eat_flag', 'i_change_flag', 'i_version'])
                ->where([
                    'i_id_user' => $userId,
                    'd_reservation_date' => $reservationDate,
                    'i_reservation_type IN' => [1, 2, 3, 4],
                ])
                ->all();
            $existingMap = [];
            $existingByMeal = [];
            foreach ($existingRows as $row) {
                $existingMap[(int)$row->i_reservation_type][(int)$row->i_id_room] = $row;
                $existingByMeal[(int)$row->i_reservation_type][] = $row;
            }

            foreach ($data['meals'] as $mealType => $selectedRooms) {
                $selectedRoomPerMeal[$mealType] = null;
                foreach ($selectedRooms as $roomId => $value) {
                    $valueInt = is_bool($value) ? ($value ? 1 : 0) : (int)$value;
                    if ($valueInt !== 1) {
                        continue;
                    }

                    if (isset($selectedRoomPerMeal[$mealType]) && $selectedRoomPerMeal[$mealType] !== $roomId) {
                        Log::error("同一食事区分で複数部屋が選択されました。MealType={$mealType}");
                        $connection->rollback();
                        return $this->err('同じ食事区分に対して複数の部屋を選択することはできません。', 409);
                    }

                    if (!array_key_exists($roomId, $rooms)) {
                        Log::error('権限のない部屋が指定されました。Room ID: ' . $roomId);
                        $connection->rollback();
                        return $this->err('選択された部屋は権限がありません。', 403);
                    }

                    $selectedRoomPerMeal[$mealType] = $roomId;
                }
            }

            foreach ($selectedRoomPerMeal as $mealType => $roomId) {
                if ($roomId === null) {
                    foreach ($existingByMeal[(int)$mealType] ?? [] as $row) {
                        if ((int)$row->eat_flag !== 1) {
                            continue;
                        }
                        $ok = $this->updateReservationRowWithVersion($row, [
                            'eat_flag'      => 0,
                            'i_change_flag' => 0,
                            'c_update_user' => $userName,
                            'dt_update'     => DateTime::now(),
                        ]);
                        if (!$ok) {
                            $connection->rollback();
                            return $this->err('他の操作と競合しました。画面を再読み込みして再実行してください。', 409);
                        }
                        $operationPerformed = true;
                    }
                    continue;
                }

                foreach ($existingByMeal[(int)$mealType] ?? [] as $row) {
                    if ((int)$row->i_id_room === (int)$roomId || (int)$row->eat_flag !== 1) {
                        continue;
                    }
                    $ok = $this->updateReservationRowWithVersion($row, [
                        'eat_flag'      => 0,
                        'i_change_flag' => 0,
                        'c_update_user' => $userName,
                        'dt_update'     => DateTime::now(),
                    ]);
                    if (!$ok) {
                        $connection->rollback();
                        return $this->err('他の操作と競合しました。画面を再読み込みして再実行してください。', 409);
                    }
                    $operationPerformed = true;
                }

                $existingReservation = $existingMap[(int)$mealType][(int)$roomId] ?? null;

                if ($existingReservation) {
                    if ((int)$existingReservation->eat_flag === 0) {
                        $updateFields = [
                            'eat_flag'      => 1,
                            'i_change_flag' => 1,
                            'c_update_user' => $userName,
                            'dt_update'     => DateTime::now(),
                        ];
                        $ok = $this->updateReservationRowWithVersion($existingReservation, $updateFields);
                        if (!$ok) {
                            $connection->rollback();
                            return $this->err('他の操作と競合しました。画面を再読み込みして再実行してください。', 409);
                        }
                        $operationPerformed = true;
                    } else {
                        $duplicates[] = [
                            'reservation_date' => $reservationDate,
                            'meal_type'        => $mealType,
                            'room_id'          => $roomId,
                        ];
                    }
                    continue;
                }

                $newReservation = $this->reservationTable->patchEntity(
                    $this->reservationTable->newEmptyEntity(),
                    [
                        'i_id_user'          => $userId,
                        'd_reservation_date' => $reservationDate,
                        'i_id_room'          => $roomId,
                        'i_reservation_type' => $mealType,
                        'eat_flag'           => 1,
                        'i_change_flag'      => 1,
                        'i_version'          => 1,
                        'c_create_user'      => $userName,
                        'dt_create'          => DateTime::now(),
                    ]
                );
                $reservationsToSave[] = $newReservation;
            }

            if (!empty($reservationsToSave)) {
                try {
                    $this->reservationTable->saveManyOrFail($reservationsToSave);
                } catch (\Cake\ORM\Exception\PersistenceFailedException $e) {
                    $connection->rollback();
                    Log::error('個人予約 saveManyOrFail エラー: ' . json_encode($e->getEntity()?->getErrors() ?? [], JSON_UNESCAPED_UNICODE));
                    $detail = Configure::read('debug') ? ' 詳細: ' . implode('、', array_merge(...array_values(array_map('array_values', $e->getEntity()?->getErrors() ?? [[]])))) : '';
                    return $this->err('予約の登録中にエラーが発生しました。' . $detail, 500);
                }
                $operationPerformed = true;
            }
            $connection->commit();
        } catch (\Throwable $e) {
            if ($connection->inTransaction()) {
                $connection->rollback();
            }
            Log::error('個人予約処理で予期しない例外: ' . $e->getMessage());
            return $this->err('予約処理中に内部エラーが発生しました。', 500);
        }

        $affectedRooms = [];
        foreach ($selectedRoomPerMeal as $roomId) {
            if ($roomId !== null) {
                $affectedRooms[(int)$roomId] = true;
            }
        }
        foreach ($existingMap as $roomsMap) {
            foreach ($roomsMap as $roomId => $_row) {
                $affectedRooms[(int)$roomId] = true;
            }
        }
        $this->invalidateCachesForDateRooms($reservationDate, array_keys($affectedRooms), [$userId]);

        $finalStates = [
            'breakfast' => false,
            'lunch'     => false,
            'dinner'    => false,
            'bento'     => false,
        ];
        $type2key = [1 => 'breakfast', 2 => 'lunch', 3 => 'dinner', 4 => 'bento'];

        $rows = $this->reservationTable->find()
            ->select(['i_reservation_type', 'eat_flag'])
            ->where([
                'i_id_user'          => $userId,
                'd_reservation_date' => $reservationDate,
            ])
            ->all();

        foreach ($rows as $r) {
            $k = $type2key[(int)$r->i_reservation_type] ?? null;
            if ($k) {
                $finalStates[$k] = ((int)$r->eat_flag === 1);
            }
        }

        if (!empty($duplicates)) {
            return $this->ok('一部の予約は既に存在するため、スキップされました。', [
                'skipped' => $duplicates,
                'details' => $finalStates,
                'date' => $reservationDate,
            ], $this->redirectToIndex());
        }

        if ($operationPerformed) {
            return $this->ok('個人予約が正常に登録されました。', [
                'details' => $finalStates,
                'date' => $reservationDate,
            ], $this->redirectToIndex());
        }

        return $this->err('システムエラーが発生しました。', 500);
    }

    public function processGroupReservation(
        string $reservationDate,
        array|string $jsonData,
        array $rooms,
        string $creatorName,
        callable $dateValidator
    ): array {
        if (empty($jsonData) || (!is_string($jsonData) && !is_array($jsonData))) {
            Log::error('入力データが無効です。空文字列または想定しない形式です。');
            return $this->err('入力データが無効です。', 400);
        }

        $data = is_string($jsonData) ? json_decode($jsonData, true) : $jsonData;
        if (is_null($data) || json_last_error() !== JSON_ERROR_NONE) {
            Log::error('JSON デコードエラー: ' . json_last_error_msg());
            return $this->err('データの形式が不正です。', 400);
        }

        $dateValidation = $dateValidator($reservationDate);
        if ($dateValidation !== true) {
            Log::error('日付検証エラー: ' . $dateValidation);
            return $this->err((string)$dateValidation, 422);
        }

        if (!isset($data['users']) || !is_array($data['users'])) {
            Log::error('データ構造が不正: "users" キーが存在しない、または配列ではありません。データ: ' . json_encode($data));
            return $this->err('データ構造が不正です。', 422);
        }

        $reservationsToSave = [];
        $duplicates = [];
        $connection = $this->reservationTable->getConnection();
        $connection->begin();
        try {
            $mealTypeNames = [
                '1' => '朝食',
                '2' => '昼食',
                '3' => '夕食',
                '4' => '弁当',
            ];

            $roomId = $data['i_id_room'] ?? null;
            $userIds = array_map('intval', array_keys($data['users']));
            $mealTypes = [1, 2, 3, 4];

            $existingRows = [];
            $existingMap = [];
            if (!empty($userIds)) {
                $existingRows = $this->reservationTable->find()
                    ->enableAutoFields(false)
                    ->select(['i_id_user', 'd_reservation_date', 'i_reservation_type', 'i_id_room', 'eat_flag', 'i_change_flag', 'i_version'])
                    ->where([
                        'd_reservation_date' => $reservationDate,
                        'i_id_user IN' => $userIds,
                        'i_reservation_type IN' => $mealTypes,
                    ])
                    ->all();
                foreach ($existingRows as $row) {
                    $existingMap[(int)$row->i_id_user][(int)$row->i_reservation_type][(int)$row->i_id_room] = $row;
                }
            }

            $userNameMap = [];
            if (!empty($userIds)) {
                $userRows = $this->userTable->find()
                    ->enableAutoFields(false)
                    ->select(['i_id_user', 'c_user_name'])
                    ->where(['i_id_user IN' => $userIds])
                    ->all();
                foreach ($userRows as $row) {
                    $userNameMap[(int)$row->i_id_user] = $row->c_user_name;
                }
            }

            $roomNameMap = [];
            $roomIdsForName = [];
            if ($roomId !== null) {
                $roomIdsForName[(int)$roomId] = true;
            }
            foreach ($existingRows as $row) {
                $roomIdsForName[(int)$row->i_id_room] = true;
            }
            $roomIdsForName = array_keys($roomIdsForName);
            if (!empty($roomIdsForName)) {
                $roomRows = $this->roomTable->find()
                    ->enableAutoFields(false)
                    ->select(['i_id_room', 'c_room_name'])
                    ->where(['i_id_room IN' => $roomIdsForName])
                    ->all();
                foreach ($roomRows as $row) {
                    $roomNameMap[(int)$row->i_id_room] = $row->c_room_name;
                }
            }

            foreach ($data['users'] as $targetUserId => $meals) {
                foreach ($meals as $mealType => $selected) {
                    $valueInt = is_bool($selected) ? ($selected ? 1 : 0) : (int)$selected;

                    if (!isset($rooms[$roomId])) {
                        $connection->rollback();
                        return $this->err('選択された部屋は権限がありません。', 403);
                    }

                    if ($valueInt !== 1) {
                        $existingReservation = $existingMap[(int)$targetUserId][(int)$mealType][(int)$roomId] ?? null;
                        if ($existingReservation && (int)$existingReservation->eat_flag === 1) {
                            $ok = $this->updateReservationRowWithVersion($existingReservation, [
                                'eat_flag'      => 0,
                                'i_change_flag' => 0,
                                'c_update_user' => $creatorName,
                                'dt_update'     => DateTime::now(),
                            ]);
                            if (!$ok) {
                                $connection->rollback();
                                return $this->err('他の操作と競合しました。画面を再読み込みして再実行してください。', 409);
                            }
                        }
                        continue;
                    }

                    $userMealRows = $existingMap[(int)$targetUserId][(int)$mealType] ?? [];
                    $existingReservation = $userMealRows[(int)$roomId] ?? null;

                    foreach ($userMealRows as $existingRoomId => $row) {
                        if ((int)$existingRoomId === (int)$roomId || (int)$row->eat_flag !== 1) {
                            continue;
                        }
                        $ok = $this->updateReservationRowWithVersion($row, [
                            'eat_flag'      => 0,
                            'i_change_flag' => 0,
                            'c_update_user' => $creatorName,
                            'dt_update'     => DateTime::now(),
                        ]);
                        if (!$ok) {
                            $connection->rollback();
                            return $this->err('他の操作と競合しました。画面を再読み込みして再実行してください。', 409);
                        }
                    }

                    if ($existingReservation) {
                        if ((int)$existingReservation->eat_flag === 0) {
                            $ok = $this->updateReservationRowWithVersion($existingReservation, [
                                'eat_flag'      => 1,
                                'i_change_flag' => 1,
                                'c_update_user' => $creatorName,
                                'dt_update'     => DateTime::now(),
                            ]);
                            if (!$ok) {
                                $connection->rollback();
                                return $this->err('他の操作と競合しました。画面を再読み込みして再実行してください。', 409);
                            }
                            continue;
                        }

                        $reservedUserName = $userNameMap[(int)$targetUserId] ?? '不明なユーザー名';
                        $reservedRoomName = $roomNameMap[(int)$existingReservation->i_id_room] ?? '不明な部屋名';

                        $duplicates[] = [
                            'user_name' => $reservedUserName,
                            'meal_type' => $mealTypeNames[$mealType] ?? $mealType,
                            'room_name' => $reservedRoomName
                        ];
                        continue;
                    }

                    $newReservation = $this->reservationTable->patchEntity(
                        $this->reservationTable->newEmptyEntity(),
                        [
                            'i_id_user'          => $targetUserId,
                            'd_reservation_date' => $reservationDate,
                            'i_id_room'          => $roomId,
                            'i_reservation_type' => $mealType,
                            'eat_flag'           => 1,
                            'i_change_flag'      => 1,
                            'i_version'          => 1,
                            'c_create_user'      => $creatorName,
                            'dt_create'          => DateTime::now(),
                        ]
                    );
                    $reservationsToSave[] = $newReservation;
                }
            }

            if (!empty($reservationsToSave)) {
                try {
                    $this->reservationTable->saveManyOrFail($reservationsToSave);
                } catch (\Cake\ORM\Exception\PersistenceFailedException $e) {
                    $connection->rollback();
                    Log::error('グループ予約 saveManyOrFail エラー: ' . json_encode($e->getEntity()?->getErrors() ?? [], JSON_UNESCAPED_UNICODE));
                    $detail = Configure::read('debug') ? ' 詳細: ' . implode('、', array_merge(...array_values(array_map('array_values', $e->getEntity()?->getErrors() ?? [[]])))) : '';
                    return $this->err('予約の登録中にエラーが発生しました。' . $detail, 500);
                }
            }
            $connection->commit();
        } catch (\Throwable $e) {
            if ($connection->inTransaction()) {
                $connection->rollback();
            }
            Log::error('一括予約処理で予期しない例外: ' . $e->getMessage());
            return $this->err('予約処理中に内部エラーが発生しました。', 500);
        }

        $affectedRooms = $roomIdsForName ?: [];
        if ($roomId !== null) {
            $affectedRooms[] = (int)$roomId;
        }
        $this->invalidateCachesForDateRooms($reservationDate, $affectedRooms, $userIds);

        if (!empty($duplicates)) {
            return $this->ok('一部の予約はすでに存在していたためスキップされました。', [
                'skipped' => $duplicates,
                'date' => $reservationDate,
            ], $this->redirectToIndex());
        }

        return $this->ok('予約が正常に登録されました。', ['date' => $reservationDate], $this->redirectToIndex());
    }

    public function processToggle(
        int $roomId,
        array $payload,
        int $loginUserId,
        string $loginUserName
    ): array {
        if (empty($payload)) {
            return [
                'status' => 400,
                'body' => ['ok' => false, 'message' => 'Empty request body.'],
            ];
        }

        $targetUserId = isset($payload['userId']) ? (int)$payload['userId'] : $loginUserId;
        $actorName    = $loginUserName;

        $dateStr = (string)($payload['date'] ?? '');
        $meal    = isset($payload['meal'])  ? (int)$payload['meal']  : null;
        $value   = isset($payload['value']) ? (int)$payload['value'] : null;

        $loginUser = $this->userTable->find()
            ->select(['i_admin', 'i_user_level'])
            ->where(['i_id_user' => $loginUserId])
            ->first();
        $isAdmin = $loginUser ? ((int)$loginUser->i_admin === 1) : false;
        $isStaff = $loginUser ? ((int)$loginUser->i_user_level === 0) : false;
        if ($targetUserId !== $loginUserId && !($isAdmin || $isStaff)) {
            return [
                'status' => 403,
                'body' => ['ok' => false, 'message' => '他ユーザーの予約を更新する権限がありません。'],
            ];
        }

        $targetUser = $this->userTable->find()
            ->select(['i_user_level'])
            ->where(['i_id_user' => $targetUserId])
            ->first();
        if ($targetUser === null) {
            return [
                'status' => 404,
                'body' => ['ok' => false, 'message' => '対象ユーザーが存在しません。'],
            ];
        }
        $targetUserLevel = (int)$targetUser->i_user_level;

        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateStr)) {
            return [
                'status' => 422,
                'body' => ['ok' => false, 'message' => 'Invalid date format (Y-m-d).'],
            ];
        }
        try {
            new Date($dateStr);
        } catch (\Throwable $e) {
            return [
                'status' => 422,
                'body' => ['ok' => false, 'message' => 'Invalid date value.'],
            ];
        }
        if (!in_array($meal, [1, 2, 3, 4], true)) {
            return [
                'status' => 422,
                'body' => ['ok' => false, 'message' => 'Invalid meal type (1..4).'],
            ];
        }
        if (!in_array($value, [0, 1], true)) {
            return [
                'status' => 422,
                'body' => ['ok' => false, 'message' => 'Invalid value (0 or 1).'],
            ];
        }

        $targetDate   = new Date($dateStr);
        $today        = Date::today('Asia/Tokyo');
        $lastDeadline = $today->addDays(14);
        $isLastMinute = ($targetDate >= $today && $targetDate <= $lastDeadline);

        $exists = $this->reservationTable->exists([
            'i_id_user'          => $targetUserId,
            'i_id_room'          => $roomId,
            'd_reservation_date' => $dateStr,
            'i_reservation_type' => $meal,
        ]);

        if ($isLastMinute && $targetUserLevel === 0 && $value === 0 && $exists) {
            return [
                'status' => 403,
                'body' => ['ok' => false, 'message' => '職員は直前編集でのキャンセルはできません。'],
            ];
        }

        if ($exists) {
            $existingEatFlag = null;
            if ($isLastMinute) {
                $existingEntity = $this->reservationTable->find()
                    ->select(['eat_flag'])
                    ->where([
                        'i_id_user'          => $targetUserId,
                        'i_id_room'          => $roomId,
                        'd_reservation_date' => $dateStr,
                        'i_reservation_type' => $meal,
                    ])
                    ->first();
                $existingEatFlag = $existingEntity ? (int)$existingEntity->eat_flag : 0;
            }

            if ($value === 1) {
                $eatFlag = $isLastMinute ? (int)$existingEatFlag : 1;
                $changeFlag = 1;
            } else {
                $eatFlag = $isLastMinute ? (int)$existingEatFlag : 0;
                $changeFlag = 0;
            }
        } else {
            if ($value === 1) {
                $eatFlag    = $isLastMinute ? 0 : 1;
                $changeFlag = 1;
            } else {
                $eatFlag    = 0;
                $changeFlag = 0;
            }
        }

        try {
            $result = $this->reservationTable->toggleMeal(
                userId: $targetUserId,
                roomId: $roomId,
                date:   $dateStr,
                meal:   $meal,
                on:     $value === 1,
                actor:  $actorName,
                eatFlag: $eatFlag,
                changeFlag: $changeFlag,
            );
            $this->invalidateCachesForDateRooms($dateStr, [$roomId], [$targetUserId]);

            return [
                'status' => 200,
                'body' => [
                    'ok'      => true,
                    'value'   => (bool)($result['value'] ?? false),
                    'details' => $result['details'] ?? [],
                ],
            ];
        } catch (\Cake\ORM\Exception\PersistenceFailedException $e) {
            $errors = $e->getEntity()?->getErrors() ?? [];
            $flat   = json_encode($errors, JSON_UNESCAPED_UNICODE);

            $isMealConflict = (is_string($flat) && preg_match('/(昼|弁|bento|lunch|unique.*bento|unique.*lunch)/ui', $flat));
            $isOptimisticConflict = (is_string($flat) && preg_match('/(conflict|optimistic)/ui', $flat));
            $isConflict = $isMealConflict || $isOptimisticConflict;
            $status = $isConflict ? 409 : 422;
            $message = 'Validation failed.';
            if ($isMealConflict) {
                $message = '昼食と弁当は同時に予約できません。';
            } elseif ($isOptimisticConflict) {
                $message = '他の操作と競合しました。画面を再読み込みして再実行してください。';
            }

            return [
                'status' => $status,
                'body' => [
                    'ok'      => false,
                    'message' => $message,
                    'errors'  => \Cake\Core\Configure::read('debug') ? $errors : null,
                ],
            ];
        } catch (\InvalidArgumentException $e) {
            return [
                'status' => 422,
                'body' => ['ok' => false, 'message' => $e->getMessage()],
            ];
        } catch (\Throwable $e) {
            return [
                'status' => 500,
                'body' => [
                    'ok' => false,
                    'message' => 'Internal Server Error',
                    'debug' => \Cake\Core\Configure::read('debug') ? $e->getMessage() : null,
                ],
            ];
        }
    }

    private function ok(string $message, array $data = [], ?string $redirect = null): array
    {
        return ['ok' => true, 'message' => $message, 'data' => $data, 'redirect' => $redirect];
    }

    private function err(string $message, int $status, array $data = []): array
    {
        return ['ok' => false, 'message' => $message, 'status' => $status, 'data' => $data];
    }

    private function updateReservationRowWithVersion(object $row, array $updateFields): bool
    {
        $expectedVersion = (int)($row->i_version ?? 1);
        $set = $updateFields;
        $set['i_version'] = $expectedVersion + 1;

        $affected = $this->reservationTable->updateAll(
            $set,
            [
                'i_id_user'          => (int)$row->i_id_user,
                'd_reservation_date' => (string)$row->d_reservation_date,
                'i_reservation_type' => (int)$row->i_reservation_type,
                'i_id_room'          => (int)$row->i_id_room,
                'i_version'          => $expectedVersion,
            ]
        );

        return $affected === 1;
    }

    private function redirectToIndex(): string
    {
        return $this->webroot . 'TReservationInfo/';
    }

    private function invalidateCachesForDateRooms(string $date, array $roomIds, array $userIds = []): void
    {
        if ($date === '') {
            return;
        }
        Cache::delete('meal_counts:' . $date, 'default');
        foreach (array_unique(array_filter($roomIds)) as $rid) {
            Cache::delete(sprintf('users_by_room_edit:%d:%s', (int)$rid, $date), 'default');
        }
        $today = Date::today('Asia/Tokyo')->format('Y-m-d');
        if ($date === $today) {
            foreach (array_unique(array_filter($userIds)) as $uid) {
                Cache::delete(sprintf('today_report:%d:%s', (int)$uid, $date), 'default');
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
}
