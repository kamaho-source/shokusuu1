<?php
declare(strict_types=1);

namespace App\Application\UseCase\DeleteUser;

use App\Application\Port\AuditLogInterface;
use App\Domain\Entity\UserAccount;
use App\Domain\Exception\NotFoundException;
use App\Domain\Repository\ReservationRepositoryInterface;
use App\Domain\Repository\UserAccountRepositoryInterface;
use Cake\Chronos\ChronosDate;
use Psr\Clock\ClockInterface;

/**
 * 利用者を削除（論理削除）する。誰が削除してよいか（認可）は呼び出し側で確かめる。
 *
 * - 所属している部屋はすべて退出済みにする。
 * - 削除済みにできたら、翌日以降の未承認の予約を消す（当日と過去日は控除の実績のため残す）。
 * 従来どおり、ひとまとめのトランザクションにはしない。
 */
final class DeleteUserUseCase
{
    public function __construct(
        private readonly UserAccountRepositoryInterface $users,
        private readonly ReservationRepositoryInterface $reservations,
        private readonly AuditLogInterface $auditLog,
        private readonly ClockInterface $clock,
    ) {}

    /** 対象の利用者（認可より先に 404 を返す・削除済みかを確かめるため）。居なければ null。 */
    public function target(int $userId): ?UserAccount
    {
        return $this->users->find($userId);
    }

    /**
     * @throws NotFoundException 対象の利用者が居ない場合
     * @throws \Throwable DB の失敗（変換せずにそのまま投げる）
     */
    public function execute(DeleteUserInput $input): DeleteUserOutput
    {
        $user = $this->users->find($input->userId);
        if ($user === null) {
            throw new NotFoundException('対象ユーザーが見つかりません。');
        }

        $this->users->leaveAllRooms($user->id, $input->actor->name);
        $deleted = $this->users->markDeleted($user->id, $input->actor->name);
        if ($deleted) {
            $today = new ChronosDate($this->clock->now());
            $this->reservations->deleteUnapprovedAfter($user->id, $today->format('Y-m-d'));
        }

        $this->auditLog->record(
            'user', 'user_delete', $input->actor, 'm_user_info', (string)$user->id,
            ['target_user_name' => $user->name], $deleted,
            $deleted ? null : 'ユーザーの削除（論理削除）の保存に失敗しました'
        );

        return new DeleteUserOutput($deleted);
    }
}
