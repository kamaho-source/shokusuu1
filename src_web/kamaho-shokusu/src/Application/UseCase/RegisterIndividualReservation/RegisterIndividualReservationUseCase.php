<?php
declare(strict_types=1);

namespace App\Application\UseCase\RegisterIndividualReservation;

use App\Application\Port\ReservationCacheInterface;
use App\Application\Port\TransactionManagerInterface;
use App\Domain\Entity\MealReservation;
use App\Domain\Exception\ConflictException;
use App\Domain\Exception\DomainException;
use App\Domain\Exception\InvalidInputException;
use App\Domain\Exception\PersistenceException;
use App\Domain\Exception\ReservationValidationException;
use App\Domain\Exception\UnauthorizedException;
use App\Domain\Repository\ReservationRepositoryInterface;
use App\Domain\Repository\RoomMembershipRepositoryInterface;
use App\Domain\Repository\UserRepositoryInterface;
use App\Domain\Service\ReservationDateRule;
use App\Domain\ValueObject\MealType;
use App\Domain\ValueObject\UserRole;
use Cake\Chronos\ChronosDate;
use Psr\Log\LoggerInterface;

/**
 * 個人予約を保存する（1人 × 1日。食事区分ごとに1部屋を選ぶ）。
 *
 * - 選ばなかった食事は、画面で扱う部屋の予約だけを取り消す（画面に出ていない部屋の予約を巻き込まない）。
 * - 別の部屋の予約を取り消してこの部屋へ移すのは部屋変更。職員は直前期間にはできない（管理者は例外）。
 * - すでに有効な予約はスキップして伝える。
 */
final class RegisterIndividualReservationUseCase
{
    private const CONFLICT_MESSAGE = '他の操作と競合しました。画面を再読み込みして再実行してください。';

    public function __construct(
        private readonly ReservationRepositoryInterface $reservations,
        private readonly RoomMembershipRepositoryInterface $rooms,
        private readonly UserRepositoryInterface $users,
        private readonly TransactionManagerInterface $transaction,
        private readonly ReservationCacheInterface $cache,
        private readonly LoggerInterface $logger,
        /** true なら保存失敗の詳細（検証エラーの文言）を利用者に返す（デバッグ時のみ） */
        private readonly bool $showErrorDetail = false,
    ) {}

    /**
     * @throws InvalidInputException 入力・日付が不正な場合
     * @throws UnauthorizedException 権限のない部屋を指定した場合、職員が直前期間に取消・部屋変更をしようとした場合
     * @throws ConflictException 競合・昼食と弁当の同時予約・画面外の部屋の予約を変える必要がある場合
     * @throws PersistenceException 保存に失敗した場合
     */
    public function execute(RegisterIndividualReservationInput $input): RegisterIndividualReservationOutput
    {
        $reservationDate = $input->reservationDate;
        $userId          = $input->userId;

        try {
            $data = self::decodeJsonInput($input->data);
        } catch (\InvalidArgumentException $e) {
            $this->logger->error($e->getMessage());
            throw new InvalidInputException($e->getMessage(), 400);
        } catch (\JsonException $e) {
            $this->logger->error('JSONデコードエラー: ' . $e->getMessage());
            throw new InvalidInputException('データの形式が不正です。', 400);
        }

        if (!isset($data['meals']) || !is_array($data['meals'])) {
            $this->logger->error('データ構造が不正: "meals" キーが存在しない、または配列ではありません。データ: ' . json_encode($data));
            throw new InvalidInputException('データ構造が不正です。');
        }

        if ($input->dateError !== null) {
            $this->logger->error('日付検証エラー: ' . $input->dateError);
            throw new InvalidInputException($input->dateError);
        }

        try {
            $selectedRoomPerMeal = self::resolveSelectedRoomsPerMeal($data['meals'], $input->rooms);
        } catch (\OverflowException $e) {
            $this->logger->error($e->getMessage());
            throw new ConflictException($e->getMessage());
        } catch (\DomainException $e) {
            $this->logger->error($e->getMessage());
            throw new UnauthorizedException($e->getMessage());
        }

        $today      = ChronosDate::today('Asia/Tokyo');
        $duplicates = [];
        $this->transaction->begin();
        try {
            ['byRoom' => $existingMap, 'byMeal' => $existingByMeal] = $this->buildIndividualExistingMaps($reservationDate, $userId);
            $changes            = $this->applyIndividualMealChanges($selectedRoomPerMeal, $existingByMeal, $existingMap, $reservationDate, $userId, $input->userName, $input->rooms, $today);
            $duplicates         = $changes['duplicates'];
            $operationPerformed = $changes['performed'];

            if ($changes['toSave'] !== []) {
                $this->saveNewRows($changes['toSave'], $changes['hasNonIntegerMeal'], $input->userName);
                $operationPerformed = true;
            }
            $this->transaction->commit();
        } catch (DomainException $e) {
            if ($this->transaction->inTransaction()) {
                $this->transaction->rollback();
            }
            throw $e;
        } catch (\RuntimeException $e) {
            // DB の失敗など（従来どおり、理由をそのまま競合として返す）
            $this->transaction->rollback();
            throw new ConflictException($e->getMessage());
        } catch (\Throwable $e) {
            if ($this->transaction->inTransaction()) {
                $this->transaction->rollback();
            }
            $this->logger->error('個人予約処理で予期しない例外: ' . $e->getMessage());
            throw new PersistenceException('予約処理中に内部エラーが発生しました。');
        }

        $this->cache->forgetDate($reservationDate, [$userId]);

        $finalStates = $this->buildFinalReservationStates($reservationDate, $userId, $today);

        if ($duplicates !== []) {
            return new RegisterIndividualReservationOutput('一部の予約は既に存在するため、スキップされました。', [
                'skipped' => $duplicates,
                'details' => $finalStates,
                'date'    => $reservationDate,
            ]);
        }

        if ($operationPerformed) {
            return new RegisterIndividualReservationOutput('個人予約が正常に登録されました。', [
                'details' => $finalStates,
                'date'    => $reservationDate,
            ]);
        }

        throw new PersistenceException('システムエラーが発生しました。');
    }

