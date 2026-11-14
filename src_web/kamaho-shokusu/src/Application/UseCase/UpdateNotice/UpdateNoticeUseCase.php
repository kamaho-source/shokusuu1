<?php
declare(strict_types=1);

namespace App\Application\UseCase\UpdateNotice;

use App\Application\Port\AuditLogInterface;
use App\Domain\Entity\Notice;
use App\Domain\Exception\NotFoundException;
use App\Domain\Repository\NoticeRepositoryInterface;
use App\Domain\Service\NoticeRule;

/**
 * お知らせを更新する（管理者・システム管理者）。種別はシステム管理者だけが変えられ、それ以外は今の種別のまま。
 */
final class UpdateNoticeUseCase
{
    public function __construct(
        private readonly NoticeRepositoryInterface $notices,
        private readonly AuditLogInterface $auditLog,
    ) {}

    /**
     * @throws NotFoundException お知らせが無い
     */
    public function execute(UpdateNoticeInput $input): UpdateNoticeOutput
    {
        $current = $this->notices->find($input->id) ?? throw new NotFoundException('お知らせが見つかりません。');

        $error = NoticeRule::error($input->importance, $input->type, $input->startDate, $input->endDate);
        if ($error !== null) {
            return new UpdateNoticeOutput(false, $error, $current);
        }

        $notice = new Notice(
            $current->id,
            trim($input->title),
            $input->body,
            $input->startDate,
            $input->endDate,
            $input->importance,
            NoticeRule::resolveType($input->isSystemAdmin, $input->type, $current->type),
            $current->createdBy,
            $current->createdAt,
            NoticeRule::resolveModal($input->isSystemAdmin, $input->modal, $current->modal),
        );
        if (!$this->notices->update($notice, $input->actor->name)) {
            return new UpdateNoticeOutput(false, 'お知らせの更新に失敗しました。入力内容を確認してください。', $notice);
        }

        $this->auditLog->record('notice', 'notice_edit', $input->actor, 'm_notice', (string)$notice->id, ['title' => $notice->title], true);

        return new UpdateNoticeOutput(true, null, $notice);
    }
}
