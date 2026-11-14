<?php
declare(strict_types=1);

namespace App\Infrastructure\Query;

use App\Application\Query\NoticeQueryInterface;
use App\Domain\Entity\Notice;
use App\Infrastructure\Repository\CakeNoticeRepository;
use Cake\ORM\TableRegistry;

/**
 * NoticeQueryInterface の CakePHP 実装（m_notice）。
 */
final class CakeNoticeQuery implements NoticeQueryInterface
{
    public function find(int $id): ?Notice
    {
        return (new CakeNoticeRepository())->find($id);
    }

    public function all(): array
    {
        return array_map(
            CakeNoticeRepository::toNotice(...),
            TableRegistry::getTableLocator()->get('MNotice')->find()->orderByDesc('dt_create')->all()->toList()
        );
    }

    public function active(): array
    {
        return array_map(
            CakeNoticeRepository::toNotice(...),
            TableRegistry::getTableLocator()->get('MNotice')->find('active')->all()->toList()
        );
    }

    public function activeModals(): array
    {
        return array_map(
            CakeNoticeRepository::toNotice(...),
            TableRegistry::getTableLocator()->get('MNotice')->find('active')->where(['i_modal' => 1])->all()->toList()
        );
    }
}
