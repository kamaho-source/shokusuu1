<?php
declare(strict_types=1);

namespace App\Policy;

use Authorization\Policy\OrmResolver;
use Cake\Datasource\EntityInterface;
use Cake\Datasource\RepositoryInterface;

/**
 * 永続化層（App\Infrastructure\Persistence\{Entity,Table}）のエンティティ・テーブルから、ポリシー（App\Policy\{名前}Policy）を探す。
 *
 * 標準の OrmResolver は、クラス名に \Model\Entity\ / \Model\Table\ が入っている前提のため置き換える。
 */
class PersistenceOrmResolver extends OrmResolver
{
    private const ENTITY_NAMESPACE = 'App\\Infrastructure\\Persistence\\Entity\\';

    private const TABLE_NAMESPACE = 'App\\Infrastructure\\Persistence\\Table\\';

    protected function getEntityPolicy(EntityInterface $entity): mixed
    {
        $class = get_class($entity);
        if (!str_starts_with($class, self::ENTITY_NAMESPACE)) {
            return parent::getEntityPolicy($entity);
        }

        return $this->findPolicy($class, substr($class, strlen(self::ENTITY_NAMESPACE)), 'App');
    }

    protected function getRepositoryPolicy(RepositoryInterface $table): mixed
    {
        $class = get_class($table);
        if (!str_starts_with($class, self::TABLE_NAMESPACE)) {
            return parent::getRepositoryPolicy($table);
        }

        return $this->findPolicy($class, substr($class, strlen(self::TABLE_NAMESPACE)), 'App');
    }
}
