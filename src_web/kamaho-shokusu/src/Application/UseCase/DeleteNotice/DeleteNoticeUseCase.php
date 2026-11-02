<?php
declare(strict_types=1);

namespace App\Application\UseCase\DeleteNotice;

use App\Application\Port\AuditActor;
use App\Application\Port\AuditLogInterface;
use App\Domain\Exception\NotFoundException;
use App\Domain\Repository\NoticeRepositoryInterface;

/**
 * お知らせを削除する（管理者・システム管理者）。
 */
final class DeleteNoticeUseCase
{
    public function __construct(
        private readonly NoticeRepositoryInterface $notices,
        private readonly AuditLogInterface $auditLog,
    ) {}

    /**
     * @return bool 消せたか
     * @throws NotFoundException お知らせが無い
     */
    public function execute(int $id, AuditActor $actor): bool
    {
        $notice = $this->notices->find($id) ?? throw new NotFoundException('お知らせが見つかりません。');
        if (!$this->notices->delete($id)) {
            return false;
        }

        $this->auditLog->record('notice', 'notice_delete', $actor, 'm_notice', (string)$id, ['title' => $notice->title], true);

        return true;
    }
}
