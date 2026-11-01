<?php
declare(strict_types=1);

namespace App\Application\UseCase\ToggleMeal;

use App\Application\Port\ReservationCacheInterface;
use App\Domain\Exception\ConflictException;
use App\Domain\Exception\DomainException;
use App\Domain\Exception\InvalidInputException;
use App\Domain\Exception\NotFoundException;
use App\Domain\Exception\UnauthorizedException;
use App\Domain\Repository\ReservationRepositoryInterface;
use App\Domain\Repository\RoomMembershipRepositoryInterface;
use App\Domain\Repository\UserRepositoryInterface;
use App\Domain\Service\ReservationDateRule;
use App\Domain\ValueObject\MealType;
use App\Domain\ValueObject\UserRole;
use Cake\Chronos\ChronosDate;

/**
 * 1件の食事予約を登録・取消する（トグル）。
 *
 * 他人の予約を扱うには、操作者本人も部屋に有効所属していること（管理者も同じ。#821）。
 * adminAnyRoom のとき（エクセル食数予約の一括保存）だけ、管理者・システム管理者は所属外の部屋も操作できる。
 * 対象者の所属・削除済み部屋・過去日・直前・二重予約の判定は変わらない。
 */
final class ToggleMealUseCase
{
    public function __construct(
        private readonly ReservationRepositoryInterface $reservations,
        private readonly RoomMembershipRepositoryInterface $rooms,
        private readonly UserRepositoryInterface $users,
        private readonly ReservationCacheInterface $cache,
    ) {}

