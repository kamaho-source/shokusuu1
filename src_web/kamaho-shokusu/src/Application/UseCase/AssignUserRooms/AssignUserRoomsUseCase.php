<?php
declare(strict_types=1);

namespace App\Application\UseCase\AssignUserRooms;

use App\Application\Port\TransactionManagerInterface;
use App\Domain\Repository\RoomMembershipRepositoryInterface;
use App\Domain\Repository\UserAccountRepositoryInterface;

/**
 * 利用者の所属部屋を、部屋名で入れ替える。誰が変えてよいか（認可）は呼び出し側で確かめる。
 *
 * 今の所属をすべて退出済みにしてから、指定した部屋（先頭の2つまで）に所属させる（1つのトランザクション）。
 * 見つからない部屋・登録できなかった部屋は理由を返し、残りは続ける（従来どおり）。
 */
final class AssignUserRoomsUseCase
{
    private const MAX_ROOMS = 2;

    public function __construct(
        private readonly UserAccountRepositoryInterface $users,
        private readonly RoomMembershipRepositoryInterface $rooms,
        private readonly TransactionManagerInterface $transaction,
    ) {}

    /** 削除されていない利用者か（居なければ「ユーザーが見つかりません」を返すため）。 */
    public function isActiveUser(int $userId): bool
    {
        $user = $this->users->find($userId);

        return $user !== null && !$user->isDeleted();
    }

    /**
     * @throws \Throwable DB の失敗（取り消してから投げ直す）
     */
    public function execute(AssignUserRoomsInput $input): AssignUserRoomsOutput
    {
        $created = 0;
        $errors  = [];

        $this->transaction->begin();
        try {
            $this->users->leaveAllRooms($input->userId, $input->actorName);
            foreach (array_slice($input->roomNames, 0, self::MAX_ROOMS) as $roomName) {
                $roomId = $this->rooms->roomIdByName((string)$roomName);
                if ($roomId === null) {
                    $errors[] = "部屋名 '{$roomName}' が見つかりません";
                    continue;
                }
                if ($this->users->joinRoom($input->userId, $roomId, $input->actorName)) {
                    $created++;
                } else {
                    $errors[] = "部屋 '{$roomName}' の登録に失敗";
                }
            }
            $this->transaction->commit();
        } catch (\Throwable $e) {
            $this->transaction->rollback();
            throw $e;
        }

        return new AssignUserRoomsOutput($created, $errors);
    }
}
