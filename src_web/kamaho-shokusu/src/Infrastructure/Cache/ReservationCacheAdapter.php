<?php
declare(strict_types=1);

namespace App\Infrastructure\Cache;

use App\Application\Port\ReservationCacheInterface;

/**
 * ReservationCacheInterface の実装（ReservationCache を使う）。
 */
final class ReservationCacheAdapter implements ReservationCacheInterface
{
    public function forgetDate(string $date, array $userIds): void
    {
        if ($date === '') {
            return;
        }
        ReservationCache::forgetDate($date, $userIds);
        ReservationCache::bumpVersion();
    }
}
