<?php
declare(strict_types=1);

namespace App\Application\UseCase\ChangeMealRoom;

use App\Domain\Exception\DomainException;
use App\Domain\Exception\UnauthorizedException;
use App\Service\ReservationWriteService;

/**
 * カレンダーの予約ポップアップで、予約済みの食事を別の部屋へ移すユースケース。
 *
 * 旧部屋の取消と新部屋の登録は ReservationWriteService::processRoomChange() が
 * 1トランザクションで行う。
 */
final class ChangeMealRoomUseCase
{
    /** processToggle が直前期間の職員キャンセルを拒否するときの文言 */
    private const STAFF_LAST_MINUTE_CANCEL_MESSAGE = '職員は直前編集でのキャンセルはできません。';

    public function __construct(
        private readonly ReservationWriteService $writeService,
    ) {}

    /**
     * @throws DomainException 権限不足・所属外・競合・入力不正など
     */
    public function execute(ChangeMealRoomInput $input): ChangeMealRoomOutput
    {
        try {
            $this->writeService->processRoomChange(
                fromRoomId:    $input->fromRoomId,
                toRoomId:      $input->toRoomId,
                payload:       ['date' => $input->date, 'meal' => $input->meal, 'userId' => $input->targetUserId],
                loginUserId:   $input->loginUserId,
                loginUserName: $input->loginUserName,
            );
        } catch (UnauthorizedException $e) {
            // 部屋変更なのに「キャンセルできません」と出ると利用者が混乱するため言い換える
            if ($e->getMessage() === self::STAFF_LAST_MINUTE_CANCEL_MESSAGE) {
                throw new UnauthorizedException('直前期間（14日以内）は職員の部屋変更はできません。');
            }
            throw $e;
        }

        return new ChangeMealRoomOutput(
            meal:       $input->meal,
            fromRoomId: $input->fromRoomId,
            toRoomId:   $input->toRoomId,
        );
    }
}
