<?php
declare(strict_types=1);

namespace App\Controller;

use App\Service\ReservationWriteService;
use Cake\Http\Response;

/**
 * 予約トグル専用コントローラー。
 *
 * Kid UI からの単一食事フラグ ON/OFF を担当する。
 */
class ReservationToggleController extends ReservationBaseController
{
    private ReservationWriteService $writeService;

    public function initialize(): void
    {
        parent::initialize();

        $this->writeService = new ReservationWriteService(
            $this->TIndividualReservationInfo,
            $this->MUserInfo,
            $this->MRoomInfo,
            (string)($this->request->getAttribute('webroot') ?? '')
        );

        $this->FormProtection->setConfig('unlockedActions', ['toggle']);
    }

    /**
     * 予約トグルAPI（個人が自分の1日1食区分をON/OFF）。
     *
     * @param int|null $roomId
     * @return Response|null
     */
    public function toggle(?int $roomId = null): ?Response
    {
        $this->request->allowMethod(['post']);
        $this->response = $this->response->withType('application/json');

        $payload = (array)$this->request->getData();
        if (empty($payload)) {
            $payload = (array)($this->request->input('json_decode', true) ?? []);
        }
        if ($roomId === null) {
            $roomId = isset($payload['roomId']) ? (int)$payload['roomId'] : (int)($payload['i_id_room'] ?? 0);
        }
        if ($roomId <= 0) {
            return $this->apiResponseService->error($this->response, 'roomId is required.', 400);
        }
        $targetUserId = isset($payload['userId']) ? (int)$payload['userId'] : 0;

        if ($denied = $this->authorizeReservation('toggle', [
            'i_id_room' => (int)$roomId,
            'i_id_user' => $targetUserId,
        ], true)) {
            return $denied;
        }

        $loginUser = $this->request->getAttribute('identity');
        $loginUserId   = (int)($loginUser?->get('i_id_user') ?? $loginUser?->get('id') ?? 0);
        $loginUserName = (string)($loginUser?->get('c_user_name') ?? '不明');
        $loginAccount  = (string)($loginUser?->get('c_login_account') ?? '');
        if ($loginUserId <= 0) {
            return $this->apiResponseService->error($this->response, 'Unauthorized', 401);
        }

        $auditContext = ['date' => $payload['date'] ?? null, 'meal' => $payload['meal'] ?? null, 'value' => $payload['value'] ?? null];
        try {
            $result = $this->writeService->processToggle(
                roomId: $roomId,
                payload: $payload,
                loginUserId: $loginUserId,
                loginUserName: $loginUserName
            );

            \App\Service\AuditLogService::record(
                'reservation', 'reservation_toggle', $loginUserName, $loginUserId,
                't_reservation_info', "room:{$roomId}", $auditContext, $this->getClientIp(), 1, $loginAccount
            );

            return $this->apiResponseService->success($this->response, $result, null, 200);
        } catch (\App\Domain\Exception\DomainException $e) {
            \App\Service\AuditLogService::record(
                'reservation', 'reservation_toggle', $loginUserName, $loginUserId,
                't_reservation_info', "room:{$roomId}", $auditContext, $this->getClientIp(), 0, $loginAccount
            );

            return $this->apiResponseService->error($this->response, $e->getMessage(), $e->getStatusCode());
        }
    }
}
