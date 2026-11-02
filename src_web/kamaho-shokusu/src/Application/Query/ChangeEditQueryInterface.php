<?php
declare(strict_types=1);

namespace App\Application\Query;

use App\Application\Query\Dto\ChangeEditContext;
use App\Domain\Exception\NotFoundException;

/**
 * 直前編集の画面の読み取り。
 */
interface ChangeEditQueryInterface
{
    /**
     * 選べる部屋の中から、その日に予約がある部屋（無ければ先頭の部屋）。部屋が無ければ null。
     *
     * @param array<int, string> $rooms 部屋ID => 部屋名（並び順のまま）
     */
    public function defaultRoomId(array $rooms, string $date): ?int;

    /** 部屋の名前。部屋が無ければ null。 */
    public function roomName(int $roomId): ?string;

    /**
     * 部屋の利用者（見る人がその人の予約を変えられるか＝allowEdit 付き）と、その日の予約（どの部屋で食べるか）。
     *
     * @param int $loginUserId 見る人（ログイン者）の利用者ID
     * @param int $loginAdmin  見る人の i_admin
     * @param int $loginLevel  見る人の i_user_level
     * @throws NotFoundException 部屋が無い
     */
    public function context(int $roomId, string $date, int $loginUserId, int $loginAdmin, int $loginLevel): ChangeEditContext;

    /**
     * 利用者本人の、その日に食べる予約（食事と部屋）。個人の直前編集の初期表示に使う。
     *
     * @return list<array{type: int, room_id: int}>
     */
    public function ownMeals(int $userId, string $date): array;
}
