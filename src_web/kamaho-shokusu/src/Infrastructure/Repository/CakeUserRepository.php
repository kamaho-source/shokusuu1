<?php
declare(strict_types=1);

namespace App\Infrastructure\Repository;

use App\Domain\Exception\DomainException;
use App\Domain\Repository\UserRepositoryInterface;
use App\Domain\ValueObject\UserRoleInfo;
use App\Infrastructure\Persistence\SystemError;
use Cake\ORM\Table;

/**
 * UserRepositoryInterface の CakePHP 実装（m_user_info）。
 */
final class CakeUserRepository implements UserRepositoryInterface
{
    public function __construct(private readonly Table $users)
    {
    }

    public function findRoleInfo(int $userId): ?UserRoleInfo
    {
        try {
            $row = $this->users->find()
                ->select(['i_admin', 'i_user_level'])
                ->where(['i_id_user' => $userId])
                ->first();
        } catch (DomainException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw SystemError::reservationUpdateFailed($e, '利用者の読み取り');
        }
        if ($row === null) {
            return null;
        }

        return new UserRoleInfo(
            (int)$row->i_admin,
            $row->i_user_level === null ? null : (int)$row->i_user_level,
        );
    }

    public function findRoleInfos(array $userIds): array
    {
        if ($userIds === []) {
            return [];
        }
        $rows = $this->users->find()
            ->enableAutoFields(false)
            ->select(['i_id_user', 'i_admin', 'i_user_level'])
            ->where(['i_id_user IN' => $userIds])
            ->all();

        $infos = [];
        foreach ($rows as $row) {
            $infos[(int)$row->i_id_user] = new UserRoleInfo(
                (int)$row->i_admin,
                $row->i_user_level === null ? null : (int)$row->i_user_level,
            );
        }

        return $infos;
    }

    public function findNames(array $userIds): array
    {
        if ($userIds === []) {
            return [];
        }
        $rows = $this->users->find()
            ->enableAutoFields(false)
            ->select(['i_id_user', 'c_user_name'])
            ->where(['i_id_user IN' => $userIds])
            ->all();

        $names = [];
        foreach ($rows as $row) {
            if ($row->c_user_name !== null) {
                $names[(int)$row->i_id_user] = (string)$row->c_user_name;
            }
        }

        return $names;
    }
}
