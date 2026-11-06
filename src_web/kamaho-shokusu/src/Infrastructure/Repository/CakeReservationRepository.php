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
use App\Infrastructure\Persistence\Table\TIndividualReservationInfoTable;
use Cake\I18n\Date;
use Cake\I18n\DateTime;
use Cake\Database\Exception\QueryException;
use Cake\Datasource\EntityInterface;
use Cake\ORM\Exception\PersistenceFailedException;
use Cake\ORM\TableRegistry;

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
            ->select(['i_id_user', 'd_reservation_date', 'i_reservation_type', 'i_id_room', 'eat_flag', 'i_change_flag', 'i_version', 'i_approval_status'])
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
                approvalStatus: $row->i_approval_status === null ? null : (int)$row->i_approval_status,
            );
        }

        return $found;
    }

    public function updateWithVersion(MealReservation $row, int $changeFlag, ?int $eatFlag, string $actor, ?int $roomId = null): bool
    {
        $fields = ['i_change_flag' => $changeFlag]
            + ($roomId === null ? [] : ['i_id_room' => $roomId])
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
        } catch (QueryException $e) {
            // 同時に同じ予約が送られ、先に登録された行と主キーが重なった。SQL は利用者に返さない
            if (self::isDuplicateKey($e)) {
                throw new ConflictException('同じ予約がすでに登録されています。画面を再読み込みしてください。');
            }
            throw $e;
        }
    }

    public function deleteUnapprovedAfter(int $userId, string $date): void
    {
        $this->reservations->deleteAll([
            'i_id_user'            => $userId,
            'i_approval_status'    => 0, // 未承認のみ
            'd_reservation_date >' => $date, // 当日分は実績として残す
        ]);
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

    public function findInDateRange(string $from, string $to, ?array $roomIds = null, ?array $userIds = null): array
    {
        $where = ['d_reservation_date >=' => $from, 'd_reservation_date <=' => $to];
        if ($roomIds !== null) {
            $where['i_id_room IN'] = $roomIds;
        }
        if ($userIds !== null) {
            $where['i_id_user IN'] = $userIds;
        }
        $rows = $this->reservations->find()
            ->select(['i_id_user', 'i_id_room', 'i_reservation_type', 'd_reservation_date', 'eat_flag', 'i_change_flag'])
            ->where($where)
            ->all();

        $found = [];
        foreach ($rows as $row) {
            $found[] = self::toReservation($row);
        }

        return $found;
    }

    public function findFromDate(int $userId, int $roomId, string $fromDate): array
    {
        $rows = $this->reservations->find()
            ->where(['i_id_user' => $userId, 'i_id_room' => $roomId, 'd_reservation_date >=' => $fromDate])
            ->all();

        $found = [];
        foreach ($rows as $row) {
            $found[] = self::toReservation($row);
        }

        return $found;
    }

    public function findOne(int $userId, int $roomId, string $date, int $mealType): ?MealReservation
    {
        $row = $this->row($userId, $roomId, $date, $mealType);

        return $row === null ? null : self::toReservation($row);
    }

    public function moveForTransfer(MealReservation $source, int $toRoomId, bool $overwriteDestination, bool $createDestination, string $at): void
    {
        $old = $this->row($source->userId, $source->roomId, $source->date, $source->mealType);
        if ($old === null) {
            return;
        }
        $copied = [
            // 従来どおり、当初予定は数値にする（NULL は 0）
            'eat_flag'      => (int)$old->eat_flag,
            // NULL（直前の変更なし）を 0 にすると、当初予定のある予約が取消扱いになるため、そのまま写す
            'i_change_flag' => $old->i_change_flag === null ? null : (int)$old->i_change_flag,
        ];
        $destinationKey = ['i_id_user' => $source->userId, 'i_id_room' => $toRoomId, 'd_reservation_date' => $source->date, 'i_reservation_type' => $source->mealType];
        if ($overwriteDestination) {
            $destination = $this->row($source->userId, $toRoomId, $source->date, $source->mealType);
            // 承認状態を引き継ぐ。版は上げて、古い画面からの更新を競合として止める
            $this->reservations->updateAll($copied + [
                'i_approval_status' => (int)($old->i_approval_status ?? 0),
                'i_version'         => max((int)$destination?->i_version, (int)$old->i_version) + 1,
                'c_update_user'     => 'batch',
                'dt_update'         => $at,
            ], $destinationKey);
        }

        $this->reservations->deleteAll(['i_id_user' => $source->userId, 'i_id_room' => $source->roomId, 'd_reservation_date' => $source->date, 'i_reservation_type' => $source->mealType]);

        if ($createDestination) {
            // 版（i_version）は受け取らない項目のため、従来どおり 1 から始まる
            $new = $this->reservations->newEntity($destinationKey + $copied + [
                'c_create_user' => (string)$old->c_create_user,
                'dt_create'     => $old->dt_create,
                'c_update_user' => 'batch',
                'dt_update'     => $at,
            ]);
            // 異動は予約内容の変更ではないため承認状態を引き継ぐ（mass assign 不可のため個別に設定する）
            $new->set('i_approval_status', (int)($old->i_approval_status ?? 0));
            // 既存の行を部屋ごと移すだけで二重予約は増えないため、重複チェックは行わない
            try {
                $this->reservations->saveOrFail($new, ['checkDuplicateRoom' => false]);
            } catch (PersistenceFailedException $e) {
                // 昼食と弁当の重なりは、管理者が読める理由にする（部屋異動の「適用失敗」の理由として残る。#945）
                $reason = TIndividualReservationInfoTable::lunchBentoViolation($e);
                if ($reason === null) {
                    throw $e;
                }
                throw new ConflictException($reason);
            }
        }
    }

    public function adjustRoomCount(string $date, int $roomId, int $mealType, int $delta, string $at): void
    {
        if ($delta === 0) {
            return;
        }
        $counts = TableRegistry::getTableLocator()->get('TReservationInfo');
        $key = ['d_reservation_date' => $date, 'i_id_room' => $roomId, 'c_reservation_type' => $mealType];
        $row = $counts->find()->where($key)->first();
        if ($row !== null) {
            $counts->updateAll(['i_taberu_ninzuu' => max(0, (int)$row->i_taberu_ninzuu + $delta), 'dt_update' => $at, 'c_update_user' => 'batch'], $key);
            return;
        }
        if ($delta > 0) {
            $counts->saveOrFail($counts->newEntity($key + [
                'i_taberu_ninzuu'  => 1,
                'i_tabenai_ninzuu' => 0,
                'dt_create'        => $at,
                'c_create_user'    => 'batch',
            ]));
        }
    }

    private function row(int $userId, int $roomId, string $date, int $mealType): ?EntityInterface
    {
        /** @var \Cake\Datasource\EntityInterface|null */
        return $this->reservations->find()
            ->where(['i_id_user' => $userId, 'i_id_room' => $roomId, 'd_reservation_date' => $date, 'i_reservation_type' => $mealType])
            ->first();
    }

    private static function toReservation(EntityInterface $row): MealReservation
    {
        return new MealReservation(
            userId: (int)$row->get('i_id_user'),
            date: self::dateString($row->get('d_reservation_date')),
            roomId: (int)$row->get('i_id_room'),
            mealType: (int)$row->get('i_reservation_type'),
            eatFlag: $row->get('eat_flag') === null ? null : (int)$row->get('eat_flag'),
            changeFlag: $row->get('i_change_flag') === null ? null : (int)$row->get('i_change_flag'),
            version: $row->get('i_version') === null ? null : (int)$row->get('i_version'),
            approvalStatus: $row->get('i_approval_status') === null ? null : (int)$row->get('i_approval_status'),
        );
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

    /**
     * 一意キーの重複か（MySQL 1062・SQLite の UNIQUE 違反）。NOT NULL などの他の制約違反は含めない。
     */
    private static function isDuplicateKey(QueryException $e): bool
    {
        $previous = $e->getPrevious();
        $info     = $previous instanceof \PDOException ? ($previous->errorInfo ?? []) : [];

        return ($info[0] ?? '') === '23000'
            && (($info[1] ?? null) === 1062 || str_contains((string)($info[2] ?? ''), 'UNIQUE'));
    }

    private static function readFailed(\Throwable $e): DomainException
    {
        return $e instanceof DomainException ? $e : SystemError::reservationUpdateFailed($e, '予約の読み取り');
    }
}
