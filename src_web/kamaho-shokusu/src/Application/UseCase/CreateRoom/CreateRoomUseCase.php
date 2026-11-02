<?php
declare(strict_types=1);

namespace App\Application\UseCase\CreateRoom;

use App\Application\Port\AuditLogInterface;
use App\Domain\Repository\RoomRepositoryInterface;
use Psr\Clock\ClockInterface;

/**
 * 部屋を作る。表示順は今の最後の次にする。送られた値から受け取るのは部屋名だけ。
 */
final class CreateRoomUseCase
{
    public function __construct(
        private readonly RoomRepositoryInterface $rooms,
        private readonly AuditLogInterface $auditLog,
        private readonly ClockInterface $clock,
    ) {}

    /**
     * @throws \Throwable DB の失敗（変換せずにそのまま投げる）
     */
    public function execute(CreateRoomInput $input): CreateRoomOutput
    {
        $result = $this->rooms->create(
            $input->input,
            $this->rooms->nextDisplayNo(),
            $input->loggedIn ? $input->actor->name : null,
            $this->clock->now()->format('Y-m-d H:i:s'),
        );
        if (!$result->isSaved()) {
            return new CreateRoomOutput(false, $result->fieldErrors);
        }

        $this->auditLog->record(
            'master', 'room_create', $input->actor, 'm_room_info', (string)$result->roomId,
            ['room_name' => $this->rooms->find((int)$result->roomId)?->name], true
        );

        return new CreateRoomOutput(true);
    }
}
