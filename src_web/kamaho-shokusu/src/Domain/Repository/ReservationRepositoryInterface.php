<?php
declare(strict_types=1);

namespace App\Domain\Repository;

use App\Domain\Entity\MealReservation;
use App\Domain\Exception\ConflictException;
use App\Domain\Exception\DomainException;
use App\Domain\Exception\ReservationValidationException;

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

    /**
     * 利用者・日付・食事区分（部屋を指定すればその部屋だけ）の予約行。並びは DB の読み取り順のまま。
     *
     * 読み取りの失敗は変換せずにそのまま投げる（呼び出し側が従来どおりの応答に変える）。
     *
     * @param list<string> $dates
     * @param list<int>    $userIds
     * @param list<int>    $mealTypes
     * @return list<MealReservation>
     * @throws \RuntimeException 読み取りに失敗したとき
     */
    public function findByUsersAndDates(array $dates, array $userIds, array $mealTypes, ?int $roomId = null): array;

    /**
     * 予約行を楽観的ロックつきで更新する（承認済みの行は更新しない）。
     *
     * @param int|null $eatFlag null なら当初予定（eat_flag）は変えない
     * @return bool false = 楽観的ロック競合
     * @throws ConflictException 承認済みの行、または有効にすると別の部屋の予約・昼食と弁当の同時予約になるとき
     * @throws \RuntimeException それ以外の失敗（変換せずにそのまま投げる）
     */
    public function updateWithVersion(MealReservation $row, int $changeFlag, ?int $eatFlag, string $actor): bool;

    /**
     * 予約行を新しく保存する（version は 1）。
     *
     * @param list<MealReservation> $rows
     * @param bool $validate false なら入力の検証（型変換）をせずに値をそのまま入れる（一括登録の従来の保存）
     * @throws ReservationValidationException 検証ルールに当たったとき
     * @throws ConflictException 別の部屋で同じ食事が有効なとき
     * @throws \RuntimeException それ以外の失敗（変換せずにそのまま投げる）
     */
    public function addMany(array $rows, string $actor, bool $validate = true): void;

    /**
     * 部屋の日付ごとの最終更新日時（更新が無ければ作成日時）。'Y-m-d H:i:s' の文字列。
     *
     * @param list<string> $dates
     * @return array<string, string|null> 日付 => 最終更新日時（行が無い日付は含まない）
     * @throws \RuntimeException 読み取りに失敗したとき
     */
    public function latestUpdateByDate(int $roomId, array $dates): array;
}
