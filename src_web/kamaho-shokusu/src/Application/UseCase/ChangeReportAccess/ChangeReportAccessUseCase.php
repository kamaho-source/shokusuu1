<?php
declare(strict_types=1);

namespace App\Application\UseCase\ChangeReportAccess;

use App\Application\Port\AuditLogInterface;
use App\Domain\Exception\NotFoundException;
use App\Domain\Repository\UserAccountRepositoryInterface;

/**
 * 利用者のレポート閲覧権（i_report_access）を変える。誰が変えてよいか（認可）は呼び出し側で確かめる。
 */
final class ChangeReportAccessUseCase
{
    public function __construct(
        private readonly UserAccountRepositoryInterface $users,
        private readonly AuditLogInterface $auditLog,
    ) {}

    /**
     * @throws NotFoundException 対象の利用者が居ない場合
     * @throws \Throwable DB の失敗（変換せずにそのまま投げる）
     */
    public function execute(ChangeReportAccessInput $input): ChangeReportAccessOutput
    {
        $user = $this->users->find($input->targetUserId);
        if ($user === null) {
            throw new NotFoundException('対象ユーザーが見つかりません。');
        }
        $value = $input->enabled ? 1 : 0;

        if (!$this->users->updateReportAccess($user->id, $value, $input->actor->name)) {
            return new ChangeReportAccessOutput(false);
        }
        // 従来どおり、記録するのは保存できたときだけ
        $this->auditLog->record(
            'user', 'user_report_access_change', $input->actor, 'm_user_info', (string)$user->id,
            ['target_user_name' => $user->name, 'i_report_access' => $value], true
        );

        return new ChangeReportAccessOutput(true);
    }
}
