<?php
declare(strict_types=1);

namespace App\Service;

use App\Domain\Exception\ConflictException;
use App\Domain\Exception\InvalidInputException;
use App\Domain\Exception\NotFoundException;
use App\Domain\Exception\PersistenceException;
use App\Domain\Exception\UnauthorizedException;
use App\Domain\ValueObject\UserRole;
use Cake\I18n\Date;
use Cake\I18n\DateTime;
use Cake\Cache\Cache;
use Cake\Core\Configure;
use Cake\Log\Log;
use Cake\ORM\Table;
use Cake\ORM\TableRegistry;

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
        try {
            $data = $this->decodeJsonInput($jsonData);
        } catch (\InvalidArgumentException $e) {
            Log::error($e->getMessage());
            throw new InvalidInputException($e->getMessage(), 400);
        } catch (\JsonException $e) {
            Log::error('JSONデコードエラー: ' . $e->getMessage());
            throw new InvalidInputException('データの形式が不正です。', 400);
        }

        if (!isset($data['meals']) || !is_array($data['meals'])) {
            Log::error('データ構造が不正: "meals" キーが存在しない、または配列ではありません。データ: ' . json_encode($data));
            throw new InvalidInputException('データ構造が不正です。');
        }

        $dateValidation = $dateValidator($reservationDate);
        if ($dateValidation !== true) {
            Log::error('日付検証エラー: ' . $dateValidation);
            throw new InvalidInputException((string)$dateValidation);
        }

        try {
            $selectedRoomPerMeal = $this->resolveSelectedRoomsPerMeal($data['meals'], $rooms);
        } catch (\OverflowException $e) {
            Log::error($e->getMessage());
            throw new ConflictException($e->getMessage());
        } catch (\DomainException $e) {
            Log::error($e->getMessage());
            throw new UnauthorizedException($e->getMessage());
        }

        $existingMap        = [];
        $duplicates         = [];
        $operationPerformed = false;
        $connection         = $this->reservationTable->getConnection();
        $connection->begin();
        try {
            ['byRoom' => $existingMap, 'byMeal' => $existingByMeal] = $this->buildIndividualExistingMaps($reservationDate, $userId);
            $changes            = $this->applyIndividualMealChanges($selectedRoomPerMeal, $existingByMeal, $existingMap, $reservationDate, $userId, $userName);
            $duplicates         = $changes['duplicates'];
            $operationPerformed = $changes['performed'];

            if (!empty($changes['toSave'])) {
                try {
                    $this->reservationTable->saveManyOrFail($changes['toSave']);
                } catch (\Cake\ORM\Exception\PersistenceFailedException $e) {
                    $connection->rollback();
                    Log::error('個人予約 saveManyOrFail エラー: ' . json_encode($e->getEntity()?->getErrors() ?? [], JSON_UNESCAPED_UNICODE));
                    $detail = Configure::read('debug') ? ' 詳細: ' . implode('、', array_merge(...array_values(array_map('array_values', $e->getEntity()?->getErrors() ?? [[]])))) : '';
                    throw new PersistenceException('予約の登録中にエラーが発生しました。' . $detail);
                }
                $operationPerformed = true;
            }
            $connection->commit();
        } catch (\App\Domain\Exception\DomainException $e) {
            throw $e;
        } catch (\RuntimeException $e) {
            $connection->rollback();
            throw new ConflictException($e->getMessage());
        } catch (\Throwable $e) {
            if ($connection->inTransaction()) {
                $connection->rollback();
            }
            Log::error('個人予約処理で予期しない例外: ' . $e->getMessage());
            throw new PersistenceException('予約処理中に内部エラーが発生しました。');
        }

        $affectedRooms = [];
        foreach (array_filter($selectedRoomPerMeal) as $roomId) {
            $affectedRooms[(int)$roomId] = true;
        }
        foreach ($existingMap as $roomsMap) {
            foreach (array_keys($roomsMap) as $roomId) {
                $affectedRooms[(int)$roomId] = true;
            }
        }
        $this->invalidateCachesForDateRooms($reservationDate, array_keys($affectedRooms), [$userId]);

        $finalStates = $this->buildFinalReservationStates($reservationDate, $userId);

        if (!empty($duplicates)) {
            return $this->ok('一部の予約は既に存在するため、スキップされました。', [
                'skipped' => $duplicates,
                'details' => $finalStates,
                'date'    => $reservationDate,
            ], $this->redirectToIndex());
        }

        if ($operationPerformed) {
            return $this->ok('個人予約が正常に登録されました。', [
                'details' => $finalStates,
                'date'    => $reservationDate,
            ], $this->redirectToIndex());
        }

        throw new PersistenceException('システムエラーが発生しました。');
    }

    public function processGroupReservation(
        string $reservationDate,
        array|string $jsonData,
        array $rooms,
        string $creatorName,
        callable $dateValidator,
        int $loginUserId = 0,
        bool $isAdmin = false,
        int $loginUserLevel = 0,
        bool $isBlockLeader = false
    ): array {
        try {
            $data = $this->decodeJsonInput($jsonData);
        } catch (\InvalidArgumentException $e) {
            Log::error($e->getMessage());
            throw new InvalidInputException($e->getMessage(), 400);
        } catch (\JsonException $e) {
            Log::error('JSON デコードエラー: ' . $e->getMessage());
            throw new InvalidInputException('データの形式が不正です。', 400);
        }

        if (!isset($data['users']) || !is_array($data['users'])) {
            Log::error('データ構造が不正: "users" キーが存在しない、または配列ではありません。データ: ' . json_encode($data));
            throw new InvalidInputException('データ構造が不正です。');
        }

        $dateValidation = $dateValidator($reservationDate);
        if ($dateValidation !== true) {
            Log::error('日付検証エラー: ' . $dateValidation);
            throw new InvalidInputException((string)$dateValidation);
        }

        $roomId  = isset($data['i_id_room']) ? (int)$data['i_id_room'] : null;
        $userIds = array_map('intval', array_keys($data['users']));

        // ログインユーザーを特定できない場合は権限判定できないため拒否する（fail-closed）
        if ($loginUserId <= 0) {
            throw new UnauthorizedException('ログインユーザーを特定できないため予約を更新できません。');
        }

        $isLoginStaff    = in_array($loginUserLevel, [0, 7], true);
        $targetUserLevels = $this->fetchUserLevels($userIds);
        $blockLeaderInRoom = false;
        if ($isBlockLeader && $roomId !== null) {
            $userGroupTable    = TableRegistry::getTableLocator()->get('MUserGroup');
            $blockLeaderInRoom = (bool)$userGroupTable->exists([
                'i_id_user' => $loginUserId,
                'i_id_room' => $roomId,
            ]);
        }
        foreach ($userIds as $targetUserId) {
            $targetUserLevel = $targetUserLevels[$targetUserId] ?? 0;
            $canEditOther    = $isAdmin || ($isLoginStaff && $targetUserLevel === 1) || $blockLeaderInRoom;
            if ($targetUserId !== $loginUserId && !$canEditOther) {
                throw new UnauthorizedException('他ユーザーの予約を更新する権限がありません。');
            }
        }

        $existingMap = $this->buildGroupExistingMap($reservationDate, $userIds);
        $userNameMap = $this->fetchUserNames($userIds);

        $roomIdsForName = $roomId !== null ? [$roomId => true] : [];
        foreach ($existingMap as $userMap) {
            foreach ($userMap as $mealMap) {
                foreach (array_keys($mealMap) as $rid) {
                    $roomIdsForName[(int)$rid] = true;
                }
            }
        }
        $allRoomIds  = array_keys($roomIdsForName);
        $roomNameMap = $this->fetchRoomNames($allRoomIds);

        $duplicates = [];
        $connection = $this->reservationTable->getConnection();
        $connection->begin();
        try {
            $changes    = $this->applyGroupMealChanges($data['users'], $rooms, $roomId, $existingMap, $userNameMap, $roomNameMap, $reservationDate, $creatorName);
            $duplicates = $changes['duplicates'];

            if (!empty($changes['toSave'])) {
                try {
                    $this->reservationTable->saveManyOrFail($changes['toSave']);
                } catch (\Cake\ORM\Exception\PersistenceFailedException $e) {
                    $connection->rollback();
                    Log::error('グループ予約 saveManyOrFail エラー: ' . json_encode($e->getEntity()?->getErrors() ?? [], JSON_UNESCAPED_UNICODE));
                    $detail = Configure::read('debug') ? ' 詳細: ' . implode('、', array_merge(...array_values(array_map('array_values', $e->getEntity()?->getErrors() ?? [[]])))) : '';
                    throw new PersistenceException('予約の登録中にエラーが発生しました。' . $detail);
                }
            }
            $connection->commit();
        } catch (\App\Domain\Exception\DomainException $e) {
            throw $e;
        } catch (\DomainException $e) {
            $connection->rollback();
            throw new UnauthorizedException($e->getMessage());
        } catch (\RuntimeException $e) {
            $connection->rollback();
            throw new ConflictException($e->getMessage());
        } catch (\Throwable $e) {
            if ($connection->inTransaction()) {
                $connection->rollback();
            }
            Log::error('一括予約処理で予期しない例外: ' . $e->getMessage());
            throw new PersistenceException('予約処理中に内部エラーが発生しました。');
        }

        $this->invalidateCachesForDateRooms($reservationDate, $allRoomIds, $userIds);

        if (!empty($duplicates)) {
            return $this->ok('一部の予約はすでに存在していたためスキップされました。', [
                'skipped' => $duplicates,
                'date'    => $reservationDate,
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
            throw new InvalidInputException('Empty request body.', 400);
        }

        $targetUserId = isset($payload['userId']) ? (int)$payload['userId'] : $loginUserId;
        $actorName    = $loginUserName;

        $dateStr = (string)($payload['date'] ?? '');
        $meal    = isset($payload['meal'])  ? (int)$payload['meal']  : null;
        $value   = isset($payload['value']) ? (int)$payload['value'] : null;

        $loginUser = $this->userTable->find()
            ->select(['i_admin', 'i_user_level', 'i_id_staff'])
            ->where(['i_id_user' => $loginUserId])
            ->first();
        $isAdmin       = $loginUser ? UserRole::isAdmin((int)$loginUser->i_admin) : false;
        $isBlockLeader = $loginUser ? UserRole::isBlockLeader((int)$loginUser->i_admin) : false;
        $loginStaff    = $loginUser ? $loginUser->i_id_staff : null;
        $hasStaffId    = $loginStaff !== null && $loginStaff !== '' && $loginStaff !== 0;
        // i_user_level=0,7 は職員レベル（staffId 有無を問わず子供の予約を編集可）
        $isStaffUser   = $loginUser ? in_array((int)($loginUser->i_user_level ?? -1), [0, 7], true) : false;

        $targetUser = $this->userTable->find()
            ->select(['i_user_level'])
            ->where(['i_id_user' => $targetUserId])
            ->first();
        if ($targetUser === null) {
            throw new NotFoundException('対象ユーザーが存在しません。');
        }
        $targetUserLevel = (int)$targetUser->i_user_level;

        $userGroupTable    = TableRegistry::getTableLocator()->get('MUserGroup');
        $blockLeaderInRoom = $isBlockLeader && (bool)$userGroupTable->exists([
            'i_id_user' => $loginUserId,
            'i_id_room' => $roomId,
        ]);
        $canEditOther = $isAdmin || (($isStaffUser || $isBlockLeader) && $targetUserLevel === 1) || $blockLeaderInRoom;
        if ($targetUserId !== $loginUserId && !$canEditOther) {
            throw new UnauthorizedException('他ユーザーの予約を更新する権限がありません。');
        }

        // 対象ユーザーが指定部屋に所属しているか確認（他部屋への不正書き込みを防ぐ）
        $belongsToRoom = $userGroupTable->exists([
            'i_id_user' => $targetUserId,
            'i_id_room' => $roomId,
        ]);
        if (!$belongsToRoom) {
            throw new UnauthorizedException('対象ユーザーは指定された部屋に所属していません。');
        }

        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateStr)) {
            throw new InvalidInputException('Invalid date format (Y-m-d).');
        }
        try {
            new Date($dateStr);
        } catch (\Throwable $e) {
            throw new InvalidInputException('Invalid date value.');
        }
        if (!in_array($meal, [1, 2, 3, 4], true)) {
            throw new InvalidInputException('Invalid meal type (1..4).');
        }
        if (!in_array($value, [0, 1], true)) {
            throw new InvalidInputException('Invalid value (0 or 1).');
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

        if (!$isAdmin && $isLastMinute && $targetUserLevel === 0 && $value === 0 && $exists) {
            throw new UnauthorizedException('職員は直前編集でのキャンセルはできません。');
        }

        $changeFlag = $value;
        $eatFlag    = $this->resolveEatFlag($value, $isLastMinute, $exists, $targetUserId, $roomId, $dateStr, $meal);

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
                'value'   => (bool)($result['value'] ?? false),
                'details' => $result['details'] ?? [],
            ];
        } catch (\Cake\ORM\Exception\PersistenceFailedException $e) {
            $errors = $e->getEntity()?->getErrors() ?? [];
            $flat   = json_encode($errors, JSON_UNESCAPED_UNICODE);

            $isMealConflict       = is_string($flat) && preg_match('/(昼|弁|bento|lunch|unique.*bento|unique.*lunch)/ui', $flat);
            $isOptimisticConflict = is_string($flat) && preg_match('/(conflict|optimistic)/ui', $flat);
            $message = match(true) {
                $isMealConflict       => '昼食と弁当は同時に予約できません。',
                $isOptimisticConflict => '他の操作と競合しました。画面を再読み込みして再実行してください。',
                default               => 'Validation failed.',
            };

            if ($isMealConflict || $isOptimisticConflict) {
                throw new ConflictException($message);
            }
            throw new InvalidInputException($message);
        } catch (\InvalidArgumentException $e) {
            throw new InvalidInputException($e->getMessage());
        } catch (\Throwable $e) {
            Log::error('toggle処理で予期しない例外: ' . $e->getMessage());
            throw new PersistenceException('Internal Server Error');
        }
    }

    /**
     * isLastMinute・既存有無・選択値から eat_flag を決定する。
     *
     * - 直前編集かつ既存レコードあり: DB の現在値を維持（変更不可）
     * - 直前編集かつ既存レコードなし: 0 固定（当日分は未実食扱い）
     * - 通常編集: $value をそのまま使用
     */
    private function resolveEatFlag(
        int    $value,
        bool   $isLastMinute,
        bool   $exists,
        int    $targetUserId,
        int    $roomId,
        string $dateStr,
        int    $meal
    ): int {
        if (!$isLastMinute) {
            return $value;
        }

        if (!$exists) {
            return 0;
        }

        $entity = $this->reservationTable->find()
            ->select(['eat_flag'])
            ->where([
                'i_id_user'          => $targetUserId,
                'i_id_room'          => $roomId,
                'd_reservation_date' => $dateStr,
                'i_reservation_type' => $meal,
            ])
            ->first();

        return $entity ? (int)$entity->eat_flag : 0;
    }

    // -------------------------------------------------------------------------
    // 共通ヘルパー
    // -------------------------------------------------------------------------

    /**
     * JSON 入力のバリデーションとデコードを行う。
     *
     * @throws \InvalidArgumentException 入力が空または不正な型の場合
     * @throws \JsonException JSON デコード失敗時
     */
    private function decodeJsonInput(array|string $jsonData): array
    {
        if (empty($jsonData) || (!is_string($jsonData) && !is_array($jsonData))) {
            throw new \InvalidArgumentException('入力データが無効です。');
        }
        if (is_array($jsonData)) {
            return $jsonData;
        }
        return json_decode($jsonData, true, 512, JSON_THROW_ON_ERROR);
    }

    // -------------------------------------------------------------------------
    // 個人予約ヘルパー
    // -------------------------------------------------------------------------

    /**
     * 食事区分ごとに選択された部屋IDを確定する（DB不要・純粋バリデーション）。
     *
     * @param array $meals    リクエストの meals データ
     * @param array $rooms    アクセス可能な部屋マップ
     * @return array          [mealType => roomId|null]
     * @throws \OverflowException  同一食事区分に複数部屋が選択された場合（→ 409）
     * @throws \DomainException    権限のない部屋が指定された場合（→ 403）
     */
    private function resolveSelectedRoomsPerMeal(array $meals, array $rooms): array
    {
        $selectedRoomPerMeal = [];
        foreach ($meals as $mealType => $selectedRooms) {
            $selectedRoomPerMeal[$mealType] = null;
            foreach ($selectedRooms as $roomId => $value) {
                $valueInt = is_bool($value) ? ($value ? 1 : 0) : (int)$value;
                if ($valueInt !== 1) {
                    continue;
                }
                if (isset($selectedRoomPerMeal[$mealType]) && $selectedRoomPerMeal[$mealType] !== $roomId) {
                    throw new \OverflowException("同じ食事区分に対して複数の部屋を選択することはできません。");
                }
                if (!array_key_exists($roomId, $rooms)) {
                    throw new \DomainException('選択された部屋は権限がありません。');
                }
                $selectedRoomPerMeal[$mealType] = $roomId;
            }
        }
        return $selectedRoomPerMeal;
    }

    /**
     * 指定ユーザー・日付の既存予約を取得し、2種のマップを返す。
     *
     * @return array{byRoom: array, byMeal: array}
     */
    private function buildIndividualExistingMaps(string $date, int $userId): array
    {
        $rows = $this->reservationTable->find()
            ->enableAutoFields(false)
            ->select(['i_id_user', 'd_reservation_date', 'i_reservation_type', 'i_id_room', 'eat_flag', 'i_change_flag', 'i_version'])
            ->where([
                'i_id_user'             => $userId,
                'd_reservation_date'    => $date,
                'i_reservation_type IN' => [1, 2, 3, 4],
            ])
            ->all();

        $byRoom = [];
        $byMeal = [];
        foreach ($rows as $row) {
            $byRoom[(int)$row->i_reservation_type][(int)$row->i_id_room] = $row;
            $byMeal[(int)$row->i_reservation_type][]                     = $row;
        }
        return ['byRoom' => $byRoom, 'byMeal' => $byMeal];
    }

    /**
     * 個人予約の eat_flag 変更・新規作成エンティティを生成する。
     *
     * @return array{toSave: array, duplicates: array, performed: bool}
     * @throws \RuntimeException 楽観的ロック競合時（→ 409）
     */
    private function applyIndividualMealChanges(
        array  $selectedRoomPerMeal,
        array  $existingByMeal,
        array  $existingMap,
        string $reservationDate,
        int    $userId,
        string $userName
    ): array {
        $toSave     = [];
        $duplicates = [];
        $performed  = false;

        foreach ($selectedRoomPerMeal as $mealType => $roomId) {
            if ($roomId === null) {
                foreach ($existingByMeal[(int)$mealType] ?? [] as $row) {
                    if ((int)$row->eat_flag !== 1) {
                        continue;
                    }
                    if (!$this->updateReservationRowWithVersion($row, ['eat_flag' => 0, 'i_change_flag' => 0, 'c_update_user' => $userName, 'dt_update' => DateTime::now()])) {
                        throw new \RuntimeException('他の操作と競合しました。画面を再読み込みして再実行してください。');
                    }
                    $performed = true;
                }
                continue;
            }

            foreach ($existingByMeal[(int)$mealType] ?? [] as $row) {
                if ((int)$row->i_id_room === (int)$roomId || (int)$row->eat_flag !== 1) {
                    continue;
                }
                if (!$this->updateReservationRowWithVersion($row, ['eat_flag' => 0, 'i_change_flag' => 0, 'c_update_user' => $userName, 'dt_update' => DateTime::now()])) {
                    throw new \RuntimeException('他の操作と競合しました。画面を再読み込みして再実行してください。');
                }
                $performed = true;
            }

            $existing = $existingMap[(int)$mealType][(int)$roomId] ?? null;

            if ($existing) {
                if ((int)$existing->eat_flag === 0) {
                    if (!$this->updateReservationRowWithVersion($existing, ['eat_flag' => 1, 'i_change_flag' => 1, 'c_update_user' => $userName, 'dt_update' => DateTime::now()])) {
                        throw new \RuntimeException('他の操作と競合しました。画面を再読み込みして再実行してください。');
                    }
                    $performed = true;
                } else {
                    $duplicates[] = ['reservation_date' => $reservationDate, 'meal_type' => $mealType, 'room_id' => $roomId];
                }
                continue;
            }

            $toSave[] = $this->reservationTable->patchEntity($this->reservationTable->newEmptyEntity(), [
                'i_id_user'          => $userId,
                'd_reservation_date' => $reservationDate,
                'i_id_room'          => $roomId,
                'i_reservation_type' => $mealType,
                'eat_flag'           => 1,
                'i_change_flag'      => 1,
                'i_version'          => 1,
                'c_create_user'      => $userName,
                'dt_create'          => DateTime::now(),
            ]);
        }

        return ['toSave' => $toSave, 'duplicates' => $duplicates, 'performed' => $performed];
    }

    /**
     * 変更後の最終予約状態（朝/昼/夕/弁当）を返す。
     *
     * @return array{breakfast: bool, lunch: bool, dinner: bool, bento: bool}
     */
    private function buildFinalReservationStates(string $date, int $userId): array
    {
        $states   = ['breakfast' => false, 'lunch' => false, 'dinner' => false, 'bento' => false];
        $type2key = [1 => 'breakfast', 2 => 'lunch', 3 => 'dinner', 4 => 'bento'];

        $rows = $this->reservationTable->find()
            ->select(['i_reservation_type', 'eat_flag'])
            ->where(['i_id_user' => $userId, 'd_reservation_date' => $date])
            ->all();

        foreach ($rows as $r) {
            $k = $type2key[(int)$r->i_reservation_type] ?? null;
            if ($k) {
                $states[$k] = ((int)$r->eat_flag === 1);
            }
        }
        return $states;
    }

    // -------------------------------------------------------------------------
    // グループ予約ヘルパー
    // -------------------------------------------------------------------------

    /**
     * 複数ユーザー・日付の既存予約を [userId][mealType][roomId] マップで返す。
     */
    private function buildGroupExistingMap(string $date, array $userIds): array
    {
        if (empty($userIds)) {
            return [];
        }
        $rows = $this->reservationTable->find()
            ->enableAutoFields(false)
            ->select(['i_id_user', 'd_reservation_date', 'i_reservation_type', 'i_id_room', 'eat_flag', 'i_change_flag', 'i_version'])
            ->where([
                'd_reservation_date'    => $date,
                'i_id_user IN'          => $userIds,
                'i_reservation_type IN' => [1, 2, 3, 4],
            ])
            ->all();

        $map = [];
        foreach ($rows as $row) {
            $map[(int)$row->i_id_user][(int)$row->i_reservation_type][(int)$row->i_id_room] = $row;
        }
        return $map;
    }

    /** @return array<int, string> userId => c_user_name */
    private function fetchUserNames(array $userIds): array
    {
        if (empty($userIds)) {
            return [];
        }
        $map = [];
        foreach ($this->userTable->find()->enableAutoFields(false)->select(['i_id_user', 'c_user_name'])->where(['i_id_user IN' => $userIds])->all() as $row) {
            $map[(int)$row->i_id_user] = $row->c_user_name;
        }
        return $map;
    }

    /** @return array<int, string> roomId => c_room_name */
    private function fetchRoomNames(array $roomIds): array
    {
        if (empty($roomIds)) {
            return [];
        }
        $map = [];
        foreach ($this->roomTable->find()->enableAutoFields(false)->select(['i_id_room', 'c_room_name'])->where(['i_id_room IN' => $roomIds])->all() as $row) {
            $map[(int)$row->i_id_room] = $row->c_room_name;
        }
        return $map;
    }

    /** @return array<int, int> userId => i_user_level */
    private function fetchUserLevels(array $userIds): array
    {
        if (empty($userIds)) {
            return [];
        }
        $map = [];
        foreach ($this->userTable->find()->enableAutoFields(false)->select(['i_id_user', 'i_user_level'])->where(['i_id_user IN' => $userIds])->all() as $row) {
            $map[(int)$row->i_id_user] = (int)$row->i_user_level;
        }
        return $map;
    }

    /**
     * グループ予約の eat_flag 変更・新規作成エンティティを生成する。
     *
     * @return array{toSave: array, duplicates: array}
     * @throws \DomainException    権限のない部屋が指定された場合（→ 403）
     * @throws \RuntimeException   楽観的ロック競合時（→ 409）
     */
    private function applyGroupMealChanges(
        array    $users,
        array    $rooms,
        int|null $roomId,
        array    $existingMap,
        array    $userNameMap,
        array    $roomNameMap,
        string   $reservationDate,
        string   $creatorName
    ): array {
        $mealTypeNames = ['1' => '朝食', '2' => '昼食', '3' => '夕食', '4' => '弁当'];
        $toSave        = [];
        $duplicates    = [];

        foreach ($users as $targetUserId => $meals) {
            foreach ($meals as $mealType => $selected) {
                $valueInt = is_bool($selected) ? ($selected ? 1 : 0) : (int)$selected;

                if (!isset($rooms[$roomId])) {
                    throw new \DomainException('選択された部屋は権限がありません。');
                }

                if ($valueInt !== 1) {
                    $existing = $existingMap[(int)$targetUserId][(int)$mealType][(int)$roomId] ?? null;
                    if ($existing && (int)$existing->eat_flag === 1) {
                        if (!$this->updateReservationRowWithVersion($existing, ['eat_flag' => 0, 'i_change_flag' => 0, 'c_update_user' => $creatorName, 'dt_update' => DateTime::now()])) {
                            throw new \RuntimeException('他の操作と競合しました。画面を再読み込みして再実行してください。');
                        }
                    }
                    continue;
                }

                $userMealRows = $existingMap[(int)$targetUserId][(int)$mealType] ?? [];
                $existing     = $userMealRows[(int)$roomId] ?? null;

                foreach ($userMealRows as $existingRoomId => $row) {
                    if ((int)$existingRoomId === (int)$roomId || (int)$row->eat_flag !== 1) {
                        continue;
                    }
                    if (!$this->updateReservationRowWithVersion($row, ['eat_flag' => 0, 'i_change_flag' => 0, 'c_update_user' => $creatorName, 'dt_update' => DateTime::now()])) {
                        throw new \RuntimeException('他の操作と競合しました。画面を再読み込みして再実行してください。');
                    }
                }

                if ($existing) {
                    if ((int)$existing->eat_flag === 0) {
                        if (!$this->updateReservationRowWithVersion($existing, ['eat_flag' => 1, 'i_change_flag' => 1, 'c_update_user' => $creatorName, 'dt_update' => DateTime::now()])) {
                            throw new \RuntimeException('他の操作と競合しました。画面を再読み込みして再実行してください。');
                        }
                        continue;
                    }
                    $duplicates[] = [
                        'user_name' => $userNameMap[(int)$targetUserId] ?? '不明なユーザー名',
                        'meal_type' => $mealTypeNames[$mealType] ?? $mealType,
                        'room_name' => $roomNameMap[(int)$existing->i_id_room] ?? '不明な部屋名',
                    ];
                    continue;
                }

                $toSave[] = $this->reservationTable->patchEntity($this->reservationTable->newEmptyEntity(), [
                    'i_id_user'          => $targetUserId,
                    'd_reservation_date' => $reservationDate,
                    'i_id_room'          => $roomId,
                    'i_reservation_type' => $mealType,
                    'eat_flag'           => 1,
                    'i_change_flag'      => 1,
                    'i_version'          => 1,
                    'c_create_user'      => $creatorName,
                    'dt_create'          => DateTime::now(),
                ]);
            }
        }

        return ['toSave' => $toSave, 'duplicates' => $duplicates];
    }

    private function ok(string $message, array $data = [], ?string $redirect = null): array
    {
        return ['ok' => true, 'message' => $message, 'data' => $data, 'redirect' => $redirect];
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
                'd_reservation_date' => $row->d_reservation_date instanceof Date
                    ? $row->d_reservation_date->format('Y-m-d')
                    : (string)$row->d_reservation_date,
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
        ReservationReportService::invalidateMealCountsCache($date);
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
