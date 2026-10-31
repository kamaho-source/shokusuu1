<?php
declare(strict_types=1);

namespace App\Domain\Service;

use App\Domain\Exception\InvalidInputException;

/**
 * 部屋の所属メンバーの表示順（m_user_info.i_disp_no）を、並べ直した順に割り当て直す計算。
 *
 * 表示順は部屋をまたいで共通のため、メンバーが今持っている番号を並べ直した順に配り直す
 * （部屋に所属しない人の番号は動かさない）。番号が足りない（NULL・重複がある）分は、
 * 全ユーザーの最大値の後ろに新しい番号を足す。
 */
final class MemberDisplayOrder
{
    /**
     * @param array<int, int|null> $current   同じグループのメンバー: ユーザーID => 今の i_disp_no
     * @param list<int>            $newOrder  並べ直した後のユーザーID（$current と同じ顔ぶれ）
     * @param int                  $maxDispNo 全ユーザーの i_disp_no の最大値
     * @return array<int, int> 番号が変わる人だけ: ユーザーID => 新しい i_disp_no
     * @throws InvalidInputException 顔ぶれが一致しないとき
     */
    public static function reassign(array $current, array $newOrder, int $maxDispNo): array
    {
        $sortedNew = $newOrder;
        sort($sortedNew);
        $sortedCurrent = array_keys($current);
        sort($sortedCurrent);
        if ($sortedNew !== $sortedCurrent) {
            throw new InvalidInputException('部屋の所属メンバーが変わっています。画面を読み込み直してください。');
        }

        $slots = array_values(array_unique(array_filter($current, static fn(?int $v): bool => $v !== null)));
        sort($slots);
        while (count($slots) < count($newOrder)) {
            $slots[] = ++$maxDispNo;
        }

        $changes = [];
        foreach ($newOrder as $i => $userId) {
            if ($current[$userId] !== $slots[$i]) {
                $changes[$userId] = $slots[$i];
            }
        }

        return $changes;
    }

    private function __construct() {}
}
