<?php
declare(strict_types=1);

namespace App\Test\TestCase\Application\Fake;

use App\Application\Port\ReservationCacheInterface;

/**
 * キャッシュの破棄を記録するだけのテスト用実装。
 */
final class SpyReservationCache implements ReservationCacheInterface
{
    /** @var list<array{date: string, userIds: list<int>}> */
    public array $forgotten = [];

    public int $versionBumps = 0;

    public function forgetDate(string $date, array $userIds): void
    {
        $this->forgotten[] = ['date' => $date, 'userIds' => $userIds];
        $this->versionBumps++;
    }

    public function forgetDates(array $userIdsByDate, bool $bumpVersion = true): void
    {
        foreach ($userIdsByDate as $date => $userIds) {
            $this->forgotten[] = ['date' => (string)$date, 'userIds' => $userIds];
        }
        $this->versionBumps += $bumpVersion ? 1 : 0;
    }

    /** @var list<array{date: string, userId: int}> forgetAfterRoomTransfer の呼び出し */
    public array $afterTransfer = [];

    public function forgetAfterRoomTransfer(string $date, int $userId): void
    {
        $this->afterTransfer[] = ['date' => $date, 'userId' => $userId];
    }
}
