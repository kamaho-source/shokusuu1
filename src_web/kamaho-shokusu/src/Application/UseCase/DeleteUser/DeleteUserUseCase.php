<?php
declare(strict_types=1);

namespace App\Application\UseCase\DeleteUser;

use App\Application\Port\AuditLogInterface;
use App\Application\Port\TransactionManagerInterface;
use App\Domain\Entity\UserAccount;
use App\Domain\Exception\NotFoundException;
use App\Domain\Repository\ReservationRepositoryInterface;
use App\Domain\Repository\UserAccountRepositoryInterface;
use Cake\Chronos\ChronosDate;
use Psr\Clock\ClockInterface;

/**
 * 利用者を削除（論理削除）する。誰が削除してよいか（認可）は呼び出し側で確かめる。
 *
 * 1つのトランザクションで次を行い、どれかが失敗したら全部取り消す。
 * - 所属している部屋をすべて退出済みにし、「削除で外した」印を付ける（復元でこの部屋だけ戻す）。
 * - 利用者を削除済みにする（所属を外した時刻と同じ時刻にする）。
 * - 翌日以降の未承認の予約を消す（当日と過去日は控除の実績のため残す）。
 * 未適用の部屋異動の予約は残す（削除されている間は適用しない。復元すれば予約どおり適用される）。
 */
final class DeleteUserUseCase
{
    private const SAVE_FAILED = 'ユーザーの削除（論理削除）の保存に失敗しました';

    public function __construct(
        private readonly UserAccountRepositoryInterface $users,
        private readonly ReservationRepositoryInterface $reservations,
        private readonly TransactionManagerInterface $transaction,
        private readonly AuditLogInterface $auditLog,
        private readonly ClockInterface $clock,
    ) {}

    /** 対象の利用者（認可より先に 404 を返すため）。居なければ null。 */
    public function target(int $userId): ?UserAccount
    {
        return $this->users->find($userId);
    }

    /**
     * @throws NotFoundException 対象の利用者が居ない場合
     * @throws \Throwable DB の失敗（取り消して、失敗を監査ログに残してから投げ直す）
     */
    public function execute(DeleteUserInput $input): DeleteUserOutput
    {
        $user = $this->users->find($input->userId);
        if ($user === null) {
            throw new NotFoundException('対象ユーザーが見つかりません。');
        }
        $now   = $this->clock->now();
        $at    = $now->format('Y-m-d H:i:s');
        $today = (new ChronosDate($now))->format('Y-m-d');

        $this->transaction->begin();
        try {
            $deleted = $this->users->leaveAllRoomsForDeletion($user->id, $input->actor->name, $at)
                && $this->users->markDeleted($user->id, $input->actor->name, $at);
            if (!$deleted) {
                $this->transaction->rollback();
                $this->record($input, $user, false, self::SAVE_FAILED);

                return new DeleteUserOutput(false);
            }
            $this->reservations->deleteUnapprovedAfter($user->id, $today);
            $this->transaction->commit();
        } catch (\Throwable $e) {
            if ($this->transaction->inTransaction()) {
                $this->transaction->rollback();
            }
            $this->record($input, $user, false, self::SAVE_FAILED . '（' . $e->getMessage() . '）');
            throw $e;
        }

        $this->record($input, $user, true, null);

        return new DeleteUserOutput(true);
    }

    private function record(DeleteUserInput $input, UserAccount $user, bool $succeeded, ?string $reason): void
    {
        $this->auditLog->record(
            'user', 'user_delete', $input->actor, 'm_user_info', (string)$user->id,
            ['target_user_name' => $user->name], $succeeded, $reason
        );
    }
}
