<?php
declare(strict_types=1);

namespace App\Application\UseCase\CreateNotice;

use App\Application\Port\AuditLogInterface;
use App\Domain\Entity\Notice;
use App\Domain\Exception\InvalidInputException;
use App\Domain\Repository\NoticeRepositoryInterface;
use App\Domain\Service\NoticeRule;

/**
 * お知らせを登録する（管理者・システム管理者）。種別はシステム管理者だけが指定でき、それ以外は通常にする。
 */
final class CreateNoticeUseCase
{
    public function __construct(
        private readonly NoticeRepositoryInterface $notices,
        private readonly AuditLogInterface $auditLog,
    ) {}

    /**
     * @return CreateNoticeOutput 登録したお知らせ（ID）
     * @throws InvalidInputException 入力が不正・保存できない（タイトルが空など）
     */
    public function execute(CreateNoticeInput $input): CreateNoticeOutput
    {
        $error = NoticeRule::error($input->importance, $input->type, $input->startDate, $input->endDate);
        if ($error !== null) {
            throw new InvalidInputException($error, 400);
        }

        $notice = new Notice(
            null,
            trim($input->title),
            $input->body,
            $input->startDate,
            $input->endDate,
            $input->importance,
            NoticeRule::resolveType($input->isSystemAdmin, $input->type, null),
        );
        $id = $this->notices->add($notice, $input->actor->id, $input->actor->name);
        if ($id === null) {
            throw new InvalidInputException('お知らせの登録に失敗しました。入力内容を確認してください。', 400);
        }

        $this->auditLog->record('notice', 'notice_add', $input->actor, 'm_notice', (string)$id, ['title' => $notice->title], true);

        return new CreateNoticeOutput($id);
    }
}