    /**
     * 新しい予約行を保存する。検証ルールに当たったら、取り消して理由を返す。
     *
     * @param list<MealReservation> $toSave
     * @param bool $hasNonIntegerMeal 食事区分に整数でない値があったか（従来は保存時の検証で失敗していたため、同じく保存の失敗にする）
     * @throws ConflictException 昼食と弁当の同時予約・別の部屋の予約と重なる場合
     * @throws PersistenceException それ以外の検証エラー
     */
    private function saveNewRows(array $toSave, bool $hasNonIntegerMeal, string $userName): void
    {
        try {
            if ($hasNonIntegerMeal) {
                throw new ReservationValidationException(null, ['食事区分が正しくありません。'], '{"i_reservation_type":{"integer":"食事区分が正しくありません。"}}');
            }
            $this->reservations->addMany($toSave, $userName);
        } catch (ReservationValidationException $e) {
            $this->transaction->rollback();
            $this->logger->error('個人予約 saveManyOrFail エラー: ' . $e->getMessage());
            if ($e->lunchBentoMessage !== null) {
                throw new ConflictException($e->lunchBentoMessage);
            }
            $detail = $this->showErrorDetail ? ' 詳細: ' . implode('、', $e->errorMessages) : '';
            throw new PersistenceException('予約の登録中にエラーが発生しました。' . $detail);
        }
    }

    /**
     * JSON 入力のバリデーションとデコードを行う。
     *
     * @param array<array-key, mixed>|string $jsonData
     * @return array<array-key, mixed>
     * @throws \InvalidArgumentException 入力が空または不正な型の場合
     * @throws \JsonException JSON デコード失敗時
     */
    private static function decodeJsonInput(array|string $jsonData): array
    {
        if (empty($jsonData)) {
            throw new \InvalidArgumentException('入力データが無効です。');
        }
        if (is_array($jsonData)) {
            return $jsonData;
        }

        return json_decode($jsonData, true, 512, JSON_THROW_ON_ERROR);
    }

    /**
     * 食事区分ごとに選択された部屋IDを確定する（DB不要・純粋バリデーション）。
     *
     * @param array<array-key, mixed> $meals リクエストの meals データ
     * @param array<array-key, mixed> $rooms アクセス可能な部屋マップ
     * @return array<array-key, int|string|null> [mealType => roomId|null]
     * @throws \OverflowException 同一食事区分に複数部屋が選択された場合（→ 409）
     * @throws \DomainException   権限のない部屋が指定された場合（→ 403）
     */
    private static function resolveSelectedRoomsPerMeal(array $meals, array $rooms): array
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
                    throw new \OverflowException('同じ食事区分に対して複数の部屋を選択することはできません。');
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
     * @return array{byRoom: array<int, array<int, MealReservation>>, byMeal: array<int, list<MealReservation>>}
     */
    private function buildIndividualExistingMaps(string $date, int $userId): array
    {
        $byRoom = [];
        $byMeal = [];
        foreach ($this->reservations->findByUsersAndDates([$date], [$userId], MealType::values()) as $row) {
            $byRoom[$row->mealType][$row->roomId] = $row;
            $byMeal[$row->mealType][]             = $row;
        }

        return ['byRoom' => $byRoom, 'byMeal' => $byMeal];
    }

