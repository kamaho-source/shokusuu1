<?php
declare(strict_types=1);

namespace App\Test\TestCase\Application\Fake;

use App\Domain\Repository\ReservationRepositoryInterface;

/**
 * テスト用の予約 Repository（メモリ上）。
 *
 * - $rows: "userId:roomId:date:meal" => ['eat' => int, 'effective' => bool]
 * - $onToggle: toggle の代わりに呼ぶ処理（例外を投げて保存失敗を再現する）
 */
final class FakeReservationRepository implements ReservationRepositoryInterface
{
    /** @var array<string, array{eat: int, effective: bool}> */
    public array $rows = [];

    /** @var list<array{userId:int, roomId:int, date:string, meal:int, on:bool, eatFlag:int, changeFlag:int}> */
    public array $toggles = [];

    /** @var (\Closure(int $meal, int $roomId): void)|null */
    public ?\Closure $onToggle = null;

    public int $transactions = 0;

    public function add(int $userId, int $roomId, string $date, int $meal, int $eat = 1, bool $effective = true): void
    {
        $this->rows["{$userId}:{$roomId}:{$date}:{$meal}"] = ['eat' => $eat, 'effective' => $effective];
    }

    public function exists(int $userId, int $roomId, string $date, int $mealType): bool
    {
        return isset($this->rows["{$userId}:{$roomId}:{$date}:{$mealType}"]);
    }

    public function effectiveRoomIds(int $userId, string $date, int $mealType): array
    {
        $ids = [];
        foreach ($this->rows as $key => $row) {
            [$u, $r, $d, $m] = explode(':', $key);
            if ((int)$u === $userId && $d === $date && (int)$m === $mealType && $row['effective']) {
                $ids[] = (int)$r;
            }
        }

        return $ids;
    }

    public function eatFlag(int $userId, int $roomId, string $date, int $mealType): ?int
    {
        return $this->rows["{$userId}:{$roomId}:{$date}:{$mealType}"]['eat'] ?? null;
    }

    public function toggle(int $userId, int $roomId, string $date, int $mealType, bool $on, string $actor, int $eatFlag, int $changeFlag): array
    {
        if ($this->onToggle !== null) {
            ($this->onToggle)($mealType, $roomId);
        }
        $this->toggles[] = compact('userId', 'roomId', 'date') + ['meal' => $mealType, 'on' => $on, 'eatFlag' => $eatFlag, 'changeFlag' => $changeFlag];
        $this->add($userId, $roomId, $date, $mealType, $eatFlag, $on);

        return ['value' => $on, 'details' => []];
    }

    public function transactional(callable $work): array
    {
        $this->transactions++;
        $before = [$this->rows, $this->toggles];
        try {
            return $work();
        } catch (\Throwable $e) {
            [$this->rows, $this->toggles] = $before;
            throw $e;
        }
    }
}
