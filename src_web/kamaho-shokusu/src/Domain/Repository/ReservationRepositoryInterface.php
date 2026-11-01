<?php
declare(strict_types=1);

namespace App\Domain\Repository;

use App\Domain\Exception\DomainException;

/**
 * 食事予約（t_individual_reservation_info）の読み書き。
 *
 * 実装は、保存の失敗を Domain の例外に変換して投げる（二重予約・昼食と弁当の同時予約・承認済み・競合など）。
 */
interface ReservationRepositoryInterface
{
    /**
     * その利用者・部屋・日・食事の予約行があるか（有効・無効を問わない）。
     *
     * @throws DomainException 読み取りに失敗したとき
     */
    public function exists(int $userId, int $roomId, string $date, int $mealType): bool;

    /**
     * その利用者・日・食事が有効（食べる）になっている部屋。
     *
     * @return list<int>
     * @throws DomainException 読み取りに失敗したとき
     */
    public function effectiveRoomIds(int $userId, string $date, int $mealType): array;

    /**
     * 予約行の当初予定（eat_flag）。行が無ければ null。
     *
     * @throws DomainException 読み取りに失敗したとき
     */
    public function eatFlag(int $userId, int $roomId, string $date, int $mealType): ?int;

    /**
     * 1件の食事予約を登録・取消する。昼食⇔弁当を登録すると、同じ部屋の相手は取り消される。
     *
     * @return array{value: bool, details: array<mixed>}
     * @throws DomainException 二重予約・昼食と弁当の同時予約・承認済み・競合・入力不正・保存失敗
     */
    public function toggle(
        int $userId,
        int $roomId,
        string $date,
        int $mealType,
        bool $on,
        string $actor,
        int $eatFlag,
        int $changeFlag
    ): array;

    /**
     * $work を1トランザクションで実行する。例外が出たら取り消す。
     *
     * @param callable(): array<mixed> $work
     * @return array<mixed> $work の戻り値
     * @throws DomainException $work が投げた例外、またはトランザクションの失敗
     */
    public function transactional(callable $work): array;
}
