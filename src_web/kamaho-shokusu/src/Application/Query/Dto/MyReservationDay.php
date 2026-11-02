<?php
declare(strict_types=1);

namespace App\Application\Query\Dto;

/**
 * 自分のある日の予約。食事ごとに 1=食べる・0=食べない・null=予約行なし、食べる部屋（食べるときだけ）。
 */
final class MyReservationDay
{
    public function __construct(
        public readonly ?int $breakfast = null,
        public readonly ?int $breakfastRoom = null,
        public readonly ?int $lunch = null,
        public readonly ?int $lunchRoom = null,
        public readonly ?int $dinner = null,
        public readonly ?int $dinnerRoom = null,
        public readonly ?int $bento = null,
        public readonly ?int $bentoRoom = null,
    ) {}

    /** どれか1つでも食べるか。 */
    public function hasAnyMeal(): bool
    {
        return in_array(1, [$this->breakfast, $this->lunch, $this->dinner, $this->bento], true);
    }

    /**
     * 画面（JS）に渡す形（従来のキー）。
     *
     * @return array{breakfast: int|null, breakfastRoom: int|null, lunch: int|null, lunchRoom: int|null, dinner: int|null, dinnerRoom: int|null, bento: int|null, bentoRoom: int|null}
     */
    public function toArray(): array
    {
        return [
            'breakfast'     => $this->breakfast,
            'breakfastRoom' => $this->breakfastRoom,
            'lunch'         => $this->lunch,
            'lunchRoom'     => $this->lunchRoom,
            'dinner'        => $this->dinner,
            'dinnerRoom'    => $this->dinnerRoom,
            'bento'         => $this->bento,
            'bentoRoom'     => $this->bentoRoom,
        ];
    }
}
