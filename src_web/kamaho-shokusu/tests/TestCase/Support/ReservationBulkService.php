<?php
declare(strict_types=1);

namespace App\Test\TestCase\Support;

use App\Application\UseCase\BulkAddReservations\BulkAddReservationsInput;
use App\Application\UseCase\BulkChangeEditReservations\BulkChangeEditReservationsInput;
use App\Infrastructure\ReservationUseCases;
use Cake\ORM\Table;
use Psr\Clock\ClockInterface;

/**
 * 一括登録・直前一括編集の入口（処理本体は BulkAddReservationsUseCase・BulkChangeEditReservationsUseCase）。
 *
 * テスト用: 予約の UseCase を以前のサービスと同じ引数で呼ぶ（本番のコードからは使わない）。
 */
class ReservationBulkService
{
    /**
     * @param ClockInterface|null $clock 時計（省略時は Asia/Tokyo の現在時刻）
     */
    public function __construct(private readonly ?ClockInterface $clock = null)
    {
    }

    /**
     * 直前一括編集（部屋 × 日付 × 利用者 × 食事）を保存する。BulkChangeEditReservationsUseCase を使う。
     *
     * @return array{ok: bool, message?: string, updated?: int, created?: int, skipped?: list<string>, conflict_date?: string}
     * @throws \App\Domain\Exception\PersistenceException 部屋の読み取りに失敗した場合
     */
    public function processBulkChangeEdit(
        array $dayUsers,
        int $roomId,
        string $loginName,
        Table $reservationTable,
        Table $userTable,
        array $snapshots = [],
        int $loginUserId = 0,
        bool $isAdmin = false,
        int $loginUserLevel = 0,
        bool $isBlockLeader = false
    ): array {
        return $this->useCases($reservationTable, $userTable)->bulkChangeEditReservations()->execute(new BulkChangeEditReservationsInput(
            dayUsers: $dayUsers,
            roomId: $roomId,
            loginName: $loginName,
            snapshots: $snapshots,
            loginUserId: $loginUserId,
            isAdmin: $isAdmin,
            loginUserLevel: $loginUserLevel,
            isBlockLeader: $isBlockLeader,
        ));
    }

    /**
     * 一括登録（個人・部屋単位）を保存する。BulkAddReservationsUseCase を使う。
     *
     * @param Table $roomTable 互換のために受け取る（部屋は Repository から読む）
     * @return array{ok: bool, message: string, redirect_url?: string, conflict_date?: string}
     * @throws \RuntimeException 保存前の読み取りに失敗した場合
     */
    public function processBulkAdd(
        array $data,
        int $userId,
        string $userName,
        Table $reservationTable,
        Table $userTable,
        Table $roomTable,
        bool $isAdmin = false,
        int $loginUserLevel = 0,
        bool $isBlockLeader = false
    ): array {
        return $this->useCases($reservationTable, $userTable)->bulkAddReservations()->execute(new BulkAddReservationsInput(
            data: $data,
            userId: $userId,
            userName: $userName,
            isAdmin: $isAdmin,
            loginUserLevel: $loginUserLevel,
            isBlockLeader: $isBlockLeader,
        ));
    }

    private function useCases(Table $reservationTable, Table $userTable): ReservationUseCases
    {
        /** @var \App\Infrastructure\Persistence\Table\TIndividualReservationInfoTable $reservationTable */
        return new ReservationUseCases($reservationTable, $userTable, $this->clock ?? new \Cake\Chronos\ClockFactory('Asia/Tokyo'));
    }
}
