<?php
declare(strict_types=1);

namespace App\Infrastructure;

use App\Application\UseCase\BulkToggleMeals\BulkToggleMealsUseCase;
use App\Application\UseCase\ChangeMealRoom\ChangeMealRoomUseCase;
use App\Application\UseCase\DirectRegisterMeals\DirectRegisterMealsUseCase;
use App\Application\UseCase\RegisterGroupReservation\RegisterGroupReservationUseCase;
use App\Application\UseCase\RegisterIndividualReservation\RegisterIndividualReservationUseCase;
use App\Application\UseCase\ToggleMeal\ToggleMealUseCase;
use App\Infrastructure\Cache\ReservationCacheAdapter;
use App\Infrastructure\Log\CakeLogger;
use App\Infrastructure\Persistence\CakeTransactionManager;
use App\Infrastructure\Repository\CakeReservationRepository;
use App\Infrastructure\Repository\CakeRoomMembershipRepository;
use App\Infrastructure\Repository\CakeUserRepository;
use App\Model\Table\TIndividualReservationInfoTable;
use Cake\Core\Configure;
use Cake\ORM\Table;

/**
 * 予約の書き込みの UseCase を、CakePHP の Table から組み立てる（Controller から使う組み立て口）。
 *
 * 同じインスタンスから作った UseCase は Repository を共有する（所属の判定結果を1リクエストの中で使い回す）。
 */
final class ReservationUseCases
{
    private readonly CakeReservationRepository $reservations;

    private readonly CakeRoomMembershipRepository $rooms;

    private readonly ReservationCacheAdapter $cache;

    private readonly CakeUserRepository $userRepository;

    private readonly CakeTransactionManager $transaction;

    private readonly CakeLogger $logger;

    private ?ToggleMealUseCase $toggleMeal = null;

    public function __construct(TIndividualReservationInfoTable $reservations, Table $users)
    {
        $this->reservations   = new CakeReservationRepository($reservations);
        $this->rooms          = new CakeRoomMembershipRepository();
        $this->cache          = new ReservationCacheAdapter();
        $this->userRepository = new CakeUserRepository($users);
        $this->transaction    = new CakeTransactionManager($reservations->getConnection());
        $this->logger         = new CakeLogger();
    }

    public function toggleMeal(): ToggleMealUseCase
    {
        return $this->toggleMeal ??= new ToggleMealUseCase(
            $this->reservations,
            $this->rooms,
            $this->userRepository,
            $this->cache,
        );
    }

    public function bulkToggleMeals(): BulkToggleMealsUseCase
    {
        return new BulkToggleMealsUseCase($this->toggleMeal(), $this->cache);
    }

    public function changeMealRoom(): ChangeMealRoomUseCase
    {
        return new ChangeMealRoomUseCase($this->toggleMeal(), $this->reservations, $this->rooms, $this->cache);
    }

    public function directRegisterMeals(): DirectRegisterMealsUseCase
    {
        return new DirectRegisterMealsUseCase($this->toggleMeal());
    }

    public function registerIndividualReservation(): RegisterIndividualReservationUseCase
    {
        return new RegisterIndividualReservationUseCase(
            $this->reservations,
            $this->rooms,
            $this->userRepository,
            $this->transaction,
            $this->cache,
            $this->logger,
            (bool)Configure::read('debug'),
        );
    }

    public function registerGroupReservation(): RegisterGroupReservationUseCase
    {
        return new RegisterGroupReservationUseCase(
            $this->reservations,
            $this->rooms,
            $this->userRepository,
            $this->transaction,
            $this->cache,
            $this->logger,
            (bool)Configure::read('debug'),
        );
    }

    /** 部屋・所属の Repository（Service から所属の判定に使う）。 */
    public function rooms(): CakeRoomMembershipRepository
    {
        return $this->rooms;
    }
}
