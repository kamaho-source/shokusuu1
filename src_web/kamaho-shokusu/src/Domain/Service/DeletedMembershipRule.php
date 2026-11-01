<?php
declare(strict_types=1);

namespace App\Domain\Service;

/**
 * 利用者の削除で外した所属かどうかのルール（復元で所属中に戻す部屋・削除済みの人の「削除前の所属」の表示）。
 *
 * - 削除で外した所属には印（i_left_by_deletion=1）を付ける。部屋異動で外れた所属（active_flag=1 だけ）は対象外。
 * - 印の列を足す前に削除された人（どの行にも印が無い人）は、削除と同じ時刻（前後数秒）に外れた行を、削除で外した所属とみなす。
 *   削除の処理は、所属を外す時刻と利用者を削除済みにする時刻をほぼ同じにしていた。
 *   削除のあとに利用者の行が更新されて時刻がずれた人は見分けられないため、対象なし（所属なしで復元）にする。
 */
final class DeletedMembershipRule
{
    /** 印の無い人で、削除と同じ時刻とみなす幅（秒）。 */
    public const LEGACY_TOLERANCE_SECONDS = 5;

    /**
     * @param int|null $activeFlag     所属の active_flag（1=退出済み）
     * @param int|null $leftByDeletion 所属の i_left_by_deletion
     * @param bool     $userHasMarks   その利用者のどれかの所属に印があるか
     * @param \DateTimeInterface|null $groupUpdated 所属の更新日時
     * @param \DateTimeInterface|null $userUpdated  利用者の更新日時（削除した時刻）
     */
    public static function isLeftByDeletion(
        ?int $activeFlag,
        ?int $leftByDeletion,
        bool $userHasMarks,
        ?\DateTimeInterface $groupUpdated,
        ?\DateTimeInterface $userUpdated,
    ): bool {
        if ($activeFlag !== 1) {
            return false;
        }
        if ($userHasMarks) {
            return $leftByDeletion === 1;
        }
        if ($groupUpdated === null || $userUpdated === null) {
            return false;
        }

        return abs($groupUpdated->getTimestamp() - $userUpdated->getTimestamp()) <= self::LEGACY_TOLERANCE_SECONDS;
    }
}
