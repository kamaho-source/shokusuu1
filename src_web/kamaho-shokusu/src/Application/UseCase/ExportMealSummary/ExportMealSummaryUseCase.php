<?php
declare(strict_types=1);

namespace App\Application\UseCase\ExportMealSummary;

use App\Application\Port\AuditLogInterface;
use App\Application\Query\MealSummaryQueryInterface;
use App\Domain\Exception\InvalidInputException;
use App\Domain\Repository\MealPriceRepositoryInterface;
use App\Domain\Service\MealPriceRule;

/**
 * 控除表（職員ごとの月の食数と控除額）を出す（管理者）。全職員の氏名・職員ID・控除額が出るため、出力を監査ログに残す。
 *
 * その年の単価が無いときは出さない（以前は控除額が黙って 0 円になっていた）。
 */
final class ExportMealSummaryUseCase
{
    public function __construct(
        private readonly MealPriceRepositoryInterface $prices,
        private readonly MealSummaryQueryInterface $summary,
        private readonly AuditLogInterface $auditLog,
    ) {}

    /**
     * @throws InvalidInputException 年・月が不正（400）・その年の単価が無い（422）
     */
    public function execute(ExportMealSummaryInput $input): ExportMealSummaryOutput
    {
        $year  = $input->year;
        $month = $input->month;
        if ($year < 1 || $year > 9999 || $month < 1 || $month > 12) {
            throw new InvalidInputException('年・月の指定が正しくありません。', 400);
        }
        $price = $this->prices->findByYear($year) ?? throw new InvalidInputException(MealPriceRule::missingYearMessage($year), 422);

        $rows = [];
        foreach ($this->summary->staffMealCounts($year, $month) as $staff) {
            $rows[] = [
                'name'        => $staff->name,
                'staff_id'    => $staff->staffId,
                'meal_counts' => $staff->counts,
                'total_price' => $price->totalFor($staff->counts),
            ];
        }

        $this->auditLog->record('master', 'meal_price_excel_export', $input->actor, 'm_meal_price_info', null, ['year' => $year, 'month' => $month], true);

        return new ExportMealSummaryOutput($rows);
    }
}
