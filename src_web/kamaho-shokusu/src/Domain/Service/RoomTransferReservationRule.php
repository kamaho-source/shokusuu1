<?php
declare(strict_types=1);

namespace App\Domain\Service;

use App\Domain\Entity\MealReservation;
use Cake\Chronos\ChronosDate;

/**
 * 部屋異動で、異動元の部屋の予約1件を異動先の部屋へ移すときの決め方。
 *
 * - 異動元の行は消える。食数として数える行（食べる）なら、異動元の集計を1減らす。
 * - 異動先に同じ日・食事の行が無ければ、異動元の行を写して作る。数える行なら異動先の集計を1増やす。
 * - 異動先に行があり、それが取消（数えない）で異動元が数える行なら、異動先の行に異動元の予約を写す
 *   （部屋変更で残った取消行に引き継がないと予約が消える）。集計は数える値の差だけ増減する。
 * - それ以外で異動先に行があるときは、異動先の行をそのまま残す。
 */
final class RoomTransferReservationRule
{
    /**
     * @param MealReservation $source 異動元の行
     * @param MealReservation|null $destination 異動先の同じ日・食事の行
     * @param ChronosDate $today 数える値を決める今日（実際の今日）
     * @return array{fromDelta: int, toDelta: int, overwriteDestination: bool, createDestination: bool}
     */
    public static function plan(MealReservation $source, ?MealReservation $destination, ChronosDate $today): array
    {
        // 集計で数えるのは 1（食べる）だけ。0・1 以外の値が入っていても増減は ±1 まで（従来どおり）
        $counted = $source->isEffective($today) ? 1 : 0;
        if ($destination === null) {
            return ['fromDelta' => -$counted, 'toDelta' => $counted, 'overwriteDestination' => false, 'createDestination' => true];
        }
        $overwrite = !$destination->isEffective($today) && $counted === 1;

        return [
            'fromDelta'            => -$counted,
            'toDelta'              => $overwrite ? $counted - $destination->countedValue($today) : 0,
            'overwriteDestination' => $overwrite,
            'createDestination'    => false,
        ];
    }
}
