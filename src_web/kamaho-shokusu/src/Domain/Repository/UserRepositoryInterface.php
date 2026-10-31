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

    /**
     * 複数の利用者のロール情報。見つからない利用者は含まない。
     *
     * @param list<int> $userIds
     * @return array<int, UserRoleInfo> 利用者ID => ロール情報
     * @throws \RuntimeException 読み取りに失敗したとき（変換せずにそのまま投げる）
     */
    public function findRoleInfos(array $userIds): array;

    /**
     * 利用者名。名前の無い・見つからない利用者は含まない。
     *
     * @param list<int> $userIds
     * @return array<int, string> 利用者ID => 利用者名
     * @throws \RuntimeException 読み取りに失敗したとき（変換せずにそのまま投げる）
     */
    public function findNames(array $userIds): array;
}
