<?php
declare(strict_types=1);

namespace App\Test\TestCase\Application\Fake;

use App\Application\UseCase\BulkAddReservations\BulkAddReservationsUseCase;
use App\Application\UseCase\BulkChangeEditReservations\BulkChangeEditReservationsUseCase;
use App\Application\UseCase\BulkReservation\BulkReservationSupport;
use App\Application\UseCase\RegisterGroupReservation\RegisterGroupReservationUseCase;
use App\Application\UseCase\RegisterIndividualReservation\RegisterIndividualReservationUseCase;
use App\Application\UseCase\ToggleMeal\ToggleMealUseCase;
use Psr\Log\NullLogger;

/**
 * 予約の UseCase のテストで使う、Fake をそろえた組み立て。
 *
 * 既定: 利用者 10（職員）が部屋 1・7・8 に所属。
 */
final class ReservationFixture
{
    public FakeReservationRepository $reservations;

    public FakeRoomMembershipRepository $rooms;

    public FakeUserRepository $users;

    public SpyReservationCache $cache;

    public function __construct()
    {
        $this->reservations = new FakeReservationRepository();
        $this->rooms        = new FakeRoomMembershipRepository(['10:1', '10:7', '10:8']);
        $this->users        = new FakeUserRepository([10 => FakeUserRepository::staff()]);
        $this->cache        = new SpyReservationCache();
    }

    public function toggleMeal(): ToggleMealUseCase
    {
        return new ToggleMealUseCase($this->reservations, $this->rooms, $this->users, $this->cache);
    }

    public function registerIndividual(): RegisterIndividualReservationUseCase
    {
        return new RegisterIndividualReservationUseCase($this->reservations, $this->rooms, $this->users, $this->reservations, $this->cache, new NullLogger());
    }

    public function registerGroup(): RegisterGroupReservationUseCase
    {
        return new RegisterGroupReservationUseCase($this->reservations, $this->rooms, $this->users, $this->reservations, $this->cache, new NullLogger());
    }

    public function bulkAdd(): BulkAddReservationsUseCase
    {
        return new BulkAddReservationsUseCase(
            $this->reservations,
            $this->rooms,
            $this->users,
            $this->reservations,
            $this->cache,
            new FakeReservationDatePolicy(),
            new BulkReservationSupport($this->reservations, $this->rooms, $this->cache),
            new NullLogger(),
            '過去日は選べません。',
        );
    }

    public function bulkChangeEdit(): BulkChangeEditReservationsUseCase
    {
        return new BulkChangeEditReservationsUseCase(
            $this->reservations,
            $this->rooms,
            $this->users,
            $this->reservations,
            new FakeReservationDatePolicy(),
            new BulkReservationSupport($this->reservations, $this->rooms, $this->cache),
            new NullLogger(),
        );
    }
}
