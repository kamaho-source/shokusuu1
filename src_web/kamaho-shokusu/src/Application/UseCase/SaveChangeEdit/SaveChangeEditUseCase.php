<?php
declare(strict_types=1);

namespace App\Application\UseCase\SaveChangeEdit;

use App\Domain\Entity\MealReservation;
use App\Domain\Exception\ApprovedReservationChangeException;
use App\Domain\Exception\ConflictException;
use App\Domain\Exception\InvalidInputException;
use App\Domain\Exception\PersistenceException;
use App\Domain\Exception\ReservationValidationException;
use App\Domain\Repository\ReservationRepositoryInterface;
use App\Domain\Repository\RoomMembershipRepositoryInterface;
use App\Domain\Repository\UserRepositoryInterface;
use App\Domain\Service\ChangeEditPermission;
use App\Domain\Service\ReservationDateRule;
use App\Domain\ValueObject\MealType;
use App\Domain\ValueObject\UserRole;
use Cake\Chronos\ChronosDate;
use Psr\Clock\ClockInterface;

/**
 * 直前編集（1部屋 × 1日）を保存する。変えられない利用者・食事は skipped に理由を積んで飛ばす。
 *
 * - 過ぎた日（実績）は、管理者以外は書き換えない（数える値が i_change_flag のため、食数・控除表が変わる）
 * - 職員の直前の取消・部屋の変更は、管理者以外は認めない（予約カレンダー・部屋の変更と同じ）
 * - 所属外・削除済みの部屋に残った予約は、取消だけ許す（黙って部屋を移さない）
 * - この部屋で食べるなら、他の部屋で有効な同じ食事は取り消す（部屋の移動として扱う）
 * - 当初予定（eat_flag）を残すのは i_change_flag で数える日（直前期間と過去日）。それより先は eat_flag もそろえる
 * - 承認済みの行は変えない。すべて1つのトランザクションで、途中で失敗したら取り消す
 */
final class SaveChangeEditUseCase
{
    private const CONFLICT_MESSAGE = '他のユーザーが先に保存しました。ページを再読込して再試行してください。';

    public function __construct(
        private readonly ReservationRepositoryInterface $reservations,
        private readonly RoomMembershipRepositoryInterface $rooms,
        private readonly UserRepositoryInterface $users,
        private readonly ClockInterface $clock,
    ) {}

    /**
     * @throws InvalidInputException 管理者以外が過去日を変えようとした
     * @throws ApprovedReservationChangeException 承認済みの予約を変えようとした
     * @throws ConflictException 他の操作と競合した・二重予約・昼食と弁当の同時予約になる
     * @throws ReservationValidationException 新しい行が検証ルールに当たった（lunchBentoMessage があれば昼食と弁当）
     * @throws PersistenceException DB の失敗
     */
    public function execute(SaveChangeEditInput $input): SaveChangeEditOutput
    {
        $today   = new ChronosDate($this->clock->now());
        $target  = new ChronosDate($input->date);
        $isAdmin = UserRole::isAdmin($input->loginAdmin);
        if (!$isAdmin && ReservationDateRule::isPast($target, $today)) {
            throw new InvalidInputException('過去日の予約は変更できません。');
        }

        return $this->reservations->transactional(fn(): array => [$this->save($input, $today, $target, $isAdmin)])[0];
    }

