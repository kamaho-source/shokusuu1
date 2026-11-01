<?php
declare(strict_types=1);

namespace App\Test\TestCase\Application\Fake;

use App\Application\UseCase\ToggleMeal\ToggleMealUseCase;

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
}
