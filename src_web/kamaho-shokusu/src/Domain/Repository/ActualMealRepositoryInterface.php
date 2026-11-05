<?php
declare(strict_types=1);

namespace App\Domain\Repository;

use App\Domain\Exception\ConflictException;
use App\Domain\ValueObject\ApprovalKey;

/**
 * 実食の保存先（t_individual_reservation_info の実食・承認申請）。予約は ApprovalKey（利用者 × 日付 × 部屋 × 食事区分）で特定する。
 */
interface ActualMealRepositoryInterface
{
    /** 予約があるか。 */
    public function exists(ApprovalKey $key): bool;

    /** 予約の承認状態（NULL は未承認）。予約が無ければ null。 */
    public function findApprovalStatus(ApprovalKey $key): ?int;

    /**
     * 予約を新しく作る（eat_flag・i_change_flag とも $flag、版数 1、未承認）。
     *
     * @return bool 保存できたか
     * @throws ConflictException 保存中に例外が出たとき（同時に作られて一意制約に反したなど）
     */
    public function create(ApprovalKey $key, int $flag, string $actorName, string $now): bool;

    /**
     * 版数が $expectedVersion のときだけ eat_flag・i_change_flag を $flag にし、版数を 1 つ上げる。
     *
     * @return int 更新した件数
     */
    public function updateFlag(ApprovalKey $key, int $flag, int $expectedVersion, string $actorName, string $now): int;

    /**
     * 今の承認状態が $allowedFrom のどれかのときだけ、承認状態を $newStatus にする。
     *
     * @param list<int> $allowedFrom
     * @return int 更新した件数
     */
    public function changeApprovalStatus(ApprovalKey $key, int $newStatus, array $allowedFrom, string $actorName, string $now): int;
}
