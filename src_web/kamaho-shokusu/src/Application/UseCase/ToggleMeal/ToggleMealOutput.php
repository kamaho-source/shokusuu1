<?php
declare(strict_types=1);

namespace App\Application\UseCase\ToggleMeal;

/**
 * 1件の食事予約の登録・取消（トグル）の Output DTO。
 */
final class ToggleMealOutput
{
    /**
     * @param array<mixed> $details その日の予約の詳細（画面の再描画用）
     */
    public function __construct(
        public readonly bool $value,
        public readonly array $details,
        public readonly string $date,
        public readonly int $roomId,
        public readonly int $userId,
    ) {}

    /**
     * 従来の配列の形（value, details, affected）。
     *
     * @return array{value: bool, details: array<mixed>, affected: array{date: string, roomId: int, userId: int}}
     */
    public function toArray(): array
    {
        return [
            'value'    => $this->value,
            'details'  => $this->details,
            'affected' => ['date' => $this->date, 'roomId' => $this->roomId, 'userId' => $this->userId],
        ];
    }
}
