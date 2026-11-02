<?php
declare(strict_types=1);

namespace App\Test\TestCase\Domain\Service;

use App\Domain\Entity\MealReservation;
use App\Domain\Service\RoomTransferReservationRule;
use Cake\Chronos\ChronosDate;
use PHPUnit\Framework\TestCase;

/**
 * 部屋異動で予約1件を移すときの決め方。今日は 2026-11-02。直前期間（今日〜14日先）は i_change_flag で数える。
 */
class RoomTransferReservationRuleTest extends TestCase
{
    /** @return array<string, array{MealReservation, MealReservation|null, array<string, int|bool>}> */
    public static function cases(): array
    {
        $r = fn(int $roomId, ?int $eat, ?int $change, string $date = '2026-12-01') => new MealReservation(1, $date, $roomId, 1, $eat, $change);

        return [
            '異動先なし・食べる'              => [$r(1, 1, 1), null, ['fromDelta' => -1, 'toDelta' => 1, 'overwriteDestination' => false, 'createDestination' => true]],
            '異動先なし・食べない'            => [$r(1, 0, 0), null, ['fromDelta' => 0, 'toDelta' => 0, 'overwriteDestination' => false, 'createDestination' => true]],
            '異動先が取消・異動元が食べる'    => [$r(1, 1, 1), $r(2, 0, 0), ['fromDelta' => -1, 'toDelta' => 1, 'overwriteDestination' => true, 'createDestination' => false]],
            '異動先が食べる・異動元も食べる'  => [$r(1, 1, 1), $r(2, 1, 1), ['fromDelta' => -1, 'toDelta' => 0, 'overwriteDestination' => false, 'createDestination' => false]],
            '異動先が取消・異動元も食べない'  => [$r(1, 0, 0), $r(2, 0, 0), ['fromDelta' => 0, 'toDelta' => 0, 'overwriteDestination' => false, 'createDestination' => false]],
            '直前期間に追加（eat0/chg1）'     => [$r(1, 0, 1, '2026-11-07'), null, ['fromDelta' => -1, 'toDelta' => 1, 'overwriteDestination' => false, 'createDestination' => true]],
            '直前期間で取消（eat1/chg0）'     => [$r(1, 1, 0, '2026-11-07'), null, ['fromDelta' => 0, 'toDelta' => 0, 'overwriteDestination' => false, 'createDestination' => true]],
            '想定外の値（eat2）は数えない'    => [$r(1, 2, 2), null, ['fromDelta' => 0, 'toDelta' => 0, 'overwriteDestination' => false, 'createDestination' => true]],
            '異動先が想定外の値（eat2）'      => [$r(1, 1, 1), $r(2, 2, 2), ['fromDelta' => -1, 'toDelta' => -1, 'overwriteDestination' => true, 'createDestination' => false]],
            '直前の変更なし（eat1/chgNULL）'  => [$r(1, 1, null, '2026-11-07'), $r(2, 0, 0, '2026-11-07'), ['fromDelta' => -1, 'toDelta' => 1, 'overwriteDestination' => true, 'createDestination' => false]],
        ];
    }

    /**
     * @dataProvider cases
     * @param array<string, int|bool> $expected
     */
    public function testPlan(MealReservation $source, ?MealReservation $destination, array $expected): void
    {
        $this->assertSame($expected, RoomTransferReservationRule::plan($source, $destination, new ChronosDate('2026-11-02')));
    }
}
