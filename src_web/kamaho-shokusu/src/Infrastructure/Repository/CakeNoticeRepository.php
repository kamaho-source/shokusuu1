<?php
declare(strict_types=1);

namespace App\Infrastructure\Repository;

use App\Domain\Entity\Notice;
use App\Domain\Repository\NoticeRepositoryInterface;
use Cake\Datasource\EntityInterface;
use Cake\Datasource\Exception\RecordNotFoundException;
use Cake\ORM\Table;
use Cake\ORM\TableRegistry;

/**
 * NoticeRepositoryInterface の CakePHP 実装（m_notice）。
 */
final class CakeNoticeRepository implements NoticeRepositoryInterface
{
    private static function table(): Table
    {
        return TableRegistry::getTableLocator()->get('MNotice');
    }

    /** 行をお知らせにする（CakeNoticeQuery と共通）。 */
    public static function toNotice(EntityInterface $row): Notice
    {
        return new Notice(
            (int)$row->get('i_id'),
            (string)$row->get('c_title'),
            $row->get('c_body') === null ? null : (string)$row->get('c_body'),
            $row->get('d_start')?->format('Y-m-d'),
            $row->get('d_end')?->format('Y-m-d'),
            (int)$row->get('i_importance'),
            (int)($row->get('i_type') ?? 0),
            $row->get('c_create_user') === null ? null : (string)$row->get('c_create_user'),
            $row->get('dt_create')?->format('Y-m-d H:i:s'),
        );
    }

    public function find(int $id): ?Notice
    {
        try {
            return self::toNotice(self::table()->get($id));
        } catch (RecordNotFoundException) {
            return null;
        }
    }

    /**
     * @return array<string, string|int|null>
     */
    private static function fields(Notice $notice): array
    {
        return [
            'c_title'      => $notice->title,
            'c_body'       => $notice->body !== null && $notice->body !== '' ? $notice->body : null,
            'd_start'      => $notice->startDate,
            'd_end'        => $notice->endDate,
            'i_importance' => $notice->importance,
            'i_type'       => $notice->type,
        ];
    }

    public function add(Notice $notice, int $actorId, string $actorName): ?int
    {
        $table = self::table();
        $entity = $table->patchEntity($table->newEmptyEntity(), self::fields($notice));
        $entity->set('i_id_user_created', $actorId);
        $entity->set('c_create_user', $actorName);
        $entity->set('c_update_user', $actorName);

        return $table->save($entity) ? (int)$entity->get('i_id') : null;
    }

    public function update(Notice $notice, string $actorName): bool
    {
        $table = self::table();
        $entity = $table->patchEntity($table->get((int)$notice->id), self::fields($notice));
        $entity->set('c_update_user', $actorName);

        return (bool)$table->save($entity);
    }

    public function delete(int $id): bool
    {
        $table = self::table();

        return $table->delete($table->get($id));
    }
}
