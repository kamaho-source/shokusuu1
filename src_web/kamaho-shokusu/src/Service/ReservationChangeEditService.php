<?php
declare(strict_types=1);

namespace App\Service;

use App\Application\Query\RoomQueryInterface;
use App\Domain\Exception\InvalidInputException;
use App\Domain\Service\ChangeEditPermission;
use App\Domain\ValueObject\MealType;
use App\Domain\ValueObject\UserRole;
use App\Exception\OptimisticLockConflictException;
use App\Infrastructure\Persistence\Table\TIndividualReservationInfoTable;
use App\Infrastructure\Query\CakeRoomQuery;
use Cake\I18n\DateTime;
use Cake\ORM\Table;

class ReservationChangeEditService
{
    private RoomQueryInterface $roomAccessService;

    public function __construct(?RoomQueryInterface $roomAccessService = null)
    {
        $this->roomAccessService = $roomAccessService ?? new CakeRoomQuery();
    }

    /**
     * 直前編集（1部屋 × 1日）を保存する。変更できない利用者・食事は skipped に理由を積んで飛ばす。
     *
     * @return array{updated: list<string>, created: list<string>, skipped: list<string>}
     * @throws \App\Domain\Exception\InvalidInputException 管理者以外が過去日を変更しようとした場合
     * @throws \App\Exception\ApprovedReservationException 承認済みの予約を変更しようとした場合
     * @throws \App\Exception\DuplicateMealReservationException 二重予約・昼食と弁当の同時予約になる場合
     * @throws \App\Exception\OptimisticLockConflictException 他の操作と競合した場合
     */
    public function processUpdate(
        array $usersData,
        array $userIdList,
        string $date,
        int $roomId,
        $loginUser,
        Table $reservationTable,
        Table $userTable,
        bool $isRoomManager = false
    ): array {
        $connection = $reservationTable->getConnection();
        $connection->begin();

        $updated = [];
        $created = [];
        $skipped = [];

        try {
            // ログインユーザーの編集権限を確定する（画面の allowEdit と同じ ChangeEditPermission）
            $loginUid     = $loginUser ? (int)$loginUser->get('i_id_user') : 0;
            $isAdmin      = $loginUser && UserRole::isAdmin((int)($loginUser->get('i_admin') ?? 0));
            $loginLevel   = (int)($loginUser?->get('i_user_level') ?? -1);
            $canEditAll   = ChangeEditPermission::canEditAll((int)($loginUser?->get('i_admin') ?? 0), $isRoomManager, false);

            // 過ぎた日（実績）は、管理者以外は書き換えさせない（数える値が i_change_flag のため、食数・控除表が変わる）
            if (!$isAdmin && (new ReservationDatePolicy())->isPastDate($date)) {
                throw new InvalidInputException('過去日の予約は変更できません。');
            }

            $allowedMap = array_fill_keys(array_map('intval', $userIdList), true);
            $targetUserIds = [];
            foreach ($usersData as $uid => $_) {
                $uid = (int)$uid;
                if (isset($allowedMap[$uid])) {
                    $targetUserIds[$uid] = true;
                }
            }
            $targetUserIds = array_keys($targetUserIds);

            $userLevelMap = [];
            if (!empty($targetUserIds)) {
                $levelRows = $userTable->find()
                    ->select(['i_id_user', 'i_user_level'])
                    ->where(['i_id_user IN' => $targetUserIds])
                    ->all();
                foreach ($levelRows as $row) {
                    $userLevelMap[(int)$row->i_id_user] = (int)$row->i_user_level;
                }
            }

            $isLastMinute = TIndividualReservationInfoTable::isLastMinute($date);
            // 当初予定（eat_flag）を残すのは、i_change_flag で数える日（直前期間と過去日）。15日以降は eat_flag もそろえる
            $keepsOriginalPlan = (new ReservationDatePolicy())->shouldUseChangeFlag(new \Cake\I18n\Date($date));
            $existingByRoom = [];
            $existingAny = [];
            if (!empty($targetUserIds)) {
                $existingRows = $reservationTable->find()
                    ->enableAutoFields(false)
                    ->select([
                        'i_id_user',
                        'd_reservation_date',
                        'i_reservation_type',
                        'i_id_room',
                        'eat_flag',
                        'i_change_flag',
                        'i_version',
                    ])
                    ->where([
                        'i_id_user IN' => $targetUserIds,
                        'd_reservation_date' => $date,
                        'i_reservation_type IN' => MealType::values(),
                    ])
                    ->all();
                foreach ($existingRows as $row) {
                    $uid = (int)$row->i_id_user;
                    $mealType = (int)$row->i_reservation_type;
                    $rid = (int)$row->i_id_room;
                    $existingByRoom[$uid][$mealType][$rid] = $row;
                    // 部屋を書き換えて移すときは、有効な行を優先して選ぶ（取消済みの行を移すと二重予約になる）
                    if (!isset($existingAny[$uid][$mealType]) || TIndividualReservationInfoTable::isEffectiveRow($row)) {
                        $existingAny[$uid][$mealType] = $row;
                    }
                }
            }

            $rowsToUpdate = [];
            $newRows = [];

            foreach ($usersData as $userIdRaw => $meals) {
                $userId = (int)$userIdRaw;
                if (!isset($allowedMap[$userId])) {
                    $skipped[] = "利用者ID {$userId} はこの部屋の所属ではないためスキップされました。";
                    continue;
                }

                // 画面（allowEdit）と同じ決まり。レベル不明（対象ユーザーが存在しない等）は子どもとみなさない（fail-closed）
                if (!ChangeEditPermission::canEdit($canEditAll, $loginUid, $loginLevel, $userId, $userLevelMap[$userId] ?? null)) {
                    $skipped[] = "利用者ID {$userId} の予約を変更する権限がありません。";
                    continue;
                }

                $targetUserLevel = $userLevelMap[$userId] ?? null;

                foreach ($meals as $mealTypeRaw => $flags) {
                    $mealType = (int)$mealTypeRaw;
                    if (MealType::tryFrom($mealType) === null) {
                        continue;
                    }

                    $changeFlagRaw = isset($flags['i_change_flag']) ? (int)$flags['i_change_flag'] : null;
                    if ($changeFlagRaw === null) {
                        continue;
                    }
                    $changeFlag = ($changeFlagRaw === 2) ? 0 : $changeFlagRaw;
                    if (!in_array($changeFlag, [0, 1], true)) {
                        $skipped[] = "利用者ID {$userId} の変更フラグが不正なためスキップされました。";
                        continue;
                    }

                    $existingInRoom = $existingByRoom[$userId][$mealType][$roomId] ?? null;
                    $existingForMeal = $existingAny[$userId][$mealType] ?? null;

                    if ($existingInRoom !== null || $existingForMeal !== null) {
                        // 職員の直前取消は認めない（toggle・change-room と同じく、管理者は例外）
                        if (!$isAdmin && $isLastMinute && $targetUserLevel !== null && UserRole::isStaffLevel($targetUserLevel) && $changeFlag === 0) {
                            $skipped[] = "利用者ID {$userId} は職員のため、直前編集でのキャンセルはできません。";
                            continue;
                        }

                        // 職員の部屋変更は、直前期間は食数が部屋ごとに変わるため取消と同じく認めない（change-room API と同じく管理者は例外）
                        if (!$isAdmin && $isLastMinute && $changeFlag === 1 && $targetUserLevel !== null && UserRole::isStaffLevel($targetUserLevel)
                            && $this->hasEffectiveRowInOtherRoom($existingByRoom[$userId][$mealType] ?? [], $roomId)
                        ) {
                            $skipped[] = "利用者ID {$userId} は職員のため、直前期間（14日以内）は部屋の変更はできません。";
                            continue;
                        }

                        // 退出済み・削除済みの部屋に残った予約は、取消だけ許す（ここで移すと、黙って部屋が変わる）
                        // この部屋ですでに有効な食事（変更なし）は見ない。以前の二重データで、関係のない保存のたびに警告を出さないため
                        if ($changeFlag === 1 && !($existingInRoom !== null && TIndividualReservationInfoTable::isEffectiveRow($existingInRoom))) {
                            $outside = $this->outsideRoomIdsWithEffectiveRow($existingByRoom[$userId][$mealType] ?? [], $roomId, $userId);
                            if ($outside !== []) {
                                $skipped[] = "利用者ID {$userId} は、所属外の部屋（ID " . implode('・', $outside) . "）に同じ食事の予約があるため、この部屋では登録できません。先にその予約を取り消してください。";
                                continue;
                            }
                        }

                        $targetRow = $existingInRoom ?? $existingForMeal;
                        // この部屋で食べるなら、他の部屋で有効な同じ食事は取り消す（部屋の移動として扱う）
                        if ($changeFlag === 1) {
                            foreach ($existingByRoom[$userId][$mealType] ?? [] as $otherRoomId => $otherRow) {
                                if ($otherRow === $targetRow || !TIndividualReservationInfoTable::isEffectiveRow($otherRow)) {
                                    continue;
                                }
                                $rowsToUpdate[implode(':', [$userId, $mealType, (int)$otherRoomId])] = [
                                    'row' => $otherRow,
                                    // 直前期間の当初予定（eat_flag）は残す。直前期間外は eat_flag が有効値なので 0 にする
                                    'set' => [
                                        'i_change_flag' => 0,
                                        'c_update_user' => $loginUser?->get('c_user_name') ?? 'system',
                                        'dt_update' => DateTime::now('Asia/Tokyo'),
                                    ] + ($keepsOriginalPlan ? [] : ['eat_flag' => 0]),
                                ];
                            }
                        }
                        if ((int)$targetRow->i_change_flag !== $changeFlag
                            || (int)$targetRow->i_id_room !== $roomId
                            || (!$keepsOriginalPlan && (int)$targetRow->eat_flag !== $changeFlag)
                        ) {
                            $updateKey = implode(':', [$userId, $mealType, (int)$targetRow->i_id_room]);
                            $rowsToUpdate[$updateKey] = [
                                'row' => $targetRow,
                                'set' => [
                                    'i_change_flag' => $changeFlag,
                                    'i_id_room' => $roomId,
                                    'c_update_user' => $loginUser?->get('c_user_name') ?? 'system',
                                    'dt_update' => DateTime::now('Asia/Tokyo'),
                                // 直前期間外は eat_flag が有効値なので、そろえないと「どの部屋でも食べない」状態になる
                                ] + ($keepsOriginalPlan ? [] : ['eat_flag' => $changeFlag]),
                            ];
                            $updated[] = "{$userId}:{$mealType}";
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
                            'eat_flag'           => $keepsOriginalPlan ? 0 : $changeFlag,
                            'i_change_flag'      => $changeFlag,
                            'i_version'          => 1,
                            'c_create_user'      => $loginUser?->get('c_user_name') ?? 'system',
                            'dt_create'          => DateTime::now('Asia/Tokyo'),
                        ];
                        $created[] = "{$userId}:{$mealType}";
                    }
                }
            }

            // 取消を先に行う（昼食⇔弁当の入れ替えで、取り消す前の相手と「同時に有効」と判定されないようにする）
            uasort($rowsToUpdate, static fn(array $a, array $b): int
                => (($a['set']['i_change_flag'] ?? 1) !== 0) <=> (($b['set']['i_change_flag'] ?? 1) !== 0));
            foreach ($rowsToUpdate as $item) {
                $ok = $this->updateReservationRowWithVersion($reservationTable, $item['row'], $item['set']);
                if (!$ok) {
                    throw new OptimisticLockConflictException();
                }
            }

            if (!empty($newRows)) {
                foreach (array_chunk($newRows, 500) as $chunk) {
                    $entities = $reservationTable->newEntities($chunk);
                    $reservationTable->saveManyOrFail($entities);
                }
            }

            $connection->commit();
        } catch (\Throwable $e) {
            $connection->rollback();
            throw $e;
        }

        return [
            'updated' => $updated,
            'created' => $created,
            'skipped' => $skipped,
        ];
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

    /**
     * 指定の部屋以外で有効な予約がある部屋のうち、利用者が有効に所属していない（退出済み）か削除済みの部屋の ID。
     *
     * @param array<int, object> $rowsByRoom 部屋ID => 予約行
     * @return list<int>
     */
    private function outsideRoomIdsWithEffectiveRow(array $rowsByRoom, int $roomId, int $userId): array
    {
        $outside = [];
        foreach ($rowsByRoom as $rid => $row) {
            $rid = (int)$rid;
            if ($rid === $roomId || !TIndividualReservationInfoTable::isEffectiveRow($row)) {
                continue;
            }
            if (!$this->roomAccessService->isRoomAvailable($rid) || !$this->roomAccessService->isActiveMember($userId, $rid)) {
                $outside[] = $rid;
            }
        }

        return $outside;
    }

    /**
     * 同じ利用者・日付・食事の行のうち、指定の部屋以外に有効な予約があるか。
     *
     * @param array<int, object> $rowsByRoom 部屋ID => 予約行
     */
    private function hasEffectiveRowInOtherRoom(array $rowsByRoom, int $roomId): bool
    {
        foreach ($rowsByRoom as $rid => $row) {
            if ((int)$rid !== $roomId && TIndividualReservationInfoTable::isEffectiveRow($row)) {
                return true;
            }
        }

        return false;
    }
}