    private function save(SaveChangeEditInput $input, ChronosDate $today, ChronosDate $target, bool $isAdmin): SaveChangeEditOutput
    {
        $roomId     = $input->roomId;
        // その部屋に所属するブロック長は、部屋の全員の直前の予約を変えられる
        $isBlockLeaderInRoom = UserRole::isBlockLeader($input->loginAdmin) && $this->rooms->isActiveMember($input->loginUserId, $roomId);
        $canEditAll = ChangeEditPermission::canEditAll($input->loginAdmin, $isBlockLeaderInRoom);
        $members    = array_fill_keys(array_map('intval', $input->memberIds), true);
        $targetIds  = array_values(array_unique(array_filter(
            array_map('intval', array_keys($input->usersData)),
            static fn(int $id): bool => isset($members[$id])
        )));

        // 区分が NULL の人は 0（職員）として扱う（以前のまま）。見つからない人は null
        $levels = [];
        foreach ($this->users->findRoleInfos($targetIds) as $id => $info) {
            $levels[$id] = $info->userLevel ?? 0;
        }

        $isLastMinute      = ReservationDateRule::isLastMinuteWindow($target, $today);
        $keepsOriginalPlan = ReservationDateRule::usesChangeFlag($target, $today);
        $eats = static fn(MealReservation $r): bool => $r->isEffective($today);

        $byRoom = [];  // [利用者][食事][部屋] = 行
        $any    = [];  // [利用者][食事] = 部屋を移すときに使う行（有効な行を優先。取消済みの行を移すと二重予約になる）
        foreach ($this->reservations->findByUsersAndDates([$input->date], $targetIds, MealType::values()) as $row) {
            $byRoom[$row->userId][$row->mealType][$row->roomId] = $row;
            if (!isset($any[$row->userId][$row->mealType]) || $eats($row)) {
                $any[$row->userId][$row->mealType] = $row;
            }
        }

        $updates = [];  // "利用者:食事:部屋" => [行, i_change_flag, eat_flag|null, 部屋|null]
        $newRows = [];
        $updated = $created = $skipped = [];
        foreach ($input->usersData as $userIdRaw => $meals) {
            $userId = (int)$userIdRaw;
            if (!isset($members[$userId])) {
                $skipped[] = "利用者ID {$userId} はこの部屋の所属ではないためスキップされました。";
                continue;
            }
            $level = $levels[$userId] ?? null;
            if (!ChangeEditPermission::canEdit($canEditAll, $input->loginUserId, $input->loginLevel, $userId, $level)) {
                $skipped[] = "利用者ID {$userId} の予約を変更する権限がありません。";
                continue;
            }
            $isStaffLocked = ChangeEditPermission::isStaffLockedInLastMinute($isAdmin, $isLastMinute, $level);

            foreach ((array)$meals as $mealTypeRaw => $flags) {
                $mealType = (int)$mealTypeRaw;
                if (MealType::tryFrom($mealType) === null || !isset($flags['i_change_flag'])) {
                    continue;
                }
                $raw = (int)$flags['i_change_flag'];
                $changeFlag = $raw === 2 ? 0 : $raw;
                if (!in_array($changeFlag, [0, 1], true)) {
                    $skipped[] = "利用者ID {$userId} の変更フラグが不正なためスキップされました。";
                    continue;
                }

                $rowsByRoom = $byRoom[$userId][$mealType] ?? [];
                $inRoom     = $rowsByRoom[$roomId] ?? null;
                $forMeal    = $any[$userId][$mealType] ?? null;
                if ($inRoom === null && $forMeal === null) {
                    if ($changeFlag === 1) {
                        $newRows[] = new MealReservation($userId, $input->date, $roomId, $mealType, $keepsOriginalPlan ? 0 : 1, 1);
                        $created[] = "{$userId}:{$mealType}";
                    }
                    continue;
                }

                $eatsElsewhere = array_filter($rowsByRoom, static fn(MealReservation $r, int $rid): bool => $rid !== $roomId && $eats($r), ARRAY_FILTER_USE_BOTH);
                if ($isStaffLocked && $changeFlag === 0) {
                    $skipped[] = "利用者ID {$userId} は職員のため、直前編集でのキャンセルはできません。";
                    continue;
                }
                if ($isStaffLocked && $changeFlag === 1 && $eatsElsewhere !== []) {
                    $skipped[] = "利用者ID {$userId} は職員のため、直前期間（14日以内）は部屋の変更はできません。";
                    continue;
                }
                // この部屋ですでに有効な食事（変更なし）は見ない。以前の二重データで、関係のない保存のたびに警告を出さないため
                if ($changeFlag === 1 && !($inRoom !== null && $eats($inRoom))) {
                    $outside = [];
                    foreach (array_keys($eatsElsewhere) as $rid) {
                        if (!$this->rooms->isRoomAvailable($rid) || !$this->rooms->isActiveMember($userId, $rid)) {
                            $outside[] = $rid;
                        }
                    }
                    if ($outside !== []) {
                        $skipped[] = "利用者ID {$userId} は、所属外の部屋（ID " . implode('・', $outside) . '）に同じ食事の予約があるため、この部屋では登録できません。先にその予約を取り消してください。';
                        continue;
                    }
                }

                $targetRow = $inRoom ?? $forMeal;
                if ($changeFlag === 1) {
                    foreach ($eatsElsewhere as $rid => $other) {
                        if ($other !== $targetRow) {
                            // 直前期間の当初予定（eat_flag）は残す。直前期間外は eat_flag が有効値なので 0 にする
                            $updates["{$userId}:{$mealType}:{$rid}"] = [$other, 0, $keepsOriginalPlan ? null : 0, null];
                        }
                    }
                }
                if ((int)$targetRow->changeFlag !== $changeFlag
                    || $targetRow->roomId !== $roomId
                    || (!$keepsOriginalPlan && (int)$targetRow->eatFlag !== $changeFlag)
                ) {
                    // 直前期間外は eat_flag が有効値なので、そろえないと「どの部屋でも食べない」状態になる
                    $updates["{$userId}:{$mealType}:{$targetRow->roomId}"] = [$targetRow, $changeFlag, $keepsOriginalPlan ? null : $changeFlag, $roomId];
                    $updated[] = "{$userId}:{$mealType}";
                }
            }
        }

        // 取消を先に行う（昼食⇔弁当の入れ替えで、取り消す前の相手と「同時に有効」と判定されないようにする）
        uasort($updates, static fn(array $a, array $b): int => ($a[1] !== 0) <=> ($b[1] !== 0));
        foreach ($updates as [$row, $changeFlag, $eatFlag, $toRoom]) {
            if ($row->isApproved()) {
                throw new ApprovedReservationChangeException();
            }
            if (!$this->reservations->updateWithVersion($row, $changeFlag, $eatFlag, $input->loginUserName, $toRoom)) {
                throw new ConflictException(self::CONFLICT_MESSAGE);
            }
        }
        if ($newRows !== []) {
            $this->reservations->addMany($newRows, $input->loginUserName);
        }

        return new SaveChangeEditOutput($updated, $created, $skipped);
    }
}
