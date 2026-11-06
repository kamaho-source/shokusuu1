<?php
declare(strict_types=1);

namespace App\Application\UseCase\CreateRoomTransfer;

use App\Domain\Entity\RoomTransferSchedule;
use App\Domain\Repository\RoomTransferScheduleRepositoryInterface;
use App\Domain\Service\DatePeriodRule;
use Psr\Clock\ClockInterface;

/**
 * 部屋異動を予約する（予約中として登録する）。適用は ApplyRoomTransfersUseCase（毎日のバッチ）。
 *
 * 有効開始日は暦にある日付で、今日以降（画面の入力欄と同じ）。以前はサーバーで確かめず、
 * 過去の日付が予約中で保存され、次の夜のバッチですぐ適用されていた。
 */
final class CreateRoomTransferUseCase
{
    public function __construct(
        private readonly RoomTransferScheduleRepositoryInterface $schedules,
        private readonly ClockInterface $clock,
    ) {}

    /**
     * @throws \Throwable DB の保存の失敗（変換せずにそのまま投げる）
     */
    public function execute(CreateRoomTransferInput $input): CreateRoomTransferOutput
    {
        if (!$input->userId || !$input->roomToId || $input->effectiveDate === '') {
            return CreateRoomTransferOutput::rejected('ユーザー・異動先部屋・有効開始日は必須です。');
        }
        if (RoomTransferSchedule::isSameRoom($input->roomFromId, $input->roomToId)) {
            return CreateRoomTransferOutput::rejected(RoomTransferSchedule::SAME_ROOM_MESSAGE);
        }
        if (!DatePeriodRule::isDate($input->effectiveDate)) {
            return CreateRoomTransferOutput::rejected('有効開始日が正しくありません。');
        }
        if ($input->effectiveDate < $this->clock->now()->format('Y-m-d')) {
            return CreateRoomTransferOutput::rejected('有効開始日は今日以降の日付を指定してください。');
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
            return CreateRoomTransferOutput::created();
        }

        return CreateRoomTransferOutput::rejected('バリデーションエラー: ' . implode(' / ', array_map(fn(array $messages) => implode(', ', array_values($messages)), $errors)));
    }
}
