<?php
declare(strict_types=1);

namespace App\Application\UseCase\CopyReservations;

use App\Domain\Exception\UnauthorizedException;

/**
 * 予約コピーの件数を、書き込まずに数える。判定はコピーと同じ（ReservationCopyPlanner）。
 * 誰がコピーしてよいか（認可）は呼び出し側で確かめる。
 */
final class PreviewReservationCopyUseCase
{
    public function __construct(private readonly ReservationCopyPlanner $planner) {}

    /**
     * @throws UnauthorizedException 所属していない部屋の指定・所属部屋なし（403）
     */
    public function execute(CopyReservationsInput $input): ReservationCopyPreview
    {
        $rows = $this->planner->sourceRows($input);
        $today = $this->planner->today();
        $copy = $skip = $invalid = 0;
        foreach ($rows as $row) {
            $verdict = $this->planner->verdict($row, $this->planner->targetDate($input, $row), $today);
            match ($verdict) {
                ReservationCopyPlanner::COPY => $copy++,
                ReservationCopyPlanner::SKIP => $skip++,
                ReservationCopyPlanner::INVALID_DATE => $invalid++,
            };
        }
        if ($input->isWeek()) {
            // 従来どおり、週は今日より前の日もスキップに数える
            return new ReservationCopyPreview($input->mode, count($rows), $copy, $skip + $invalid, 0);
        }

        return new ReservationCopyPreview($input->mode, count($rows), $copy, $skip, $invalid);
    }
}