    /**
     * 個人予約の取消・有効化を行い、新しく保存する行を返す。
     *
     * @param array<array-key, int|string|null>           $selectedRoomPerMeal
     * @param array<int, list<MealReservation>>           $existingByMeal
     * @param array<int, array<int, MealReservation>>     $existingMap
     * @param array<array-key, mixed>                     $rooms
     * @return array{toSave: list<MealReservation>, duplicates: list<array<string, mixed>>, performed: bool, hasNonIntegerMeal: bool}
     * @throws ConflictException 楽観的ロック競合時、画面外の部屋の予約を移す・取り消す必要があるとき（理由付き）
     * @throws UnauthorizedException 職員が直前期間に取消・部屋変更をしようとしたとき
     */
    private function applyIndividualMealChanges(
        array $selectedRoomPerMeal,
        array $existingByMeal,
        array $existingMap,
        string $reservationDate,
        int $userId,
        string $userName,
        array $rooms,
        ChronosDate $today
    ): array {
        $toSave            = [];
        $duplicates        = [];
        $performed         = false;
        $hasNonIntegerMeal = false;
        $outsideRooms      = []; // 画面に出ていないため取り消さなかった予約の部屋

        // 取消（部屋が null）を先に行う。昼食⇔弁当の入れ替えで、取り消す前の相手と「同時に有効」と判定されないようにする
        uasort($selectedRoomPerMeal, static fn($a, $b): int => ($a !== null) <=> ($b !== null));
        // 職員は直前期間に取消・部屋変更ができない（processToggle・change-room と同じ。管理者は例外）
        $self            = $this->users->findRoleInfos([$userId])[$userId] ?? null;
        $isLastMinute    = ReservationDateRule::isLastMinuteWindow(new ChronosDate($reservationDate), $today);
        $staffLastMinute = $isLastMinute
            && UserRole::isStaffLevel($self?->userLevel ?? -1)
            && !UserRole::isAdmin($self?->admin ?? 0);

        foreach ($selectedRoomPerMeal as $mealType => $roomId) {
            if ($roomId === null) {
                foreach ($existingByMeal[(int)$mealType] ?? [] as $row) {
                    if (!$row->isEffective($today)) {
                        continue;
                    }
                    // 取消は、この画面で扱う部屋の予約だけ（画面に出ていない部屋の予約を巻き込まない）
                    if (!array_key_exists($row->roomId, $rooms)) {
                        $outsideRooms[$row->roomId] = true;
                        continue;
                    }
                    if ($staffLastMinute) {
                        throw new UnauthorizedException('職員は直前編集でのキャンセルはできません。');
                    }
                    $this->updateRow($row, false, $isLastMinute, $userName);
                    $performed = true;
                }
                continue;
            }

            foreach ($existingByMeal[(int)$mealType] ?? [] as $row) {
                if ($row->roomId === (int)$roomId || !$row->isEffective($today)) {
                    continue;
                }
                // 画面に出ていない部屋（退出済み・削除済みなど）に残った予約は、取消だけ許す。ここで移すと黙って取り消してしまう
                if (!array_key_exists($row->roomId, $rooms)) {
                    throw new ConflictException($this->roomNamesText([$row->roomId]) . 'に同じ食事の予約があります。この画面では変更できないため、先にその予約を取り消してください。');
                }
                // 別の部屋の予約を取り消してこの部屋へ移すのは部屋変更。職員は直前期間にはできない（change-room と同じ扱い）
                if ($staffLastMinute) {
                    throw new UnauthorizedException('直前期間（14日以内）は職員の部屋変更はできません。');
                }
                $this->updateRow($row, false, $isLastMinute, $userName);
                $performed = true;
            }

            $this->assertCounterpartNotOutside((int)$mealType, (int)$roomId, $existingByMeal, $existingMap, $rooms, $today);

            $existing = $existingMap[(int)$mealType][(int)$roomId] ?? null;

            if ($existing !== null) {
                if ($existing->isEffective($today)) {
                    $duplicates[] = ['reservation_date' => $reservationDate, 'meal_type' => $mealType, 'room_id' => $roomId];
                    continue;
                }
                $this->updateRow($existing, true, $isLastMinute, $userName);
                $performed = true;
                continue;
            }

            $hasNonIntegerMeal = $hasNonIntegerMeal || !self::isIntegerLike($mealType);
            $toSave[] = new MealReservation(
                userId: $userId,
                date: $reservationDate,
                roomId: (int)$roomId,
                mealType: (int)$mealType,
                eatFlag: $isLastMinute ? 0 : 1,
                changeFlag: 1,
            );
        }

        // 画面に出ていない部屋の予約だけを取り消そうとして、何も変えていないなら、システムエラーにせず理由を返す
        if (!$performed && $toSave === [] && $duplicates === [] && $outsideRooms !== []) {
            throw new ConflictException($this->roomNamesText(array_keys($outsideRooms)) . 'の予約は、この画面では取り消せません。その部屋の予約として取り消してください。');
        }

        return ['toSave' => $toSave, 'duplicates' => $duplicates, 'performed' => $performed, 'hasNonIntegerMeal' => $hasNonIntegerMeal];
    }

