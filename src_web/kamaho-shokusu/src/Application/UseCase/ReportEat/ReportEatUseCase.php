<?php
declare(strict_types=1);

namespace App\Application\UseCase\ReportEat;

use App\Application\Port\ReservationCacheInterface;
use App\Application\UseCase\ReportMeal\ReportMealSupport;
use App\Domain\Entity\MealReservation;
use App\Domain\Exception\ConflictException;
use App\Domain\Exception\InvalidInputException;
use App\Domain\Exception\PersistenceException;
use App\Domain\Exception\ReservationValidationException;
use App\Domain\Repository\ReservationRepositoryInterface;
use App\Domain\Repository\RoomMembershipRepositoryInterface;
use App\Domain\ValueObject\MealType;
use Cake\Chronos\ChronosDate;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;

/**
 * 今日の朝・昼・夕を「食べる」で報告する（代表部屋で i_change_flag=1 にする）。
 *
 * - 当日は直前期間なので、既存の行は eat_flag を残し、新しい行は eat_flag=0 で作る
 * - 弁当は昼食と同時に有効にできないため付けない。弁当を予約している日は昼食も付けない
 * - 別の部屋ですでに食べる食事は、その部屋の予約のまま残す（代表部屋にも付けると二重に数えるため）
 * - 承認済みの行は変えない
 */
final class ReportEatUseCase
{
    private const MEAL_TYPES = [MealType::Breakfast->value, MealType::Lunch->value, MealType::Dinner->value];

    public function __construct(
        private readonly ReservationRepositoryInterface $reservations,
        private readonly RoomMembershipRepositoryInterface $rooms,
        private readonly ReservationCacheInterface $cache,
        private readonly ClockInterface $clock,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * @throws InvalidInputException 所属部屋が無い・保存の検証に失敗した
     * @throws ConflictException 同時に他の画面で予約が変わった・二重予約になる
     * @throws PersistenceException DB の失敗（理由はログに残す）
     */
    public function execute(ReportEatInput $input): ReportEatOutput
    {
        $roomId = ReportMealSupport::primaryRoomId($this->rooms, $input->userId);
        $today  = new ChronosDate($this->clock->now());
        $date   = $today->format('Y-m-d');

        try {
            $approvedUnchanged = $this->reservations->transactional(function () use ($input, $roomId, $date, $today): array {
                $rows = $this->reservations->findByUsersAndDates([$date], [$input->userId], MealType::values());
                $eats = static fn(int $mealType, ?int $exceptRoom = null): bool => array_filter(
                    $rows,
                    static fn(MealReservation $r): bool => $r->mealType === $mealType && $r->roomId !== $exceptRoom && $r->isEffective($today)
                ) !== [];

                $approvedUnchanged = false;
                $new = [];
                foreach (self::MEAL_TYPES as $mealType) {
                    $counterpart = MealType::counterpartValue($mealType);
                    if (($counterpart !== null && $eats($counterpart)) || $eats($mealType, $roomId)) {
                        continue;
                    }

                    $primary = null;
                    foreach ($rows as $r) {
                        if ($r->mealType === $mealType && $r->roomId === $roomId) {
                            $primary = $r;
                        }
                    }
                    if ($primary === null) {
                        $new[] = new MealReservation($input->userId, $date, $roomId, $mealType, 0, 1);
                        continue;
                    }
                    if ($primary->isApproved()) {
                        $approvedUnchanged = $approvedUnchanged || !$primary->isEffective($today);
                        continue;
                    }
                    if (!$this->reservations->updateWithVersion($primary, 1, null, $input->userName)) {
                        throw new ConflictException(ReportMealSupport::CONFLICT_MESSAGE);
                    }
                }
                if ($new !== []) {
                    $this->reservations->addMany($new, $input->userName);
                }

                return [$approvedUnchanged];
            })[0];
        } catch (ReservationValidationException) {
            // 昼食と弁当は同時に有効にできない（テーブルの検証ルール）など。途中までの保存は取り消される
            throw new InvalidInputException(ReportMealSupport::SAVE_FAILED_MESSAGE, 400);
        } catch (PersistenceException $e) {
            throw ReportMealSupport::dbFailure($this->logger, 'reportEat', $input->userId, $e);
        }

        $this->cache->forgetDate($date, [$input->userId]);

        return new ReportEatOutput(
            $approvedUnchanged ? '食べるで報告しました。承認済みの食事は変更されていません。' : '食べるで報告しました。',
            $approvedUnchanged,
        );
    }
}
