<?php
declare(strict_types=1);

namespace App\Application\Query;

use App\Application\Query\Dto\BulkRoomUsers;
use App\Application\Query\Dto\PersonalReservation;
use App\Application\Query\Dto\RoomUserMeals;

/**
 * 予約画面の API が使う予約の読み取り（部屋の利用者、一括登録、競合チェック用の時刻、個人予約、重複の有無）。
 *
 * 「食べる」の判定は、直前期間（境界日以前）なら i_change_flag（NULL なら eat_flag）、それより先は eat_flag。
 */
interface ReservationReadQueryInterface
{
    /**
     * 部屋の利用者（所属中で、削除されていない人。表示順）と、その日にこの部屋で食べる食事。
     *
     * @param string|null $date 'Y-m-d'。無ければ食事はすべて false
     * @return list<RoomUserMeals>
     */
    public function getUsersByRoom(int $roomId, ?string $date): array;

    /**
     * 一括登録の画面に出す部屋の利用者（ページ送り）と、その日の予約（この部屋・別の部屋）。
     *
     * @param string|null $date 'Y-m-d'
     */
    public function getUsersByRoomForBulk(int $roomId, ?string $date, int $page, int $limit): BulkRoomUsers;

    /**
     * 部屋の日付ごとの最終更新日時（楽観的ロック用。更新が無ければ作成日時）。
     *
     * @param list<string> $dates 'Y-m-d'
     * @return array<string, string|null> 日付 => 'Y-m-d H:i:s'
     */
    public function getReservationSnapshots(int $roomId, array $dates): array;

    /**
     * 本人のその日の予約（部屋ごとに、食べる食事）と、選べる部屋。
     *
     * @param string $date 'Y-m-d'
     */
    public function getPersonalReservation(int $userId, string $userName, string $date): PersonalReservation;

    /** その日・部屋・食事の予約行があるか（有効・無効を問わない）。 */
    public function hasDuplicateReservation(string $date, int $roomId, int $mealType): bool;
}
