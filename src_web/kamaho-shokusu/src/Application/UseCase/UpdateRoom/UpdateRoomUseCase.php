<?php
declare(strict_types=1);

namespace App\Application\UseCase\UpdateRoom;

use App\Application\Port\AuditLogInterface;
use App\Application\Port\TransactionManagerInterface;
use App\Domain\Exception\InvalidInputException;
use App\Domain\Exception\NotFoundException;
use App\Domain\Repository\RoomRepositoryInterface;
use App\Domain\Service\MemberDisplayOrder;
use Psr\Clock\ClockInterface;

/**
 * 部屋名と所属メンバーの並び順を、1つの［保存］でまとめて保存する（1つのトランザクション。どちらかが失敗したら両方取り消す）。
 *
 * - 部屋名は、変わったときだけ保存して監査ログに残す。
 * - 並び順は、所属メンバー全員を画面で並べた順に、表示順（i_disp_no）を振り直す。番号が変わった人がいたときだけ監査ログに残す。
 */
final class UpdateRoomUseCase
{
    public function __construct(
        private readonly RoomRepositoryInterface $rooms,
        private readonly TransactionManagerInterface $transaction,
        private readonly AuditLogInterface $auditLog,
        private readonly ClockInterface $clock,
    ) {}

    /**
     * @throws NotFoundException 部屋が無い場合
     * @throws \Throwable DB の失敗（取り消してから投げ直す）
     */
    public function execute(UpdateRoomInput $input): UpdateRoomOutput
    {
        if ($this->rooms->find($input->roomId) === null) {
            throw new NotFoundException('部屋が見つかりません。');
        }
        $at = $this->clock->now()->format('Y-m-d H:i:s');
        $renamed = false;
        $changed = 0;

        $this->transaction->begin();
        try {
            if ($input->canRename) {
                $result = $this->rooms->rename($input->roomId, $input->input, $input->actor->name, $at);
                if (!$result->isSaved()) {
                    $this->transaction->rollback();

                    return $result->fieldErrors === [] ? UpdateRoomOutput::saveFailed() : UpdateRoomOutput::invalidFields($result->fieldErrors);
                }
                $renamed = $result->changed;
            }
            if ($input->canReorder && $input->memberOrder !== null) {
                $changed = $this->reorder($input->roomId, $input->memberOrder, $input->actor->name, $at);
            }
            $this->transaction->commit();
        } catch (InvalidInputException $e) {
            $this->transaction->rollback();

            return UpdateRoomOutput::rejected($e->getMessage());
        } catch (\Throwable $e) {
            if ($this->transaction->inTransaction()) {
                $this->transaction->rollback();
            }
            throw $e;
        }

        $roomName = $this->rooms->find($input->roomId)?->name;
        if ($renamed) {
            $this->auditLog->record('master', 'room_update', $input->actor, 'm_room_info', (string)$input->roomId, ['room_name' => $roomName], true);
        }
        // 並びが変わらなかった保存は記録しない
        if ($changed > 0) {
            $this->auditLog->record('master', 'room_member_reorder', $input->actor, 'm_room_info', (string)$input->roomId, ['room_name' => $roomName, 'changed' => $changed], true);
        }

        return UpdateRoomOutput::updated($changed);
    }

    /**
     * @param list<int> $userIds
     * @return int 番号が変わった人数
     * @throws InvalidInputException 顔ぶれが今の所属メンバーと一致しないとき
     */
    private function reorder(int $roomId, array $userIds, string $updatedBy, string $at): int
    {
        // 顔ぶれ（重複・過不足）の確認は MemberDisplayOrder が行う
        $changes = MemberDisplayOrder::reassign($this->rooms->memberDisplayOrders($roomId), $userIds, $this->rooms->maxUserDisplayNo());
        foreach ($changes as $userId => $displayNo) {
            $this->rooms->setUserDisplayNo($userId, $displayNo, $updatedBy, $at);
        }

        return count($changes);
    }
}
