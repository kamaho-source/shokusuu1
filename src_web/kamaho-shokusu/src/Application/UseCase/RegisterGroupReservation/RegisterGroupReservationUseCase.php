<?php
declare(strict_types=1);

namespace App\Application\UseCase\RegisterGroupReservation;

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
use App\Domain\ValueObject\UserRoleInfo;
use Cake\Chronos\ChronosDate;
use Psr\Log\LoggerInterface;

/**
 * 部屋単位の予約を保存する（1部屋 × 1日 × 複数利用者）。
 *
 * 他ユーザーの予約は、管理者・職員（対象が児童）・部屋のブロック長だけが扱える。
 * 他人の予約を扱うには、操作者本人も部屋に有効所属していること（管理者も同じ。#821）。
 * 登録は対象も部屋に有効所属していること。取消だけなら所属は要求しない。
 */
final class RegisterGroupReservationUseCase
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
     * @throws UnauthorizedException 権限のない部屋・利用者を指定した場合
     * @throws ConflictException 競合・昼食と弁当の同時予約になる場合
     * @throws PersistenceException 保存に失敗した場合
     */
    public function execute(RegisterGroupReservationInput $input): RegisterGroupReservationOutput
    {
        $reservationDate = $input->reservationDate;
        $loginUserId     = $input->loginUserId;

        try {
            $data = self::decodeJsonInput($input->data);
        } catch (\InvalidArgumentException $e) {
            $this->logger->error($e->getMessage());
            throw new InvalidInputException($e->getMessage(), 400);
        } catch (\JsonException $e) {
            $this->logger->error('JSON デコードエラー: ' . $e->getMessage());
            throw new InvalidInputException('データの形式が不正です。', 400);
        }

        if (!isset($data['users']) || !is_array($data['users'])) {
            $this->logger->error('データ構造が不正: "users" キーが存在しない、または配列ではありません。データ: ' . json_encode($data));
            throw new InvalidInputException('データ構造が不正です。');
        }

        if ($input->dateError !== null) {
            $this->logger->error('日付検証エラー: ' . $input->dateError);
            throw new InvalidInputException($input->dateError);
        }

        $roomId  = isset($data['i_id_room']) ? (int)$data['i_id_room'] : null;
        $userIds = array_map('intval', array_keys($data['users']));

        // ログインユーザーを特定できない場合は権限判定できないため拒否する（fail-closed）
        if ($loginUserId <= 0) {
            throw new UnauthorizedException('ログインユーザーを特定できないため予約を更新できません。');
        }

        $this->assertCanEditUsers($data['users'], $userIds, $roomId, $input);

        $userNameMap = $this->users->findNames($userIds);
        $today       = ChronosDate::today('Asia/Tokyo');

        $duplicates = [];
        $this->transaction->begin();
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
            $roomNameMap = $this->rooms->roomNames(array_keys($roomIdsForName));

            $changes    = $this->applyGroupMealChanges($data['users'], $input->rooms, $roomId, $existingMap, $userNameMap, $roomNameMap, $reservationDate, $input->creatorName, $today);
            $duplicates = $changes['duplicates'];

            if ($changes['toSave'] !== []) {
                $this->saveNewRows($changes['toSave'], $changes['hasNonIntegerMeal'], $input->creatorName);
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
            $this->logger->error('一括予約処理で予期しない例外: ' . $e->getMessage());
            throw new PersistenceException('予約処理中に内部エラーが発生しました。');
        }

        $this->cache->forgetDate($reservationDate, $userIds);

        if ($duplicates !== []) {
            return new RegisterGroupReservationOutput('一部の予約はすでに存在していたためスキップされました。', [
                'skipped' => $duplicates,
                'date'    => $reservationDate,
            ]);
        }

        return new RegisterGroupReservationOutput('予約が正常に登録されました。', ['date' => $reservationDate]);
    }

    /**
     * 操作者が、送られた利用者の予約を扱えるかを確かめる。
     *
     * @param array<array-key, mixed> $users   利用者ID => [食事区分 => 0/1]
     * @param list<int>               $userIds
     * @throws UnauthorizedException 扱えない利用者・部屋があるとき
     */
    private function assertCanEditUsers(array $users, array $userIds, ?int $roomId, RegisterGroupReservationInput $input): void
    {
        $loginUserId       = $input->loginUserId;
        $isLoginStaff      = UserRole::isStaffLevel($input->loginUserLevel);
        $targetUserLevels  = array_map(static fn(UserRoleInfo $info): int => (int)$info->userLevel, $this->users->findRoleInfos($userIds));
        $blockLeaderInRoom = false;
        if ($input->isBlockLeader && $roomId !== null) {
            $blockLeaderInRoom = $this->rooms->isActiveMember($loginUserId, $roomId);
        }
        foreach ($userIds as $targetUserId) {
            $targetUserLevel = $targetUserLevels[$targetUserId] ?? 0;
            $canEditOther    = $input->isAdmin || ($isLoginStaff && $targetUserLevel === 1) || $blockLeaderInRoom;
            if ($targetUserId !== $loginUserId && !$canEditOther) {
                throw new UnauthorizedException('他ユーザーの予約を更新する権限がありません。');
            }
            if ($roomId === null) {
                continue;
            }
            // 他人の予約を扱うには、操作者本人も部屋に有効所属していること（管理者も同じ。#821）
            if ($targetUserId !== $loginUserId && !$this->rooms->isActiveMember($loginUserId, $roomId)) {
                throw new UnauthorizedException('この部屋を操作する権限がありません。');
            }
            // 登録（1を含む）は対象（本人を含む）が有効所属していること。取消のみなら所属は要求しない。
            if (self::wantsRegister((array)($users[$targetUserId] ?? [])) && !$this->rooms->isActiveMember($targetUserId, $roomId)) {
                throw new UnauthorizedException('対象ユーザーは指定された部屋に所属していません。');
            }
        }
    }

    /**
     * 送られた食事に登録（1）が含まれるか。
     *
     * @param array<array-key, mixed> $meals 食事区分 => 0/1
     */
    private static function wantsRegister(array $meals): bool
    {
        foreach ($meals as $selected) {
            if ((is_bool($selected) ? ($selected ? 1 : 0) : (int)$selected) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * 新しい予約行を保存する。検証ルールに当たったら、取り消して理由を返す。
     *
     * @param list<MealReservation> $toSave
     * @param bool $hasNonIntegerMeal 食事区分に整数でない値があったか（従来は保存時の検証で失敗していたため、同じく保存の失敗にする）
     * @throws ConflictException 昼食と弁当の同時予約・別の部屋の予約と重なる場合
     * @throws PersistenceException それ以外の検証エラー
     */
    private function saveNewRows(array $toSave, bool $hasNonIntegerMeal, string $creatorName): void
    {
        try {
            if ($hasNonIntegerMeal) {
                throw new ReservationValidationException(null, ['食事区分が正しくありません。'], '{"i_reservation_type":{"integer":"食事区分が正しくありません。"}}');
            }
            $this->reservations->addMany($toSave, $creatorName);
        } catch (ReservationValidationException $e) {
            $this->transaction->rollback();
            $this->logger->error('グループ予約 saveManyOrFail エラー: ' . $e->getMessage());
            // 昼食と弁当の同時予約は、理由のない 500 にせず 409 で理由を返す（個人予約と同じ）
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
     * @throws \InvalidArgumentException 入力が空の場合
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
     * 複数ユーザー・日付の既存予約を [userId][mealType][roomId] マップで返す。
     *
     * @param list<int> $userIds
     * @return array<int, array<int, array<int, MealReservation>>>
     */
    private function buildGroupExistingMap(string $date, array $userIds): array
    {
        $map = [];
        foreach ($this->reservations->findByUsersAndDates([$date], $userIds, MealType::values()) as $row) {
            $map[$row->userId][$row->mealType][$row->roomId] = $row;
        }

        return $map;
    }

    /**
     * グループ予約の取消・有効化を行い、新しく保存する行を返す。
     *
     * @param array<array-key, mixed>                                $users
     * @param array<array-key, mixed>                                $rooms
     * @param array<int, array<int, array<int, MealReservation>>>    $existingMap
     * @param array<int, string>                                     $userNameMap
     * @param array<int, string>                                     $roomNameMap
     * @return array{toSave: list<MealReservation>, duplicates: list<array<string, mixed>>, hasNonIntegerMeal: bool}
     * @throws UnauthorizedException 権限のない部屋が指定された場合
     * @throws ConflictException 楽観的ロック競合・承認済み・別の部屋の予約と重なる場合
     */
    private function applyGroupMealChanges(
        array $users,
        array $rooms,
        ?int $roomId,
        array $existingMap,
        array $userNameMap,
        array $roomNameMap,
        string $reservationDate,
        string $creatorName,
        ChronosDate $today
    ): array {
        $toSave            = [];
        $duplicates        = [];
        $hasNonIntegerMeal = false;
        $isLastMinute      = ReservationDateRule::isLastMinuteWindow(new ChronosDate($reservationDate), $today);

        foreach ($users as $targetUserId => $meals) {
            if (!is_array($meals)) {
                continue;
            }
            // 取消を先に行う（昼食⇔弁当の入れ替えで、取り消す前の相手と「同時に有効」と判定されないようにする）
            uasort($meals, static fn($a, $b): int => ((int)$a === 1) <=> ((int)$b === 1));
            foreach ($meals as $mealType => $selected) {
                $valueInt = is_bool($selected) ? ($selected ? 1 : 0) : (int)$selected;

                if (!isset($rooms[$roomId])) {
                    throw new UnauthorizedException('選択された部屋は権限がありません。');
                }

                if ($valueInt !== 1) {
                    $existing = $existingMap[(int)$targetUserId][(int)$mealType][(int)$roomId] ?? null;
                    if ($existing !== null && $existing->isEffective($today)) {
                        $this->updateRow($existing, false, $isLastMinute, $creatorName);
                    }
                    continue;
                }

                $userMealRows = $existingMap[(int)$targetUserId][(int)$mealType] ?? [];
                $existing     = $userMealRows[(int)$roomId] ?? null;

                // 退出済み・削除済みの部屋に残った予約は、取消だけ許す。ここで移すと黙って部屋が変わるので、スキップして伝える
                $leftRoomId = $this->leftRoomWithEffectiveRow((int)$targetUserId, (int)$roomId, $userMealRows, $today);
                if ($leftRoomId !== null) {
                    $duplicates[] = [
                        'user_name' => $userNameMap[(int)$targetUserId] ?? '不明なユーザー名',
                        'meal_type' => MealType::labels()[(int)$mealType] ?? $mealType,
                        'room_name' => $roomNameMap[$leftRoomId] ?? ('部屋' . $leftRoomId),
                    ];
                    continue;
                }
                foreach ($userMealRows as $existingRoomId => $row) {
                    if ((int)$existingRoomId === (int)$roomId || !$row->isEffective($today)) {
                        continue;
                    }
                    $this->updateRow($row, false, $isLastMinute, $creatorName);
                }

                if ($existing !== null) {
                    if (!$existing->isEffective($today)) {
                        $this->updateRow($existing, true, $isLastMinute, $creatorName);
                        continue;
                    }
                    $duplicates[] = [
                        'user_name' => $userNameMap[(int)$targetUserId] ?? '不明なユーザー名',
                        'meal_type' => MealType::labels()[(int)$mealType] ?? $mealType,
                        'room_name' => $roomNameMap[$existing->roomId] ?? '不明な部屋名',
                    ];
                    continue;
                }

                $hasNonIntegerMeal = $hasNonIntegerMeal || !self::isIntegerLike($mealType);
                $toSave[] = new MealReservation(
                    userId: (int)$targetUserId,
                    date: $reservationDate,
                    roomId: (int)$roomId,
                    mealType: (int)$mealType,
                    eatFlag: $isLastMinute ? 0 : 1,
                    changeFlag: 1,
                );
            }
        }

        return ['toSave' => $toSave, 'duplicates' => $duplicates, 'hasNonIntegerMeal' => $hasNonIntegerMeal];
    }

    /**
     * この部屋でまだ有効でない食事が、退出済み・削除済みの部屋で有効なら、その部屋 ID。なければ null。
     * （この部屋ですでに有効な食事は変更なしなので見ない）
     *
     * @param array<int, MealReservation> $userMealRows 部屋ID => 予約行
     */
    private function leftRoomWithEffectiveRow(int $targetUserId, int $roomId, array $userMealRows, ChronosDate $today): ?int
    {
        if (isset($userMealRows[$roomId]) && $userMealRows[$roomId]->isEffective($today)) {
            return null;
        }
        foreach ($userMealRows as $existingRoomId => $row) {
            if ((int)$existingRoomId !== $roomId && $row->isEffective($today)
                && (!$this->rooms->isRoomAvailable((int)$existingRoomId) || !$this->rooms->isActiveMember($targetUserId, (int)$existingRoomId))
            ) {
                return (int)$existingRoomId;
            }
        }

        return null;
    }

    /**
     * 予約行を ON/OFF する。直前期間は i_change_flag だけを変え、当初予定（eat_flag）は残す。
     *
     * @throws ConflictException 楽観的ロック競合・承認済み・別の部屋の予約と重なる場合
     */
    private function updateRow(MealReservation $row, bool $on, bool $isLastMinute, string $creatorName): void
    {
        $flag = $on ? 1 : 0;
        if (!$this->reservations->updateWithVersion($row, $flag, $isLastMinute ? null : $flag, $creatorName)) {
            throw new ConflictException(self::CONFLICT_MESSAGE);
        }
    }

    /** 保存時の検証（integer）を通る値か（整数、または符号つきの数字だけの文字列）。 */
    private static function isIntegerLike(int|string $key): bool
    {
        return is_int($key) || preg_match('/^-?[0-9]+$/', $key) === 1;
    }
}
