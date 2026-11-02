<?php
declare(strict_types=1);

namespace App\Application\UseCase\ReportNoMeal;

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
 * 今日の食事を「食べない」で報告する（ダッシュボードの「食べない」ボタン）。
 *
 * - その日のすべての部屋の予約の i_change_flag を 0 にする（当日は直前期間なので eat_flag は残す）
 * - 承認済みの行は変えない
 * - 予約行が1つも無い食事は、代表部屋（所属中で削除されていない部屋のうち、ID の小さい部屋）に 0/0 の行を作る
 * - 途中で失敗したら、すべて取り消す
 */
final class ReportNoMealUseCase
{
    public function __construct(
        private readonly ReservationRepositoryInterface $reservations,
        private readonly RoomMembershipRepositoryInterface $rooms,
        private readonly ReservationCacheInterface $cache,
        private readonly ClockInterface $clock,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * @throws InvalidInputException 所属部屋が無い・保存の検証に失敗した
     * @throws ConflictException 同時に他の画面で予約が変わった
     * @throws PersistenceException DB の失敗（理由はログに残す）
     */
    public function execute(ReportNoMealInput $input): ReportNoMealOutput
    {
        $roomId = ReportMealSupport::primaryRoomId($this->rooms, $input->userId);
        $today  = new ChronosDate($this->clock->now());
        $date   = $today->format('Y-m-d');

        try {
            $rows = $this->reservations->transactional(function () use ($input, $roomId, $date): array {
                $rows = $this->reservations->findByUsersAndDates([$date], [$input->userId], MealType::values());
                foreach ($rows as $row) {
                    if ($row->isApproved()) {
                        continue;
                    }
                    if (!$this->reservations->updateWithVersion($row, 0, null, $input->userName)) {
                        throw new ConflictException(ReportMealSupport::CONFLICT_MESSAGE);
                    }
                }

                $reserved = array_map(static fn(MealReservation $r): int => $r->mealType, $rows);
                $missing  = [];
                foreach (MealType::values() as $mealType) {
                    if (!in_array($mealType, $reserved, true)) {
                        $missing[] = new MealReservation($input->userId, $date, $roomId, $mealType, 0, 0);
                    }
                }
                if ($missing !== []) {
                    $this->reservations->addMany($missing, $input->userName);
                }

                return $rows;
            });
        } catch (ReservationValidationException) {
            throw new InvalidInputException(ReportMealSupport::SAVE_FAILED_MESSAGE, 400);
        } catch (PersistenceException $e) {
            throw ReportMealSupport::dbFailure($this->logger, 'reportNoMeal', $input->userId, $e);
        }

        $this->cache->forgetDate($date, [$input->userId]);

        // 承認済みの食事は変えていないので、黙って「報告しました」にしない
        $approvedLeft = false;
        foreach ($rows as $row) {
            $approvedLeft = $approvedLeft || ($row->isApproved() && $row->isEffective($today));
        }

        return new ReportNoMealOutput(
            $approvedLeft ? '食べないで報告しました。承認済みの食事は変更されていません。' : '食べないで報告しました。',
            $approvedLeft,
        );
    }
}
