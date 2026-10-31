<?php
declare(strict_types=1);

namespace App\Test\TestCase\Infrastructure\Cache;

use App\Infrastructure\Cache\ReservationCache;
use Cake\Cache\Cache;
use Cake\I18n\DateTime;
use Cake\TestSuite\TestCase;

/**
 * 予約キャッシュのキーと破棄のテスト。
 */
class ReservationCacheTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Cache::clear('default');
        DateTime::setTestNow(new DateTime('2026-11-01 09:00:00', 'Asia/Tokyo'));
    }

    protected function tearDown(): void
    {
        DateTime::setTestNow(null);
        Cache::clear('default');
        parent::tearDown();
    }

    public function testForgetDate_deletesMealCountsAndTodayReportOnlyForToday(): void
    {
        foreach (['2026-11-01', '2026-11-02'] as $date) {
            Cache::write(ReservationCache::mealCountsKey($date), ['x'], 'default');
            Cache::write('meal_counts:' . $date, ['old'], 'default');
            ReservationCache::markTodayReported(7, $date);
        }

        ReservationCache::forgetDate('2026-11-01', [7]);
        ReservationCache::forgetDate('2026-11-02', [7]);

        foreach (['2026-11-01', '2026-11-02'] as $date) {
            $this->assertNull(Cache::read(ReservationCache::mealCountsKey($date), 'default'), $date);
            $this->assertNull(Cache::read('meal_counts:' . $date, 'default'), $date);
        }
        $this->assertNull(Cache::read(ReservationCache::todayReportKey(7, '2026-11-01'), 'default'), '今日の印は消える');
        $this->assertSame(1, Cache::read(ReservationCache::todayReportKey(7, '2026-11-02'), 'default'), '今日以外の印は残す');
    }

    public function testBumpVersion_startsAtTwoAndIncrements(): void
    {
        $this->assertSame(1, ReservationCache::version());
        ReservationCache::bumpVersion();
        $this->assertSame(2, ReservationCache::version());
        ReservationCache::bumpVersion();
        $this->assertSame(3, ReservationCache::version());
    }
}
