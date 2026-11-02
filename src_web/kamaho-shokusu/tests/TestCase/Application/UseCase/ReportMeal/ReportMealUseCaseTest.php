<?php
declare(strict_types=1);

namespace App\Test\TestCase\Application\UseCase\ReportMeal;

use App\Application\UseCase\ReportNoMeal\ReportNoMealInput;
use App\Application\UseCase\ReportNoMeal\ReportNoMealUseCase;
use App\Domain\Entity\MealReservation;
use App\Domain\Exception\ConflictException;
use App\Test\TestCase\Application\Fake\FakeReservationRepository;
use App\Test\TestCase\Application\Fake\FakeRoomMembershipRepository;
use App\Test\TestCase\Application\Fake\SpyReservationCache;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;
use Psr\Log\NullLogger;

/**
 * 今日の食事の報告の UseCase（Repository は Fake。DB を使わない）。今日は 2026-11-02。
 *
 * 利用者 1 は部屋 5・3（削除済み）・8 に所属する。代表部屋は削除されていない部屋のうち小さい 5。
 */
class ReportMealUseCaseTest extends TestCase
{
    private const TODAY = '2026-11-02';

    private FakeReservationRepository $reservations;
    private SpyReservationCache $cache;
    private FakeRoomMembershipRepository $rooms;
    private ClockInterface $clock;

    protected function setUp(): void
    {
        parent::setUp();
        $this->reservations = new FakeReservationRepository();
        $this->cache        = new SpyReservationCache();
        $this->rooms        = new FakeRoomMembershipRepository(['1:8', '1:3', '1:5'], [3], [5 => '部屋5', 8 => '部屋8']);
        $this->clock        = new class implements ClockInterface {
            public function now(): \DateTimeImmutable
            {
                return new \DateTimeImmutable('2026-11-02 08:00:00', new \DateTimeZone('Asia/Tokyo'));
            }
        };
    }

    private function noMeal(): ReportNoMealUseCase
    {
        return new ReportNoMealUseCase($this->reservations, $this->rooms, $this->cache, $this->clock, new NullLogger());
    }


    /** @return array<string, array{int|null, int|null}> "部屋:食事" => [eat_flag, i_change_flag] */
    private function flags(): array
    {
        $map = [];
        foreach ($this->reservations->records as $r) {
            $map[$r->roomId . ':' . $r->mealType] = [$r->eatFlag, $r->changeFlag];
        }
        ksort($map);

        return $map;
    }

    public function testNoMeal_cancelsEveryRoomAndAddsMissingMealsToPrimaryRoom(): void
    {
        $this->reservations->records = [
            new MealReservation(1, self::TODAY, 8, 1, 1, 1),
            new MealReservation(1, self::TODAY, 5, 3, 0, null),
            new MealReservation(1, '2026-11-03', 5, 1, 1, 1), // 明日は変えない
            new MealReservation(2, self::TODAY, 5, 1, 1, 1),  // 他人は変えない
        ];

        $out = $this->noMeal()->execute(new ReportNoMealInput(1, 'U'));

        $this->assertSame('食べないで報告しました。', $out->message);
        $this->assertFalse($out->approvedUnchanged);
        $this->assertSame(['5:1' => [1, 1], '5:2' => [0, 0], '5:3' => [0, 0], '5:4' => [0, 0], '8:1' => [1, 0]], $this->flags());
        $this->assertSame([['date' => self::TODAY, 'userIds' => [1]]], $this->cache->forgotten);
    }

    public function testNoMeal_approvedEffectiveRowIsKeptAndReported(): void
    {
        $this->reservations->records = [new MealReservation(1, self::TODAY, 8, 2, 1, 1, 1, 2)];

        $out = $this->noMeal()->execute(new ReportNoMealInput(1, 'U'));

        $this->assertSame('食べないで報告しました。承認済みの食事は変更されていません。', $out->message);
        $this->assertTrue($out->approvedUnchanged);
        $this->assertSame([], $this->reservations->updates);
    }

    /** 承認済みでも食べない行なら、「変更されていません」とは言わない */
    public function testNoMeal_approvedNotEffectiveRowIsNotMentioned(): void
    {
        $this->reservations->records = [new MealReservation(1, self::TODAY, 8, 2, 1, 0, 1, 1)];

        $this->assertFalse($this->noMeal()->execute(new ReportNoMealInput(1, 'U'))->approvedUnchanged);
    }

    /** 同時に他の画面で予約が変わったら、競合として止め、行を足さない */
    public function testNoMeal_versionConflict_throwsAndAddsNothing(): void
    {
        $this->reservations->records = [new MealReservation(1, self::TODAY, 5, 1, 1, 1)];
        $this->reservations->onUpdate = static fn(): bool => false;

        try {
            $this->noMeal()->execute(new ReportNoMealInput(1, 'U'));
            $this->fail('競合にならなかった');
        } catch (ConflictException $e) {
            $this->assertStringContainsString('他の画面で予約が変更されました', $e->getMessage());
        }
        $this->assertSame([], $this->reservations->added);
        $this->assertSame([], $this->cache->forgotten);
    }






}
