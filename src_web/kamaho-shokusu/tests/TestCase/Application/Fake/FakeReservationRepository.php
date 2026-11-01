<?php
declare(strict_types=1);

namespace App\Test\TestCase\Application\Fake;

use App\Application\Port\TransactionManagerInterface;
use App\Domain\Entity\MealReservation;
use App\Domain\Repository\ReservationRepositoryInterface;

/**
 * テスト用の予約 Repository（メモリ上）。トランザクションも兼ねる（取り消すと begin の時点に戻る）。
 *
 * - $rows: "userId:roomId:date:meal" => ['eat' => int, 'effective' => bool]（toggle 系の UseCase が使う）
 * - $onToggle: toggle の代わりに呼ぶ処理（例外を投げて保存失敗を再現する）
 * - $records: 予約行（個人・部屋単位・一括の UseCase が使う）
 * - $onUpdate / $onAdd: 更新・保存の前に呼ぶ処理（false を返すと競合、例外で保存失敗を再現する）
 */
final class FakeReservationRepository implements ReservationRepositoryInterface, TransactionManagerInterface
{
    /** @var list<MealReservation> */
    public array $records = [];

    /** @var list<array{row: MealReservation, changeFlag: int, eatFlag: int|null, actor: string}> */
    public array $updates = [];

    /** @var list<MealReservation> */
    public array $added = [];

    /** @var (\Closure(MealReservation $row): bool)|null */
    public ?\Closure $onUpdate = null;

    /** @var (\Closure(list<MealReservation> $rows): void)|null */
    public ?\Closure $onAdd = null;

    /** @var array<string, string|null> "roomId:date" => 最終更新日時 */
    public array $latestUpdates = [];

    public int $commits = 0;

    public int $rollbacks = 0;

    /** @var list<array{0: list<MealReservation>, 1: list<array<string, mixed>>, 2: list<MealReservation>}> */
    private array $savepoints = [];

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

    public function record(int $userId, string $date, int $roomId, int $mealType, ?int $eatFlag = 1, ?int $changeFlag = 1, ?int $version = 1): MealReservation
    {
        return $this->records[] = new MealReservation($userId, $date, $roomId, $mealType, $eatFlag, $changeFlag, $version);
    }

    /** 予約行を探す（無ければ null）。 */
    public function find(int $userId, string $date, int $roomId, int $mealType): ?MealReservation
    {
        foreach ($this->records as $r) {
            if ($r->userId === $userId && $r->date === $date && $r->roomId === $roomId && $r->mealType === $mealType) {
                return $r;
            }
        }

        return null;
    }

    public function findByUsersAndDates(array $dates, array $userIds, array $mealTypes, ?int $roomId = null): array
    {
        return array_values(array_filter($this->records, static fn(MealReservation $r): bool => in_array($r->date, $dates, true)
            && in_array($r->userId, array_map('intval', $userIds), true)
            && in_array($r->mealType, $mealTypes, true)
            && ($roomId === null || $r->roomId === $roomId)));
    }

    public function updateWithVersion(MealReservation $row, int $changeFlag, ?int $eatFlag, string $actor): bool
    {
        if ($this->onUpdate !== null && !($this->onUpdate)($row)) {
            return false;
        }
        $this->updates[] = compact('row', 'changeFlag', 'eatFlag', 'actor');
        foreach ($this->records as $i => $r) {
            if ($r->userId === $row->userId && $r->date === $row->date && $r->roomId === $row->roomId && $r->mealType === $row->mealType) {
                $this->records[$i] = new MealReservation($r->userId, $r->date, $r->roomId, $r->mealType, $eatFlag ?? $r->eatFlag, $changeFlag, (int)$r->version + 1);
            }
        }

        return true;
    }

    public function addMany(array $rows, string $actor, bool $validate = true): void
    {
        if ($this->onAdd !== null) {
            ($this->onAdd)($rows);
        }
        foreach ($rows as $row) {
            $this->records[] = $row;
            $this->added[]   = $row;
        }
    }

    public function latestUpdateByDate(int $roomId, array $dates): array
    {
        $latest = [];
        foreach ($dates as $date) {
            if (array_key_exists("{$roomId}:{$date}", $this->latestUpdates)) {
                $latest[$date] = $this->latestUpdates["{$roomId}:{$date}"];
            }
        }

        return $latest;
    }

    public function begin(): void
    {
        $this->savepoints[] = [$this->records, $this->updates, $this->added];
    }

    public function commit(): void
    {
        array_pop($this->savepoints);
        $this->commits++;
    }

    public function rollback(): void
    {
        $saved = array_pop($this->savepoints);
        if ($saved !== null) {
            [$this->records, $this->updates, $this->added] = $saved;
        }
        $this->rollbacks++;
    }

    public function inTransaction(): bool
    {
        return $this->savepoints !== [];
    }
}
