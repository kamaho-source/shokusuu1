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
 * 利用者の削除を取り消し、削除で外した所属だけを所属中に戻す（1つのトランザクション）。
 * 部屋異動で外れた昔の部屋は戻さない。どの部屋が削除で外したものかは DeletedMembershipRule で見分ける。
 * 見分けられない（印の列を足す前に削除され、時刻もずれている）人は、所属なしで復元する。
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

        // 削除済みにした時刻で見分けるため、利用者を更新する前に戻す部屋を決める
        $roomIds = $this->users->roomsLeftByDeletion($user->id);

        $this->transaction->begin();
        try {
            if (!$this->users->markRestored($user->id, $input->actor->name)) {
                throw new \RuntimeException('ユーザー情報の更新に失敗しました。');
            }
            if (!$this->users->rejoinRooms($user->id, $roomIds, $input->actor->name)) {
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
            ['target_user_name' => $user->name, 'restored_room_ids' => $roomIds], true
        );

        return new RestoreUserOutput($user->name, count($roomIds));
    }
}
