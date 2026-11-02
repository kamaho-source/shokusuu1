<?php
declare(strict_types=1);

namespace App\Application\UseCase\CopyReservations;

use App\Domain\Entity\MealReservation;
use App\Domain\Exception\UnauthorizedException;
use App\Domain\Repository\ReservationRepositoryInterface;
use App\Domain\Repository\RoomMembershipRepositoryInterface;
use App\Domain\Repository\UserAccountRepositoryInterface;
use App\Domain\ValueObject\MealType;
use Cake\Chronos\ChronosDate;
use Psr\Clock\ClockInterface;

/**
 * コピーとプレビューで共通の判定（どの行を・どの日へ・写すか）。両方が同じ判定を使うことで件数がずれないようにする。
 *
 * - 週コピーは同じ曜日へ、月コピーは同じ「日」へ写す（コピー先の月に無い日は無効な日付）。
 * - 今日より前の日へは写さない（無効な日付）。
 * - コピー元で食べない行（取消済み）、コピー先で二重予約・昼食と弁当の同時予約になる行、すでに食べる行・承認済みの行がある行はスキップする。
 */
final class ReservationCopyPlanner
{
    public function __construct(
        private readonly ReservationRepositoryInterface $reservations,
        private readonly RoomMembershipRepositoryInterface $rooms,
        private readonly UserAccountRepositoryInterface $users,
        private readonly ClockInterface $clock,
    ) {}

    /**
     * コピー元の行（対象の部屋の、コピー元の期間の行。児童だけに絞ったのに児童が居なければ空）。
     *
     * @return list<MealReservation>
     * @throws UnauthorizedException 所属していない部屋の指定・所属部屋なし（403）
     */
    public function sourceRows(CopyReservationsInput $input): array
    {
        $roomIds = $this->targetRoomIds($input);
        $childIds = $input->onlyChildren ? $this->users->childUserIds() : null;
        if ($childIds === []) {
            return [];
        }
        $to = $input->isWeek() ? $input->src->addDays(6) : $input->src->endOfMonth();

        return $this->reservations->findInDateRange($input->src->format('Y-m-d'), $to->format('Y-m-d'), $roomIds, $childIds);
    }

    /**
     * コピー先の日（週は同じ曜日、月は同じ「日」。その月に無い日は null）。
     */
    public function targetDate(CopyReservationsInput $input, MealReservation $source): ?ChronosDate
    {
        $date = new ChronosDate($source->date);
        if ($input->isWeek()) {
            return $date->addDays(self::dayOffset($input->src, $input->dst));
        }
        $day = (int)$date->format('d');

        return $day > (int)$input->dst->endOfMonth()->format('d') ? null : $input->dst->addDays($day - 1);
    }

    /**
     * その行をどうするか。
     */
    public function verdict(MealReservation $source, ?ChronosDate $target, ChronosDate $today): CopyVerdict
    {
        if ($target === null || $target->lessThan($today)) {
            return CopyVerdict::InvalidDate;
        }

        return $this->shouldSkip($source, $target->format('Y-m-d'), $today) ? CopyVerdict::Skip : CopyVerdict::Copy;
    }

    public function today(): ChronosDate
    {
        return new ChronosDate($this->clock->now());
    }

    /**
     * コピーできる部屋（ログインしている人が所属している、削除されていない部屋。管理者も同じ。#821）。部屋の指定なしは所属部屋すべて。
     *
     * @return list<int>
     * @throws UnauthorizedException 所属していない部屋の指定・所属部屋なし（403）
     */
    private function targetRoomIds(CopyReservationsInput $input): array
    {
        $activeRoomIds = $input->loginUserId > 0 ? $this->rooms->activeRoomIds($input->loginUserId) : [];
        $roomIds = $activeRoomIds === [] ? [] : array_map('intval', array_keys($this->rooms->selectableRooms($activeRoomIds)));
        if ($input->roomId !== null) {
            if (!in_array($input->roomId, $roomIds, true)) {
                throw new UnauthorizedException('所属していない部屋はコピーできません。');
            }

            return [$input->roomId];
        }
        if ($roomIds === []) {
            throw new UnauthorizedException('所属している部屋がないため、コピーできません。');
        }

        return $roomIds;
    }

    /**
     * コピーしない行か（コピー元で食べない、コピー先で二重予約・昼食と弁当の同時予約になる、すでに食べる行・承認済みの行がある）。
     */
    private function shouldSkip(MealReservation $source, string $dstDate, ChronosDate $today): bool
    {
        if (!$source->isEffective($today)) {
            return true;
        }
        if (array_diff($this->reservations->effectiveRoomIds($source->userId, $dstDate, $source->mealType), [$source->roomId]) !== []) {
            return true;
        }
        if (MealType::isExclusiveValue($source->mealType)
            && $this->reservations->effectiveRoomIds($source->userId, $dstDate, (int)MealType::counterpartValue($source->mealType)) !== []
        ) {
            return true;
        }
        $existing = $this->reservations->findOne($source->userId, $source->roomId, $dstDate, $source->mealType);

        return $existing !== null && ($existing->isEffective($today) || $existing->isApproved());
    }

    private static function dayOffset(ChronosDate $from, ChronosDate $to): int
    {
        return (int)$from->diff($to)->days * ($from->lessThanOrEquals($to) ? 1 : -1);
    }
}
