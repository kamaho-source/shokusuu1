<?php
declare(strict_types=1);

namespace App\Application\UseCase\RestoreUser;

use App\Application\Port\AuditLogInterface;
use App\Application\Port\TransactionManagerInterface;
use App\Domain\Entity\UserAccount;
use App\Domain\Exception\NotFoundException;
use App\Domain\Repository\UserAccountRepositoryInterface;

/**
 * 削除済みの利用者を復元する。誰が復元してよいか（認可）と、削除済みかどうかは呼び出し側で確かめる。
 *
 * 利用者の削除を取り消し、所属の行をすべて所属中に戻す（1つのトランザクション）。
 * ponytail: 部屋異動で退出した昔の部屋も所属中に戻る（従来どおり）。直すなら、削除時に退出させた行だけを戻す。
 */
final class RestoreUserUseCase
{
    public function __construct(
        private readonly UserAccountRepositoryInterface $users,
        private readonly TransactionManagerInterface $transaction,
        private readonly AuditLogInterface $auditLog,
    ) {}

    /** 対象の利用者（認可より先に 404 を返す・削除済みかを確かめるため）。居なければ null。 */
    public function target(int $userId): ?UserAccount
    {
        return $this->users->find($userId);
    }

    /**
     * @throws NotFoundException 対象の利用者が居ない場合
     * @throws \Exception 保存できなかった場合（取り消して、失敗を監査ログに残してから投げ直す）
     */
    public function execute(RestoreUserInput $input): RestoreUserOutput
    {
        $user = $this->users->find($input->userId);
        if ($user === null) {
            throw new NotFoundException('対象ユーザーが見つかりません。');
        }

        $this->transaction->begin();
        try {
            if (!$this->users->markRestored($user->id, $input->actor->name)) {
                throw new \RuntimeException('ユーザー情報の更新に失敗しました。');
            }
            if (!$this->users->rejoinAllRooms($user->id, $input->actor->name)) {
                throw new \RuntimeException('グループ情報の更新に失敗しました。');
            }
            $this->transaction->commit();
        } catch (\Exception $e) {
            $this->transaction->rollback();
            $this->auditLog->record(
                'user', 'user_restore', $input->actor, 'm_user_info', (string)$user->id,
                ['target_user_name' => $user->name, 'error' => $e->getMessage()], false
            );
            throw $e;
        }

        $this->auditLog->record(
            'user', 'user_restore', $input->actor, 'm_user_info', (string)$user->id,
            ['target_user_name' => $user->name], true
        );

        return new RestoreUserOutput($user->name);
    }
}
