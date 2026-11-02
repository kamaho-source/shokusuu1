<?php
declare(strict_types=1);

namespace App\Application\UseCase\CreateRoomTransfer;

use App\Domain\Repository\RoomTransferScheduleRepositoryInterface;
use Psr\Clock\ClockInterface;

/**
 * 部屋異動を予約する（予約中として登録する）。適用は ApplyRoomTransfersUseCase（毎日のバッチ）。
 */
final class CreateRoomTransferUseCase
{
    public function __construct(
        private readonly RoomTransferScheduleRepositoryInterface $schedules,
        private readonly ClockInterface $clock,
    ) {}

    /**
     * @return string|null 登録できなかった理由（登録できたら null）
     * @throws \Throwable DB の保存の失敗（変換せずにそのまま投げる）
     */
    public function execute(CreateRoomTransferInput $input): ?string
    {
        if (!$input->userId || !$input->roomToId || $input->effectiveDate === '') {
            return 'ユーザー・異動先部屋・有効開始日は必須です。';
        }
        $errors = $this->schedules->create(
            [
                'i_id_user'        => $input->userId,
                'i_id_room_from'   => $input->roomFromId,
                'i_id_room_to'     => $input->roomToId,
                'd_effective_date' => $input->effectiveDate,
            ],
            $input->actorName,
            $this->clock->now()->format('Y-m-d H:i:s'),
        );
        if ($errors === []) {
            return null;
        }

        return 'バリデーションエラー: ' . implode(' / ', array_map(fn(array $messages) => implode(', ', array_values($messages)), $errors));
    }
}
