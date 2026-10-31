<?php
declare(strict_types=1);

namespace App\Service;

use App\Application\UseCase\ChangeMealRoom\ChangeMealRoomInput;
use App\Application\UseCase\RegisterGroupReservation\RegisterGroupReservationInput;
use App\Application\UseCase\RegisterIndividualReservation\RegisterIndividualReservationInput;
use App\Application\UseCase\ToggleMeal\ToggleMealInput;
use App\Domain\Exception\DomainException;
use App\Domain\Exception\PersistenceException;
use App\Infrastructure\Persistence\SystemError;
use App\Infrastructure\ReservationUseCases;
use Cake\ORM\Table;

class ReservationWriteService
{
    public function __construct(
        private Table $reservationTable,
        private Table $userTable,
        private Table $roomTable,
        private string $webroot
    ) {}

    /**
     * 個人予約（1人 × 1日。食事区分ごとに1部屋）を保存する。RegisterIndividualReservationUseCase を使う。
     *
     * @param array|string $jsonData      リクエスト（JSON 文字列または配列）
     * @param array        $rooms         画面で扱う部屋（キーが部屋ID）
     * @param callable     $dateValidator 予約日を受け取り、true（通る）または理由の文字列を返す
     * @return array{ok: true, message: string, data: array, redirect: string}
     * @throws DomainException 入力・日付の不正、権限不足、競合、保存失敗
     */
    public function processIndividualReservation(
        string $reservationDate,
        array|string $jsonData,
        array $rooms,
        int $userId,
        string $userName,
        callable $dateValidator
    ): array {
        return $this->useCases()->registerIndividualReservation()->execute(new RegisterIndividualReservationInput(
            reservationDate: $reservationDate,
            data: $jsonData,
            rooms: $rooms,
            userId: $userId,
            userName: $userName,
            dateError: self::dateError($dateValidator, $reservationDate),
        ))->toArray($this->redirectToIndex());
    }

    /**
     * グループ予約（1部屋 × 1日 × 複数利用者）を保存する。RegisterGroupReservationUseCase を使う。
     *
     * @return array{ok: true, message: string, data: array, redirect: string}
     * @throws \App\Domain\Exception\InvalidInputException 入力・日付が不正な場合
     * @throws \App\Domain\Exception\UnauthorizedException 権限のない部屋を指定した場合
     * @throws \App\Domain\Exception\ConflictException 競合・昼食と弁当の同時予約になる場合
     * @throws \App\Domain\Exception\PersistenceException 保存に失敗した場合
     */
    public function processGroupReservation(
        string $reservationDate,
        array|string $jsonData,
        array $rooms,
        string $creatorName,
        callable $dateValidator,
        int $loginUserId = 0,
        bool $isAdmin = false,
        int $loginUserLevel = 0,
        bool $isBlockLeader = false
    ): array {
        return $this->useCases()->registerGroupReservation()->execute(new RegisterGroupReservationInput(
            reservationDate: $reservationDate,
            data: $jsonData,
            rooms: $rooms,
            creatorName: $creatorName,
            dateError: self::dateError($dateValidator, $reservationDate),
            loginUserId: $loginUserId,
            isAdmin: $isAdmin,
            loginUserLevel: $loginUserLevel,
            isBlockLeader: $isBlockLeader,
        ))->toArray($this->redirectToIndex());
    }

    /**
     * 日付の検証の結果を、UseCase に渡す「弾かれた理由」にする（通ったなら null）。
     *
     * @param callable $dateValidator 予約日を受け取り、true または理由の文字列を返す
     */
    public static function dateError(callable $dateValidator, string $reservationDate): ?string
    {
        $result = $dateValidator($reservationDate);

        return $result === true ? null : (string)$result;
    }

    private ?ReservationUseCases $useCases = null;

    /** 予約の書き込みの UseCase（Repository を共有するので、所属の判定はリクエストの中で使い回される）。 */
    private function useCases(): ReservationUseCases
    {
        /** @var \App\Model\Table\TIndividualReservationInfoTable $reservations */
        $reservations = $this->reservationTable;

        return $this->useCases ??= new ReservationUseCases($reservations, $this->userTable);
    }

