<?php
declare(strict_types=1);

namespace App\Infrastructure;

use App\Application\Port\ReservationDatePolicyInterface;
use App\Application\UseCase\BulkAddReservations\BulkAddReservationsUseCase;
use App\Application\UseCase\BulkChangeEditReservations\BulkChangeEditReservationsUseCase;
use App\Application\UseCase\BulkReservation\BulkReservationSupport;
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
use App\Infrastructure\Persistence\Table\TIndividualReservationInfoTable;
use App\Service\ReservationDatePolicy;
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

    private readonly ReservationDatePolicyInterface $datePolicy;

    private ?ToggleMealUseCase $toggleMeal = null;

    /**
     * @param ReservationDatePolicyInterface|null $datePolicy 予約日の検証（省略時は今日を基準にした ReservationDatePolicy）
     */
    public function __construct(TIndividualReservationInfoTable $reservations, Table $users, ?ReservationDatePolicyInterface $datePolicy = null)
    {
        $this->datePolicy     = $datePolicy ?? new ReservationDatePolicy();
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

    public function bulkAddReservations(): BulkAddReservationsUseCase
    {
        return new BulkAddReservationsUseCase(
            $this->reservations,
            $this->rooms,
            $this->userRepository,
            $this->transaction,
            $this->cache,
            $this->datePolicy,
            $this->bulkSupport(),
            $this->logger,
            (string)Configure::read(
                'App.messages.pastDateUnavailable',
                '過去日の内容はこの画面では表示できません。修正が必要な場合は管理者にお問い合わせください。'
            ),
        );
    }

    public function bulkChangeEditReservations(): BulkChangeEditReservationsUseCase
    {
        return new BulkChangeEditReservationsUseCase(
            $this->reservations,
            $this->rooms,
            $this->userRepository,
            $this->transaction,
            $this->datePolicy,
            $this->bulkSupport(),
            $this->logger,
        );
    }

    private function bulkSupport(): BulkReservationSupport
    {
        return new BulkReservationSupport($this->reservations, $this->rooms, $this->cache);
    }

    /** 部屋・所属の Repository（Service から所属の判定に使う）。 */
    public function rooms(): CakeRoomMembershipRepository
    {
        return $this->rooms;
    }
}
