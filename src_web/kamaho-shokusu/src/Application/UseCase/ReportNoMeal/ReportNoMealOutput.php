<?php
declare(strict_types=1);

namespace App\Application\UseCase\ReportNoMeal;

/**
 * 今日の食事を「食べない」で報告した結果の Output DTO。
 */
final class ReportNoMealOutput
{
    /**
     * @param bool $approvedUnchanged 承認済みのため変えなかった食事があるか
     */
    public function __construct(
        public readonly string $message,
        public readonly bool $approvedUnchanged,
    ) {}
}
