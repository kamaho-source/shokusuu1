<?php
declare(strict_types=1);

namespace App\Application\Query\Dto;

/**
 * 部屋の詳細の画面に出す内容（ある日・ある食事）。
 */
final class RoomMealDetail
{
    /**
     * @param list<string> $eatUsers 食べる人の名前（表示順）
     * @param list<string> $noEatUsers 食べない人・まだ登録していない人の名前（表示順）
     * @param list<OtherRoomEater> $otherRoomEaters 別の部屋で食べる人
     * @param bool $useChangeFlag 直前期間の判定（境界日以前は i_change_flag を見る）か
     */
    public function __construct(
        public readonly ?string $roomName,
        public readonly array $eatUsers,
        public readonly array $noEatUsers,
        public readonly array $otherRoomEaters,
        public readonly bool $useChangeFlag,
    ) {}
}
