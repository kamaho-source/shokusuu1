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
}
