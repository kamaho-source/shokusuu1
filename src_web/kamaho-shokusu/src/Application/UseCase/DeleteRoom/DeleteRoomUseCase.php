<?php
declare(strict_types=1);

namespace App\Application\UseCase\DeleteRoom;

use App\Application\Port\AuditLogInterface;
use App\Domain\Repository\RoomRepositoryInterface;
use Psr\Clock\ClockInterface;

/**
 * 部屋を削除（論理削除）する。所属・予約には触れない（従来どおり）。成功・失敗を監査ログに残す。
 */
final class DeleteRoomUseCase
{
    public function __construct(
        private readonly RoomRepositoryInterface $rooms,
        private readonly AuditLogInterface $auditLog,
        private readonly ClockInterface $clock,
    ) {}

    /**
     * @throws \Throwable DB の失敗（変換せずにそのまま投げる）
     */
    public function execute(DeleteRoomInput $input): DeleteRoomOutput
    {
        $room = $this->rooms->find($input->roomId);
        $deleted = $this->rooms->softDelete(
            $input->roomId,
            $input->loggedIn ? $input->actor->name : null,
            $this->clock->now()->format('Y-m-d H:i:s'),
        );
        $this->auditLog->record(
            'master', 'room_delete', $input->actor, 'm_room_info', (string)$input->roomId,
            ['room_name' => $room?->name], $deleted,
            $deleted ? null : '部屋マスタの削除に失敗しました（対象が存在しない、またはDBエラー）'
        );

        return new DeleteRoomOutput($deleted);
    }
}
