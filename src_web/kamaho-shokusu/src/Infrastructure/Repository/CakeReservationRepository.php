<?php
declare(strict_types=1);

namespace App\Infrastructure\Repository;

use App\Domain\Entity\MealReservation;
use App\Domain\Exception\ConflictException;
use App\Domain\Exception\DomainException;
use App\Domain\Exception\DuplicateMealConflictException;
use App\Domain\Exception\InvalidInputException;
use App\Domain\Exception\ReservationValidationException;
use App\Domain\Repository\ReservationRepositoryInterface;
use App\Exception\ApprovedReservationException;
use App\Exception\DuplicateMealReservationException;
use App\Infrastructure\Persistence\SystemError;
use App\Model\Table\TIndividualReservationInfoTable;
use Cake\I18n\Date;
use Cake\I18n\DateTime;
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

    public function findByUsersAndDates(array $dates, array $userIds, array $mealTypes, ?int $roomId = null): array
    {
        if ($dates === [] || $userIds === [] || $mealTypes === []) {
            return [];
        }
        $where = [
            'd_reservation_date IN' => $dates,
            'i_id_user IN'          => $userIds,
            'i_reservation_type IN' => $mealTypes,
        ];
        if ($roomId !== null) {
            $where['i_id_room'] = $roomId;
        }
        $rows = $this->reservations->find()
            ->enableAutoFields(false)
            ->select(['i_id_user', 'd_reservation_date', 'i_reservation_type', 'i_id_room', 'eat_flag', 'i_change_flag', 'i_version'])
            ->where($where)
            ->all();

        $found = [];
        foreach ($rows as $row) {
            $found[] = new MealReservation(
                userId: (int)$row->i_id_user,
                date: self::dateString($row->d_reservation_date),
                roomId: (int)$row->i_id_room,
                mealType: (int)$row->i_reservation_type,
                eatFlag: $row->eat_flag === null ? null : (int)$row->eat_flag,
                changeFlag: $row->i_change_flag === null ? null : (int)$row->i_change_flag,
                version: $row->i_version === null ? null : (int)$row->i_version,
            );
        }

        return $found;
    }

    public function updateWithVersion(MealReservation $row, int $changeFlag, ?int $eatFlag, string $actor): bool
    {
        $fields = ['i_change_flag' => $changeFlag]
            + ($eatFlag === null ? [] : ['eat_flag' => $eatFlag])
            + ['c_update_user' => $actor, 'dt_update' => DateTime::now('Asia/Tokyo')];

        try {
            // テーブルは行の値（複合主キー・フラグ・版）だけを見る。読み取ったときの値をそのまま渡す
            return $this->reservations->updateRowWithVersion((object)[
                'i_id_user'          => $row->userId,
                'd_reservation_date' => $row->date,
                'i_id_room'          => $row->roomId,
                'i_reservation_type' => $row->mealType,
                'eat_flag'           => $row->eatFlag,
                'i_change_flag'      => $row->changeFlag,
                'i_version'          => $row->version,
            ], $fields);
        } catch (ApprovedReservationException | DuplicateMealReservationException $e) {
            throw new ConflictException($e->getMessage());
        }
    }

    public function addMany(array $rows, string $actor, bool $validate = true): void
    {
        $entities = [];
        foreach ($rows as $row) {
            $data = [
                'i_id_user'          => $row->userId,
                'd_reservation_date' => $row->date,
                'i_id_room'          => $row->roomId,
                'i_reservation_type' => $row->mealType,
                'eat_flag'           => $row->eatFlag,
                'i_change_flag'      => $row->changeFlag,
                'i_version'          => 1,
                'c_create_user'      => $actor,
                'dt_create'          => DateTime::now('Asia/Tokyo'),
            ];
            if ($validate) {
                $entities[] = $this->reservations->newEntity($data);
                continue;
            }
            // 検証・型変換をせず、値をそのまま入れる（プロパティへの代入と同じ）
            $entity = $this->reservations->newEmptyEntity();
            foreach ($data as $field => $value) {
                $entity->set($field, $value);
            }
            $entities[] = $entity;
        }

        try {
            foreach (array_chunk($entities, 500) as $chunk) {
                $this->reservations->saveManyOrFail($chunk);
            }
        } catch (PersistenceFailedException $e) {
            $errors = $e->getEntity()?->getErrors() ?? [];
            throw new ReservationValidationException(
                TIndividualReservationInfoTable::lunchBentoViolation($e),
                array_merge(...array_values(array_map('array_values', $errors))),
                (string)json_encode($errors, JSON_UNESCAPED_UNICODE),
            );
        } catch (ApprovedReservationException | DuplicateMealReservationException $e) {
            throw new ConflictException($e->getMessage());
        }
    }

    public function latestUpdateByDate(int $roomId, array $dates): array
    {
        if ($dates === []) {
            return [];
        }
        $query = $this->reservations->find();
        $rows  = $query
            ->enableAutoFields(false)
            ->select([
                'd_reservation_date',
                'max_dt' => $query->func()->max(
                    $query->expr('COALESCE(dt_update, dt_create)'),
                    // 型を渡さないと float 扱いになり '2026-10-24 15:04:05' が 2026 になる（年が同じだと競合を検出できない）
                    ['string']
                ),
            ])
            ->where([
                'i_id_room'             => $roomId,
                'd_reservation_date IN' => $dates,
            ])
            ->groupBy(['d_reservation_date'])
            ->all();

        $latest = [];
        foreach ($rows as $row) {
            $latest[self::dateString($row->d_reservation_date)] = $row->max_dt ? (string)$row->max_dt : null;
        }

        return $latest;
    }

    private static function dateString(Date|string|null $date): string
    {
        return $date instanceof Date ? $date->format('Y-m-d') : (string)$date;
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
