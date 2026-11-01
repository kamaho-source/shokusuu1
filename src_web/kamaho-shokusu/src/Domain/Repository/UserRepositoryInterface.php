<?php
declare(strict_types=1);

namespace App\Domain\Repository;

use App\Domain\Exception\PersistenceException;
use App\Domain\ValueObject\UserRoleInfo;

/**
 * 利用者の読み取り。
 */
interface UserRepositoryInterface
{
    /**
     * 利用者のロール情報。利用者が存在しなければ null。
     *
     * @throws PersistenceException 読み取りに失敗したとき
     */
    public function findRoleInfo(int $userId): ?UserRoleInfo;
}
