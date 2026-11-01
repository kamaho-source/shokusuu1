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

    public function forgetDates(array $userIdsByDate, bool $bumpVersion = true): void
    {
        foreach ($userIdsByDate as $date => $userIds) {
            ReservationCache::forgetDate((string)$date, $userIds);
        }
        if ($bumpVersion) {
            ReservationCache::bumpVersion();
        }
    }
}