    /**
     * @throws DomainException 入力不正・権限不足・所属外・競合・保存失敗など
     */
    public function execute(ToggleMealInput $input): ToggleMealOutput
    {
        $roomId       = $input->roomId;
        $targetUserId = $input->targetUserId;
        $loginUserId  = $input->loginUserId;
        $dateStr      = $input->date;
        $meal         = $input->meal;
        $value        = $input->value;

        // 暦にない日付は、権限・所属の判定より先に入力エラーで返す（理由が分かるように）
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateStr) === 1 && !self::isValidYmd($dateStr)) {
            throw new InvalidInputException('日付が正しくありません。');
        }

        $loginUser     = $this->users->findRoleInfo($loginUserId);
        $isAdmin       = $loginUser?->isAdmin() ?? false;
        $isBlockLeader = $loginUser?->isBlockLeader() ?? false;
        // i_user_level=0,7 は職員レベル（staffId 有無を問わず子供の予約を編集可）
        $isStaffUser   = $loginUser?->isStaff() ?? false;

        $targetUser = $this->users->findRoleInfo($targetUserId);
        if ($targetUser === null) {
            throw new NotFoundException('対象ユーザーが存在しません。');
        }
        $targetUserLevel = (int)$targetUser->userLevel;

        $blockLeaderInRoom = $isBlockLeader && $this->rooms->isActiveMember($loginUserId, $roomId);
        $canEditOther = $isAdmin || (($isStaffUser || $isBlockLeader) && $targetUserLevel === 1) || $blockLeaderInRoom;
        if ($targetUserId !== $loginUserId && !$canEditOther) {
            throw new UnauthorizedException('他ユーザーの予約を更新する権限がありません。');
        }

        // 管理者でも、存在しない・削除済みの部屋には登録できない。削除前に残った予約の取消だけは許す
        // 入力の検証はこの後で行うため、ここでは形式が正しいときだけ行を探す（不正な日付で SQL を投げない）
        $cancelsExisting = $value === 0 && $meal !== null && MealType::tryFrom($meal) !== null && self::isValidYmd($dateStr)
            && $this->reservations->exists($targetUserId, $roomId, $dateStr, $meal);
        if ($isAdmin && !$cancelsExisting && !$this->rooms->isRoomAvailable($roomId)) {
            throw new UnauthorizedException('選択された部屋は利用できません。');
        }

        // 他人の予約を扱うには、操作者本人も部屋に有効所属していること（管理者も同じ。#821）。
        // ただしエクセル食数予約（adminAnyRoom）では、管理者・システム管理者は所属外の部屋も操作できる
        if ($targetUserId !== $loginUserId && !($input->adminAnyRoom && $isAdmin) && !$this->rooms->isActiveMember($loginUserId, $roomId)) {
            throw new UnauthorizedException('この部屋を操作する権限がありません。');
        }

        // 対象ユーザーが指定部屋に有効所属していること（他部屋への不正書き込みを防ぐ）。
        // 取消（value=0）は、退出後に残った予約を消せるよう所属を要求しない。ただし既存の予約がある場合に限る
        // （行が無い取消で、所属外・削除済みの部屋に無効値の行が作られるのを防ぐ）。
        if (!$this->rooms->isActiveMember($targetUserId, $roomId) && !$cancelsExisting) {
            throw new UnauthorizedException('対象ユーザーは指定された部屋に所属していません。');
        }

        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateStr)) {
            throw new InvalidInputException('Invalid date format (Y-m-d).');
        }
        // 2026-02-30 のような暦にない日付は翌月へ繰り越されるため、形式とあわせて実在を確かめる
        if (!self::isValidYmd($dateStr)) {
            throw new InvalidInputException('日付が正しくありません。');
        }

        $today = ChronosDate::today('Asia/Tokyo');
        $date  = new ChronosDate($dateStr);
        if (ReservationDateRule::isPast($date, $today)) {
            throw new InvalidInputException('過去日の予約は変更できません。');
        }

        if ($meal === null || MealType::tryFrom($meal) === null) {
            throw new InvalidInputException('Invalid meal type (1..4).');
        }
        if (!in_array($value, [0, 1], true)) {
            throw new InvalidInputException('Invalid value (0 or 1).');
        }

        $isLastMinute = ReservationDateRule::isLastMinuteWindow($date, $today);
        $exists       = $this->reservations->exists($targetUserId, $roomId, $dateStr, $meal);
        $isStaffTarget = UserRole::isStaffLevel($targetUserLevel);

        if (!$isAdmin && $isLastMinute && $isStaffTarget && $value === 0 && $exists) {
            throw new UnauthorizedException('職員は直前編集でのキャンセルはできません。');
        }
        // 昼食⇔弁当の相手が同じ部屋で有効か（下の2つの判定に要るときだけ問い合わせる）
        $staffLastMinute = !$isAdmin && $isLastMinute && $isStaffTarget;
        $counterpart     = MealType::counterpartValue($meal);
        $counterpartHere = $counterpart !== null && $value === 1 && ($input->noSwap || $staffLastMinute)
            && in_array($roomId, $this->reservations->effectiveRoomIds($targetUserId, $dateStr, $counterpart), true);
        // 昼食⇔弁当を ON にすると、同じ部屋の相手は取り消される（toggleMeal）。
        // 入れ替えない指定（複数日予約のまとめて登録）なら、相手を黙って取り消さずに止める（直接登録ではスキップ扱いになる文言）
        if ($input->noSwap && $counterpartHere) {
            throw new ConflictException($meal === MealType::Lunch->value
                ? 'お弁当を登録済みのため、昼食は登録しませんでした（昼食と弁当は同時に予約できません）。'
                : '昼食を登録済みのため、お弁当は登録しませんでした（昼食と弁当は同時に予約できません）。');
        }
        // 職員の直前取消の禁止を、入れ替えですり抜けさせない
        if ($staffLastMinute && $counterpartHere) {
            throw new UnauthorizedException('職員は直前編集でのキャンセルはできないため、お昼とお弁当を入れ替えられません。');
        }

        $result = $this->reservations->toggle(
            userId: $targetUserId,
            roomId: $roomId,
            date: $dateStr,
            mealType: $meal,
            on: $value === 1,
            actor: $input->loginUserName,
            eatFlag: $this->eatFlag($value, $isLastMinute, $exists, $targetUserId, $roomId, $dateStr, $meal),
            changeFlag: $value,
        );
        // 一括保存では件数分のキャッシュ破棄を避けるため、呼び出し側でまとめて破棄できる
        if ($input->invalidateCache) {
            $this->cache->forgetDate($dateStr, [$targetUserId]);
        }

        return new ToggleMealOutput($result['value'], $result['details'], $dateStr, $roomId, $targetUserId);
    }

    /**
     * 書き込む eat_flag（当初予定）。
     *
     * - 直前編集かつ既存の行あり: いまの値を維持（直前期間は当初予定を変えない）
     * - 直前編集かつ行なし: 0（当初予定には無かった）
     * - 通常: 選んだ値
     */
    private function eatFlag(int $value, bool $isLastMinute, bool $exists, int $userId, int $roomId, string $date, int $meal): int
    {
        if (!$isLastMinute) {
            return $value;
        }
        if (!$exists) {
            return 0;
        }

        return $this->reservations->eatFlag($userId, $roomId, $date, $meal) ?? 0;
    }

    /** YYYY-MM-DD の形式で、暦に実在する日付か */
    private static function isValidYmd(string $date): bool
    {
        $d = \DateTimeImmutable::createFromFormat('!Y-m-d', $date);

        return $d !== false && $d->format('Y-m-d') === $date;
    }
}