    /**
     * 昼食⇔弁当: 相手が画面に出ていない部屋で有効なら、入れ替え（相手の取消）はこの画面ではできない。
     * すでにこの部屋で有効なまま（変更なし）の食事は見ない。以前の二重データで、関係のない保存まで止めないため。
     *
     * @param array<int, list<MealReservation>>       $existingByMeal
     * @param array<int, array<int, MealReservation>> $existingMap
     * @param array<array-key, mixed>                 $rooms
     * @throws ConflictException 相手が画面に出ていない部屋で有効なとき
     */
    private function assertCounterpartNotOutside(
        int $mealType,
        int $roomId,
        array $existingByMeal,
        array $existingMap,
        array $rooms,
        ChronosDate $today
    ): void {
        $opponent             = MealType::counterpartValue($mealType);
        $alreadyEffectiveHere = isset($existingMap[$mealType][$roomId]) && $existingMap[$mealType][$roomId]->isEffective($today);
        if ($opponent === null || $alreadyEffectiveHere) {
            return;
        }
        foreach ($existingByMeal[$opponent] ?? [] as $oppRow) {
            if ($oppRow->isEffective($today) && !array_key_exists($oppRow->roomId, $rooms)) {
                throw new ConflictException(sprintf(
                    '%sに%sの予約があるため、%sは予約できません（昼食と弁当は同時に予約できません）。先にその予約を取り消してください。',
                    $this->roomNamesText([$oppRow->roomId]),
                    MealType::labelOf($opponent),
                    MealType::labelOf($mealType)
                ));
            }
        }
    }

    /**
     * 予約行を ON/OFF する。直前期間は i_change_flag だけを変え、当初予定（eat_flag）は残す。
     *
     * @throws ConflictException 楽観的ロック競合・承認済み・別の部屋の予約と重なる場合
     */
    private function updateRow(MealReservation $row, bool $on, bool $isLastMinute, string $userName): void
    {
        $flag = $on ? 1 : 0;
        if (!$this->reservations->updateWithVersion($row, $flag, $isLastMinute ? null : $flag, $userName)) {
            throw new ConflictException(self::CONFLICT_MESSAGE);
        }
    }

    /**
     * 部屋IDの一覧を「部屋名」の並びにする（メッセージ用）。見つからない部屋は ID のまま出す。
     *
     * @param list<int> $roomIds
     */
    private function roomNamesText(array $roomIds): string
    {
        $names = $this->rooms->roomNames($roomIds);

        return '「' . implode('」「', array_map(static fn(int $id): string => (string)($names[$id] ?? $id), $roomIds)) . '」';
    }

    /**
     * 変更後の最終予約状態（朝/昼/夕/弁当）を返す。
     *
     * @return array{breakfast: bool, lunch: bool, dinner: bool, bento: bool}
     */
    private function buildFinalReservationStates(string $date, int $userId, ChronosDate $today): array
    {
        $states   = ['breakfast' => false, 'lunch' => false, 'dinner' => false, 'bento' => false];
        $type2key = [1 => 'breakfast', 2 => 'lunch', 3 => 'dinner', 4 => 'bento'];

        // どの部屋かを問わず、有効な行が1つでもあれば予約あり（直前期間は i_change_flag で判定する）
        foreach ($this->reservations->findByUsersAndDates([$date], [$userId], MealType::values()) as $row) {
            $k = $type2key[$row->mealType] ?? null;
            if ($k !== null && $row->isEffective($today)) {
                $states[$k] = true;
            }
        }

        return $states;
    }

    /** 保存時の検証（integer）を通る値か（整数、または符号つきの数字だけの文字列）。 */
    private static function isIntegerLike(int|string $key): bool
    {
        return is_int($key) || preg_match('/^-?[0-9]+$/', $key) === 1;
    }
}
