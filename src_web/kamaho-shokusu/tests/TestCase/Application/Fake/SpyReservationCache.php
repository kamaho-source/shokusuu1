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

    public function forgetDate(string $date, array $userIds): void
    {
        $this->forgotten[] = ['date' => $date, 'userIds' => $userIds];
    }
}