    /**
     * 1件の食事予約を登録・取消する（トグル）。
     *
     * 他人の予約を扱うには、操作者本人も部屋に有効所属していること（管理者も同じ。#821）。
     * $adminAnyRoom が true のとき（エクセル食数予約の一括保存）だけ、管理者・システム管理者は
     * 所属外の部屋も操作できる。対象者の所属・削除済み部屋・過去日・直前・二重予約の判定は変わらない。
     *
     * @param int    $roomId          部屋 ID
     * @param array  $payload         date, meal, value, userId（省略時はログインユーザー）
     * @param int    $loginUserId     操作者のユーザー ID
     * @param string $loginUserName   操作者名
     * @param bool   $invalidateCache 処理後にキャッシュを無効化するか
     * @param bool   $adminAnyRoom    管理者が所属外の部屋を操作できるか（エクセル食数予約のみ true）
     * @return array 処理結果（value, details, affected）
     * @throws DomainException 入力不正・権限不足・所属外・競合など
     * @throws PersistenceException 予期しない保存エラー
     */
    public function processToggle(
        int $roomId,
        array $payload,
        int $loginUserId,
        string $loginUserName,
        bool $invalidateCache = true,
        bool $adminAnyRoom = false
    ): array {
        return $this->useCases()->toggleMeal()
            ->execute(ToggleMealInput::fromPayload($roomId, $payload, $loginUserId, $loginUserName, $invalidateCache, $adminAnyRoom))
            ->toArray();
    }

    /**
     * 複数の予約トグルを1回のリクエストでまとめて処理する（エクセル食数予約の一括登録用）。
     *
     * 各 item を processToggle で順次処理し、成否を item 単位で記録する。
     * 1件の失敗（競合・権限など）が他の件に影響しないよう、item ごとに例外を捕捉する
     * （従来の「セルごとに個別リクエスト」と同じ“一部成功・一部失敗”の挙動を維持する）。
     * キャッシュ無効化は件数分ではなく、影響した日付ごとに最後へまとめて1回だけ実行する。
     * エクセル食数予約専用のため、管理者・システム管理者は所属外の部屋も操作できる（processToggle の $adminAnyRoom）。
     *
     * @param array<int, array{roomId?:int,i_id_room?:int,userId?:int,date?:string,meal?:int,value?:int}> $items
     * @param int    $loginUserId
     * @param string $loginUserName
     * @return array<int, array{ok:bool,value?:bool,details?:array,message?:string}> item と同じ順序の結果配列
     */
    public function processBulkToggle(array $items, int $loginUserId, string $loginUserName): array
    {
        return $this->useCases()->bulkToggleMeals()->execute($items, $loginUserId, $loginUserName);
    }

    /**
     * 予約済みの食事を別の部屋へ移す（旧部屋を取消 → 新部屋で登録を1トランザクションで）。ChangeMealRoomUseCase を使う。
     *
     * @param array{date:string, meal:int, userId?:int} $payload
     * @throws DomainException 権限不足・所属外・競合・入力不正など
     */
    public function processRoomChange(
        int $fromRoomId,
        int $toRoomId,
        array $payload,
        int $loginUserId,
        string $loginUserName
    ): void {
        $this->useCases()->changeMealRoom()->execute(new ChangeMealRoomInput(
            date: (string)($payload['date'] ?? ''),
            meal: (int)($payload['meal'] ?? 0),
            fromRoomId: $fromRoomId,
            toRoomId: $toRoomId,
            loginUserId: $loginUserId,
            loginUserName: $loginUserName,
            targetUserId: isset($payload['userId']) ? (int)$payload['userId'] : $loginUserId,
        ));
    }

    /**
     * 予期しない例外を、画面・監査ログに出してよい短い原因に変換する。
     * 生のメッセージ（SQL 文・テーブル名など）は含めない。
     * エラーコードは DB 由来なら「DB-<MySQL エラー番号>」、それ以外は「SYS-0000」。
     *
     * @param \Throwable $e
     * @return string 例: 「他の操作と同時に更新されたため。もう一度お試しください [エラーコード: DB-1213]」
     */
    public static function describeSystemError(\Throwable $e): string
    {
        return SystemError::describe($e);
    }

    private function redirectToIndex(): string
    {
        return $this->webroot . 'TReservationInfo/';
    }
}
