<?php
declare(strict_types=1);

namespace App\Application\UseCase\ChangeMealRoom;

use App\Application\Port\ReservationCacheInterface;
use App\Application\UseCase\ToggleMeal\ToggleMealInput;
use App\Application\UseCase\ToggleMeal\ToggleMealUseCase;
use App\Domain\Exception\ConflictException;
use App\Domain\Exception\DomainException;
use App\Domain\Exception\UnauthorizedException;
use App\Domain\Repository\ReservationRepositoryInterface;
use App\Domain\Repository\RoomMembershipRepositoryInterface;

/**
 * 予約済みの食事を別の部屋へ移す（旧部屋を取消 → 新部屋で登録）。
 *
 * 2つのトグルを1トランザクションで行い、どちらかが失敗したら旧部屋の予約を残す。
 * 権限・所属・過去日・直前のルールは ToggleMealUseCase をそのまま通す。
 */
final class ChangeMealRoomUseCase
{
    /** ToggleMealUseCase が直前期間の職員キャンセルを拒否するときの文言 */
    private const STAFF_LAST_MINUTE_CANCEL_MESSAGE = '職員は直前編集でのキャンセルはできません。';

    public function __construct(
        private readonly ToggleMealUseCase $toggleMeal,
        private readonly ReservationRepositoryInterface $reservations,
        private readonly RoomMembershipRepositoryInterface $rooms,
        private readonly ReservationCacheInterface $cache,
    ) {}

    /**
     * @throws DomainException 権限不足・所属外・競合・入力不正など
     */
    public function execute(ChangeMealRoomInput $input): ChangeMealRoomOutput
    {
        try {
            $this->reservations->transactional(function () use ($input): array {
                // 古い画面からの操作で、取消済み・移動済みの予約を「部屋変更」として登録し直さないよう、
                // 変更前の部屋に有効な予約があることを確かめる
                if (!in_array($input->fromRoomId, $this->reservations->effectiveRoomIds($input->targetUserId, $input->date, $input->meal), true)) {
                    throw new ConflictException('変更前の部屋の予約が見つかりません。画面を再読み込みしてください。');
                }
                // 退出済み・削除済みの部屋に残った予約は、取消だけ許す（管理者も、部屋の移動はできない）
                if (!$this->rooms->isRoomAvailable($input->fromRoomId) || !$this->rooms->isActiveMember($input->targetUserId, $input->fromRoomId)) {
                    throw new UnauthorizedException('所属外・削除済みの部屋の予約は部屋を変更できません。取り消してから登録し直してください。');
                }

                $this->toggleMeal->execute($this->toggleInput($input, $input->fromRoomId, 0));

                return $this->toggleMeal->execute($this->toggleInput($input, $input->toRoomId, 1))->toArray();
            });
        } catch (UnauthorizedException $e) {
            // 部屋変更なのに「キャンセルできません」と出ると利用者が混乱するため言い換える
            if ($e->getMessage() === self::STAFF_LAST_MINUTE_CANCEL_MESSAGE) {
                throw new UnauthorizedException('直前期間（14日以内）は職員の部屋変更はできません。');
            }
            throw $e;
        }

        $this->cache->forgetDate($input->date, [$input->targetUserId]);

        return new ChangeMealRoomOutput(
            meal:       $input->meal,
            fromRoomId: $input->fromRoomId,
            toRoomId:   $input->toRoomId,
        );
    }

    private function toggleInput(ChangeMealRoomInput $input, int $roomId, int $value): ToggleMealInput
    {
        return new ToggleMealInput(
            roomId: $roomId,
            date: $input->date,
            meal: $input->meal,
            value: $value,
            targetUserId: $input->targetUserId,
            loginUserId: $input->loginUserId,
            loginUserName: $input->loginUserName,
            invalidateCache: false,
        );
    }
}
