<?php
declare(strict_types=1);

namespace App\Domain\ValueObject;

/**
 * エクセル食数予約で表示する週（月曜始まり）と、前後の週へ移れるか。
 */
final class MealGridWeek
{
    public function __construct(
        public readonly \DateTimeImmutable $monday,
        public readonly \DateTimeImmutable $prevMonday,
        public readonly \DateTimeImmutable $nextMonday,
        public readonly bool $canGoPrev,
        public readonly bool $canGoNext,
    ) {}

    /** 表示する週の月曜日（YYYY-MM-DD）。 */
    public function mondayString(): string
    {
        return $this->monday->format('Y-m-d');
    }
}
