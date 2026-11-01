<?php
declare(strict_types=1);

namespace App\Infrastructure\Repository;

use App\Domain\Exception\ConflictException;
use App\Domain\Exception\DomainException;
use App\Domain\Exception\DuplicateMealConflictException;
use App\Domain\Exception\InvalidInputException;
use App\Domain\Repository\ReservationRepositoryInterface;
use App\Exception\ApprovedReservationException;
use App\Exception\DuplicateMealReservationException;
use App\Infrastructure\Persistence\SystemError;
use App\Model\Table\TIndividualReservationInfoTable;
use Cake\ORM\Exception\PersistenceFailedException;

/**
 * ReservationRepositoryInterface の CakePHP 実装（t_individual_reservation_info）。
 *
 * テーブル側の例外（二重予約・承認済み・検証ルール違反・DB 障害）を Domain の例外に変換する。
 */
final class CakeReservationRepository implements ReservationRepositoryInterface
{
    public function __construct(private readonly TIndividualReservationInfoTable $reservations)
    {
    }

    public function exists(int $userId, int $roomId, string $date, int $mealType): bool
    {
        try {
            return $this->reservations->exists([
                'i_id_user'          => $userId,
                'i_id_room'          => $roomId,
                'd_reservation_date' => $date,
                'i_reservation_type' => $mealType,
            ]);
        } catch (\Throwable $e) {
            throw self::readFailed($e);
        }
    }

    public function effectiveRoomIds(int $userId, string $date, int $mealType): array
    {
        try {
            return $this->reservations->findEffectiveRoomIds($userId, $date, $mealType);
        } catch (\Throwable $e) {
            throw self::readFailed($e);
        }
    }

    public function eatFlag(int $userId, int $roomId, string $date, int $mealType): ?int
    {
        try {
            $row = $this->reservations->find()
                ->select(['eat_flag'])
                ->where([
                    'i_id_user'          => $userId,
                    'i_id_room'          => $roomId,
                    'd_reservation_date' => $date,
                    'i_reservation_type' => $mealType,
                ])
                ->first();
        } catch (\Throwable $e) {
            throw self::readFailed($e);
        }

        return $row ? (int)$row->eat_flag : null;
    }

    public function toggle(
        int $userId,
        int $roomId,
        string $date,
        int $mealType,
        bool $on,
        string $actor,
        int $eatFlag,
        int $changeFlag
    ): array {
        try {
            $result = $this->reservations->toggleMeal(
                userId: $userId,
                roomId: $roomId,
                date: $date,
                meal: $mealType,
                on: $on,
                actor: $actor,
                eatFlag: $eatFlag,
                changeFlag: $changeFlag,
            );

            return ['value' => (bool)($result['value'] ?? false), 'details' => $result['details'] ?? []];
        } catch (DuplicateMealReservationException $e) {
            throw new DuplicateMealConflictException($e->getMessage());
        } catch (ApprovedReservationException $e) {
            throw new ConflictException($e->getMessage());
        } catch (PersistenceFailedException $e) {
            throw self::fromValidationFailure($e);
        } catch (\InvalidArgumentException $e) {
            throw new InvalidInputException($e->getMessage());
        } catch (DomainException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw SystemError::reservationUpdateFailed($e, 'toggle処理');
        }
    }

    public function transactional(callable $work): array
    {
        try {
            return $this->reservations->getConnection()->transactional($work);
        } catch (DomainException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw SystemError::reservationUpdateFailed($e, '予約のトランザクション');
        }
    }

    /**
     * 保存時の検証ルール違反を、理由の分かる例外にする。
     */
    private static function fromValidationFailure(PersistenceFailedException $e): DomainException
    {
        $errors = $e->getEntity()?->getErrors() ?? [];
        $flat   = json_encode($errors, JSON_UNESCAPED_UNICODE);

        $isMealConflict       = is_string($flat) && preg_match('/(昼|弁|bento|lunch|unique.*bento|unique.*lunch)/ui', $flat);
        $isOptimisticConflict = is_string($flat) && preg_match('/(conflict|optimistic)/ui', $flat);

        return match (true) {
            (bool)$isMealConflict       => new ConflictException('昼食と弁当は同時に予約できません。'),
            (bool)$isOptimisticConflict => new ConflictException('他の操作と競合しました。画面を再読み込みして再実行してください。'),
            default                     => new InvalidInputException('Validation failed.'),
        };
    }

    private static function readFailed(\Throwable $e): DomainException
    {
        return $e instanceof DomainException ? $e : SystemError::reservationUpdateFailed($e, '予約の読み取り');
    }
}
