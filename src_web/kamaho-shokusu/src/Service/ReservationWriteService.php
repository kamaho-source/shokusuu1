<?php
declare(strict_types=1);

namespace App\Service;

use App\Application\UseCase\ChangeMealRoom\ChangeMealRoomInput;
use App\Application\UseCase\ToggleMeal\ToggleMealInput;
use App\Domain\Exception\ConflictException;
use App\Domain\Exception\DomainException;
use App\Domain\Exception\InvalidInputException;
use App\Domain\Exception\PersistenceException;
use App\Domain\Exception\UnauthorizedException;
use App\Domain\ValueObject\MealType;
use App\Domain\ValueObject\UserRole;
use App\Infrastructure\Cache\ReservationCache;
use App\Infrastructure\Persistence\SystemError;
use App\Infrastructure\ReservationUseCases;
use App\Model\Table\TIndividualReservationInfoTable;
use Cake\I18n\DateTime;
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
            $changes            = $this->applyIndividualMealChanges($selectedRoomPerMeal, $existingByMeal, $existingMap, $reservationDate, $userId, $userName, $rooms);
            $duplicates         = $changes['duplicates'];
            $operationPerformed = $changes['performed'];

            if (!empty($changes['toSave'])) {
                try {
                    $this->reservationTable->saveManyOrFail($changes['toSave']);
                } catch (\Cake\ORM\Exception\PersistenceFailedException $e) {
                    $connection->rollback();
                    Log::error('個人予約 saveManyOrFail エラー: ' . json_encode($e->getEntity()?->getErrors() ?? [], JSON_UNESCAPED_UNICODE));
                    $lunchBento = TIndividualReservationInfoTable::lunchBentoViolation($e);
                    if ($lunchBento !== null) {
                        throw new ConflictException($lunchBento);
                    }
                    $detail = Configure::read('debug') ? ' 詳細: ' . implode('、', array_merge(...array_values(array_map('array_values', $e->getEntity()?->getErrors() ?? [[]])))) : '';
                    throw new PersistenceException('予約の登録中にエラーが発生しました。' . $detail);
                }
                $operationPerformed = true;
            }
            $connection->commit();
        } catch (\App\Domain\Exception\DomainException $e) {
            if ($connection->inTransaction()) {
                $connection->rollback();
            }
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

    /**
     * グループ予約（1部屋 × 1日 × 複数利用者）を保存する。
     *
     * @throws \App\Domain\Exception\InvalidInputException 入力・日付が不正な場合
     * @throws \App\Domain\Exception\UnauthorizedException 権限のない部屋を指定した場合
     * @throws \App\Domain\Exception\ConflictException 競合・昼食と弁当の同時予約になる場合
     * @throws \App\Domain\Exception\PersistenceException 保存に失敗した場合
     */
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

        $isLoginStaff    = UserRole::isStaffLevel($loginUserLevel);
        $targetUserLevels = $this->fetchUserLevels($userIds);
        $blockLeaderInRoom = false;
        if ($isBlockLeader && $roomId !== null) {
            $blockLeaderInRoom = $this->isActiveMember($loginUserId, $roomId);
        }
        foreach ($userIds as $targetUserId) {
            $targetUserLevel = $targetUserLevels[$targetUserId] ?? 0;
            $canEditOther    = $isAdmin || ($isLoginStaff && $targetUserLevel === 1) || $blockLeaderInRoom;
            if ($targetUserId !== $loginUserId && !$canEditOther) {
                throw new UnauthorizedException('他ユーザーの予約を更新する権限がありません。');
            }
            if ($roomId !== null) {
                // 他人の予約を扱うには、操作者本人も部屋に有効所属していること（管理者も同じ。#821）
                if ($targetUserId !== $loginUserId && !$this->isActiveMember($loginUserId, $roomId)) {
                    throw new UnauthorizedException('この部屋を操作する権限がありません。');
                }
                // 登録（1を含む）は対象（本人を含む）が有効所属していること。取消のみなら所属は要求しない。
                $wantsRegister = false;
                foreach ((array)($data['users'][$targetUserId] ?? []) as $selected) {
                    if ((is_bool($selected) ? ($selected ? 1 : 0) : (int)$selected) === 1) {
                        $wantsRegister = true;
                        break;
                    }
                }
                if ($wantsRegister && !$this->isActiveMember($targetUserId, $roomId)) {
                    throw new UnauthorizedException('対象ユーザーは指定された部屋に所属していません。');
                }
            }
        }

        $userNameMap = $this->fetchUserNames($userIds);

        $duplicates = [];
        $allRoomIds = [];
        $connection = $this->reservationTable->getConnection();
        $connection->begin();
        try {
            $existingMap = $this->buildGroupExistingMap($reservationDate, $userIds);

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

            $changes    = $this->applyGroupMealChanges($data['users'], $rooms, $roomId, $existingMap, $userNameMap, $roomNameMap, $reservationDate, $creatorName);
            $duplicates = $changes['duplicates'];

            if (!empty($changes['toSave'])) {
                try {
                    $this->reservationTable->saveManyOrFail($changes['toSave']);
                } catch (\Cake\ORM\Exception\PersistenceFailedException $e) {
                    $connection->rollback();
                    Log::error('グループ予約 saveManyOrFail エラー: ' . json_encode($e->getEntity()?->getErrors() ?? [], JSON_UNESCAPED_UNICODE));
                    // 昼食と弁当の同時予約は、理由のない 500 にせず 409 で理由を返す（個人予約と同じ）
                    $lunchBento = TIndividualReservationInfoTable::lunchBentoViolation($e);
                    if ($lunchBento !== null) {
                        throw new ConflictException($lunchBento);
                    }
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

    /** @var array<string, bool> リクエスト内の所属判定キャッシュ（bulk-toggle の件数ぶんの再問い合わせを避ける） */
    private ?ReservationUseCases $useCases = null;

    /** 予約の書き込みの UseCase（Repository を共有するので、所属の判定はリクエストの中で使い回される）。 */
    private function useCases(): ReservationUseCases
    {
        /** @var \App\Model\Table\TIndividualReservationInfoTable $reservations */
        $reservations = $this->reservationTable;

        return $this->useCases ??= new ReservationUseCases($reservations, $this->userTable);
    }

    private function isRoomAvailable(int $roomId): bool
    {
        return $this->useCases()->rooms()->isRoomAvailable($roomId);
    }

    private function isActiveMember(int $userId, int $roomId): bool
    {
        return $this->useCases()->rooms()->isActiveMember($userId, $roomId);
    }

    /**
     * 予約行を ON/OFF するときに書くフラグ。直前期間は i_change_flag だけを変え、当初予定（eat_flag）は残す
     * （toggle・直前編集と同じ扱い）。それ以外は eat_flag と i_change_flag をそろえる。
     *
     * @return array{i_change_flag: int, eat_flag?: int}
     */
    private static function mealFlags(string $date, bool $on): array
    {
        $flag = $on ? 1 : 0;

        return ['i_change_flag' => $flag] + (TIndividualReservationInfoTable::isLastMinute($date) ? [] : ['eat_flag' => $flag]);
    }

    /**
     * 部屋IDの一覧を「部屋名」の並びにする（メッセージ用）。見つからない部屋は ID のまま出す。
     *
     * @param list<int> $roomIds
     */
    private function roomNamesText(array $roomIds): string
    {
        $names = $this->roomTable->find('list', keyField: 'i_id_room', valueField: 'c_room_name')
            ->where(['i_id_room IN' => $roomIds])
            ->toArray();

        return '「' . implode('」「', array_map(static fn(int $id): string => (string)($names[$id] ?? $id), $roomIds)) . '」';
    }

    /**
     * 1件の食事予約を登録・取消する（トグル）。
     *
     * 他人の予約を扱うには、操作者本人も部屋に有効所属していること（管理者も同じ。#821）。
     * $adminAnyRoom が true のとき（エクセル食数予約の一括保存）だけ、管理者・システム管理者は
     * 所属外の部屋も操作できる。対象者の所属・削除済み部屋・過去日・直前・二重予約の判定は変わらない。
     *
     * @param int    $roomId          部屋 ID
     * @param array  $payload         date, meal, value, userId（省略時はログインユーザー）
     * @param int    $loginUserId     操作者のユーザー ID
     * @param string $loginUserName   操作者名
     * @param bool   $invalidateCache 処理後にキャッシュを無効化するか
     * @param bool   $adminAnyRoom    管理者が所属外の部屋を操作できるか（エクセル食数予約のみ true）
     * @return array 処理結果（value, details, affected）
     * @throws DomainException 入力不正・権限不足・所属外・競合など
     * @throws PersistenceException 予期しない保存エラー
     */
    public function processToggle(
        int $roomId,
        array $payload,
        int $loginUserId,
        string $loginUserName,
        bool $invalidateCache = true,
        bool $adminAnyRoom = false
    ): array {
        return $this->useCases()->toggleMeal()
            ->execute(ToggleMealInput::fromPayload($roomId, $payload, $loginUserId, $loginUserName, $invalidateCache, $adminAnyRoom))
            ->toArray();
    }

    /**
     * 複数の予約トグルを1回のリクエストでまとめて処理する（エクセル食数予約の一括登録用）。
     *
     * 各 item を processToggle で順次処理し、成否を item 単位で記録する。
     * 1件の失敗（競合・権限など）が他の件に影響しないよう、item ごとに例外を捕捉する
     * （従来の「セルごとに個別リクエスト」と同じ“一部成功・一部失敗”の挙動を維持する）。
     * キャッシュ無効化は件数分ではなく、影響した日付ごとに最後へまとめて1回だけ実行する。
     * エクセル食数予約専用のため、管理者・システム管理者は所属外の部屋も操作できる（processToggle の $adminAnyRoom）。
     *
     * @param array<int, array{roomId?:int,i_id_room?:int,userId?:int,date?:string,meal?:int,value?:int}> $items
     * @param int    $loginUserId
     * @param string $loginUserName
     * @return array<int, array{ok:bool,value?:bool,details?:array,message?:string}> item と同じ順序の結果配列
     */
    public function processBulkToggle(array $items, int $loginUserId, string $loginUserName): array
    {
        return $this->useCases()->bulkToggleMeals()->execute($items, $loginUserId, $loginUserName);
    }

    /**
     * 予約済みの食事を別の部屋へ移す（旧部屋を取消 → 新部屋で登録を1トランザクションで）。ChangeMealRoomUseCase を使う。
     *
     * @param array{date:string, meal:int, userId?:int} $payload
     * @throws DomainException 権限不足・所属外・競合・入力不正など
     */
    public function processRoomChange(
        int $fromRoomId,
        int $toRoomId,
        array $payload,
        int $loginUserId,
        string $loginUserName
    ): void {
        $this->useCases()->changeMealRoom()->execute(new ChangeMealRoomInput(
            date: (string)($payload['date'] ?? ''),
            meal: (int)($payload['meal'] ?? 0),
            fromRoomId: $fromRoomId,
            toRoomId: $toRoomId,
            loginUserId: $loginUserId,
            loginUserName: $loginUserName,
            targetUserId: isset($payload['userId']) ? (int)$payload['userId'] : $loginUserId,
        ));
    }

    /**
     * 予期しない例外を、画面・監査ログに出してよい短い原因に変換する。
     * 生のメッセージ（SQL 文・テーブル名など）は含めない。
     * エラーコードは DB 由来なら「DB-<MySQL エラー番号>」、それ以外は「SYS-0000」。
     *
     * @param \Throwable $e
     * @return string 例: 「他の操作と同時に更新されたため。もう一度お試しください [エラーコード: DB-1213]」
     */
    public static function describeSystemError(\Throwable $e): string
    {
        return SystemError::describe($e);
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
                'i_reservation_type IN' => MealType::values(),
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
     * @throws \App\Domain\Exception\ConflictException 画面外の部屋の予約を移す・取り消す必要があるとき（理由付き）
     * @throws \App\Domain\Exception\UnauthorizedException 職員が直前期間に取消・部屋変更をしようとしたとき
     */
    private function applyIndividualMealChanges(
        array  $selectedRoomPerMeal,
        array  $existingByMeal,
        array  $existingMap,
        string $reservationDate,
        int    $userId,
        string $userName,
        array  $rooms = []
    ): array {
        $toSave       = [];
        $duplicates   = [];
        $performed    = false;
        $outsideRooms = []; // 画面に出ていないため取り消さなかった予約の部屋

        // 取消（部屋が null）を先に行う。昼食⇔弁当の入れ替えで、取り消す前の相手と「同時に有効」と判定されないようにする
        uasort($selectedRoomPerMeal, static fn($a, $b): int => ($a !== null) <=> ($b !== null));
        // 職員は直前期間に取消・部屋変更ができない（processToggle・change-room と同じ。管理者は例外）
        $self = $this->userTable->find()->select(['i_user_level', 'i_admin'])->where(['i_id_user' => $userId])->first();
        $staffLastMinute = TIndividualReservationInfoTable::isLastMinute($reservationDate)
            && UserRole::isStaffLevel((int)($self?->i_user_level ?? -1))
            && !UserRole::isAdmin((int)($self?->i_admin ?? 0));

        foreach ($selectedRoomPerMeal as $mealType => $roomId) {
            if ($roomId === null) {
                foreach ($existingByMeal[(int)$mealType] ?? [] as $row) {
                    if (!TIndividualReservationInfoTable::isEffectiveRow($row)) {
                        continue;
                    }
                    // 取消は、この画面で扱う部屋の予約だけ（画面に出ていない部屋の予約を巻き込まない）
                    if (!array_key_exists((int)$row->i_id_room, $rooms)) {
                        $outsideRooms[(int)$row->i_id_room] = true;
                        continue;
                    }
                    if ($staffLastMinute) {
                        throw new UnauthorizedException('職員は直前編集でのキャンセルはできません。');
                    }
                    if (!$this->updateReservationRowWithVersion($row, self::mealFlags($reservationDate, false) + ['c_update_user' => $userName, 'dt_update' => DateTime::now()])) {
                        throw new \RuntimeException('他の操作と競合しました。画面を再読み込みして再実行してください。');
                    }
                    $performed = true;
                }
                continue;
            }

            foreach ($existingByMeal[(int)$mealType] ?? [] as $row) {
                if ((int)$row->i_id_room === (int)$roomId || !TIndividualReservationInfoTable::isEffectiveRow($row)) {
                    continue;
                }
                // 画面に出ていない部屋（退出済み・削除済みなど）に残った予約は、取消だけ許す。ここで移すと黙って取り消してしまう
                if (!array_key_exists((int)$row->i_id_room, $rooms)) {
                    throw new ConflictException($this->roomNamesText([(int)$row->i_id_room]) . 'に同じ食事の予約があります。この画面では変更できないため、先にその予約を取り消してください。');
                }
                // 別の部屋の予約を取り消してこの部屋へ移すのは部屋変更。職員は直前期間にはできない（change-room と同じ扱い）
                if ($staffLastMinute) {
                    throw new UnauthorizedException('直前期間（14日以内）は職員の部屋変更はできません。');
                }
                if (!$this->updateReservationRowWithVersion($row, self::mealFlags($reservationDate, false) + ['c_update_user' => $userName, 'dt_update' => DateTime::now()])) {
                    throw new \RuntimeException('他の操作と競合しました。画面を再読み込みして再実行してください。');
                }
                $performed = true;
            }

            // 昼食⇔弁当: 相手が画面に出ていない部屋で有効なら、入れ替え（相手の取消）はこの画面ではできない
            // すでにこの部屋で有効なまま（変更なし）の食事は見ない。以前の二重データで、関係のない保存まで止めないため
            $opponent = MealType::counterpartValue((int)$mealType);
            $alreadyEffectiveHere = isset($existingMap[(int)$mealType][(int)$roomId])
                && TIndividualReservationInfoTable::isEffectiveRow($existingMap[(int)$mealType][(int)$roomId]);
            if ($opponent !== null && !$alreadyEffectiveHere) {
                foreach ($existingByMeal[$opponent] ?? [] as $oppRow) {
                    if (TIndividualReservationInfoTable::isEffectiveRow($oppRow) && !array_key_exists((int)$oppRow->i_id_room, $rooms)) {
                        throw new ConflictException(sprintf(
                            '%sに%sの予約があるため、%sは予約できません（昼食と弁当は同時に予約できません）。先にその予約を取り消してください。',
                            $this->roomNamesText([(int)$oppRow->i_id_room]),
                            MealType::labelOf($opponent),
                            MealType::labelOf((int)$mealType)
                        ));
                    }
                }
            }

            $existing = $existingMap[(int)$mealType][(int)$roomId] ?? null;

            if ($existing) {
                if (!TIndividualReservationInfoTable::isEffectiveRow($existing)) {
                    if (!$this->updateReservationRowWithVersion($existing, self::mealFlags($reservationDate, true) + ['c_update_user' => $userName, 'dt_update' => DateTime::now()])) {
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
                'eat_flag'           => TIndividualReservationInfoTable::isLastMinute($reservationDate) ? 0 : 1,
                'i_change_flag'      => 1,
                'i_version'          => 1,
                'c_create_user'      => $userName,
                'dt_create'          => DateTime::now(),
            ]);
        }

        // 画面に出ていない部屋の予約だけを取り消そうとして、何も変えていないなら、システムエラーにせず理由を返す
        if (!$performed && $toSave === [] && $duplicates === [] && $outsideRooms !== []) {
            throw new ConflictException($this->roomNamesText(array_keys($outsideRooms)) . 'の予約は、この画面では取り消せません。その部屋の予約として取り消してください。');
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
            ->select(['i_reservation_type', 'eat_flag', 'i_change_flag'])
            ->where(['i_id_user' => $userId, 'd_reservation_date' => $date])
            ->all();

        // どの部屋かを問わず、有効な行が1つでもあれば予約あり（直前期間は i_change_flag で判定する）
        foreach ($rows as $r) {
            $k = $type2key[(int)$r->i_reservation_type] ?? null;
            if ($k && TIndividualReservationInfoTable::effectiveValue($date, $r->eat_flag, $r->i_change_flag) === 1) {
                $states[$k] = true;
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
                'i_reservation_type IN' => MealType::values(),
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
            if (!is_array($meals)) {
                continue;
            }
            // 取消を先に行う（昼食⇔弁当の入れ替えで、取り消す前の相手と「同時に有効」と判定されないようにする）
            uasort($meals, static fn($a, $b): int => ((int)$a === 1) <=> ((int)$b === 1));
            foreach ($meals as $mealType => $selected) {
                $valueInt = is_bool($selected) ? ($selected ? 1 : 0) : (int)$selected;

                if (!isset($rooms[$roomId])) {
                    throw new \DomainException('選択された部屋は権限がありません。');
                }

                if ($valueInt !== 1) {
                    $existing = $existingMap[(int)$targetUserId][(int)$mealType][(int)$roomId] ?? null;
                    if ($existing && TIndividualReservationInfoTable::isEffectiveRow($existing)) {
                        if (!$this->updateReservationRowWithVersion($existing, self::mealFlags($reservationDate, false) + ['c_update_user' => $creatorName, 'dt_update' => DateTime::now()])) {
                            throw new \RuntimeException('他の操作と競合しました。画面を再読み込みして再実行してください。');
                        }
                    }
                    continue;
                }

                $userMealRows = $existingMap[(int)$targetUserId][(int)$mealType] ?? [];
                $existing     = $userMealRows[(int)$roomId] ?? null;

                // 退出済み・削除済みの部屋に残った予約は、取消だけ許す。ここで移すと黙って部屋が変わるので、スキップして伝える
                // （この部屋ですでに有効な食事は変更なしなので見ない）
                $effectiveHere = isset($userMealRows[(int)$roomId]) && TIndividualReservationInfoTable::isEffectiveRow($userMealRows[(int)$roomId]);
                foreach ($userMealRows as $existingRoomId => $row) {
                    if (!$effectiveHere && (int)$existingRoomId !== (int)$roomId && TIndividualReservationInfoTable::isEffectiveRow($row)
                        && (!$this->isRoomAvailable((int)$existingRoomId) || !$this->isActiveMember((int)$targetUserId, (int)$existingRoomId))
                    ) {
                        $duplicates[] = [
                            'user_name' => $userNameMap[(int)$targetUserId] ?? '不明なユーザー名',
                            'meal_type' => $mealTypeNames[$mealType] ?? $mealType,
                            'room_name' => $roomNameMap[(int)$existingRoomId] ?? ('部屋' . (int)$existingRoomId),
                        ];
                        continue 2;
                    }
                }
                foreach ($userMealRows as $existingRoomId => $row) {
                    if ((int)$existingRoomId === (int)$roomId || !TIndividualReservationInfoTable::isEffectiveRow($row)) {
                        continue;
                    }
                    if (!$this->updateReservationRowWithVersion($row, self::mealFlags($reservationDate, false) + ['c_update_user' => $creatorName, 'dt_update' => DateTime::now()])) {
                        throw new \RuntimeException('他の操作と競合しました。画面を再読み込みして再実行してください。');
                    }
                }

                if ($existing) {
                    if (!TIndividualReservationInfoTable::isEffectiveRow($existing)) {
                        if (!$this->updateReservationRowWithVersion($existing, self::mealFlags($reservationDate, true) + ['c_update_user' => $creatorName, 'dt_update' => DateTime::now()])) {
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
                    'eat_flag'           => TIndividualReservationInfoTable::isLastMinute($reservationDate) ? 0 : 1,
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

    /**
     * 承認済み保護つきの共通更新（TIndividualReservationInfoTable に集約）。
     *
     * @param array{eat_flag?: int, i_change_flag?: int, i_id_room?: int, c_update_user?: string, dt_update?: \Cake\I18n\DateTime} $updateFields
     * @return bool false = 楽観的ロック競合
     * @throws \App\Exception\ApprovedReservationException 承認済み行を更新しようとした場合
     * @throws \App\Exception\DuplicateMealReservationException 更新で有効になる行が、他の部屋の有効な予約と重なる場合
     */
    private function updateReservationRowWithVersion(object $row, array $updateFields): bool
    {
        return $this->reservationTable->updateRowWithVersion($row, $updateFields);
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
        ReservationCache::forgetDate($date, $userIds);
        ReservationCache::bumpVersion();
    }
}
