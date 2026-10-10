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

        $this->FormProtection->setConfig('unlockedActions', ['toggle', 'bulkToggle']);
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
                't_reservation_info', "room:{$roomId}", $auditContext, $this->getClientIp(), 0, $loginAccount,
                $e->getMessage()
            );

            return $this->apiResponseService->error($this->response, $e->getMessage(), $e->getStatusCode());
        }
    }

    /**
     * 一括予約トグルAPI（エクセル食数予約の一括登録用）。
     *
     * 複数セルの変更を1リクエストでまとめて処理する。各 item の詳細な権限・競合判定は
     * ReservationWriteService::processBulkToggle(内部で processToggle) が item 単位で行い、
     * 一部成功・一部失敗を許容する。監査ログは件数サマリとして1件だけ記録する。
     *
     * ペイロード: { items: [{ roomId, userId, date, meal, value }, ...] }
     *
     * @return Response|null
     */
    public function bulkToggle(): ?Response
    {
        $this->request->allowMethod(['post']);
        $this->response = $this->response->withType('application/json');

        // 入口の認可を最初に実行する（早期 return でも認可チェックが必ず適用されるようにする）。
        // 一括登録はエクセル食数予約画面(mealCountGrid)の保存機能なので同じ認可を使う。
        // 部屋・対象ユーザー単位の詳細な権限は processToggle 内で item ごとに判定する。
        if ($denied = $this->authorizeReservation('mealCountGrid', [], true)) {
            return $denied;
        }

        $payload = (array)$this->request->getData();
        if (empty($payload)) {
            $payload = (array)($this->request->input('json_decode', true) ?? []);
        }

        $items = $payload['items'] ?? null;
        if (!is_array($items) || count($items) === 0) {
            return $this->apiResponseService->error($this->response, 'items is required.', 400);
        }
        // 過大リクエストのガード（グリッドは 人数×28日×4食 程度）。
        if (count($items) > 5000) {
            return $this->apiResponseService->error($this->response, 'items too many.', 400);
        }

        $loginUser     = $this->request->getAttribute('identity');
        $loginUserId   = (int)($loginUser?->get('i_id_user') ?? $loginUser?->get('id') ?? 0);
        $loginUserName = (string)($loginUser?->get('c_user_name') ?? '不明');
        $loginAccount  = (string)($loginUser?->get('c_login_account') ?? '');
        if ($loginUserId <= 0) {
            return $this->apiResponseService->error($this->response, 'Unauthorized', 401);
        }

        $results = $this->writeService->processBulkToggle($items, $loginUserId, $loginUserName);

        $okCount   = count(array_filter($results, static fn($r) => !empty($r['ok'])));
        $failCount = count($results) - $okCount;

        \App\Service\AuditLogService::record(
            'reservation', 'reservation_bulk_toggle', $loginUserName, $loginUserId,
            't_reservation_info', null,
            ['total' => count($results), 'ok' => $okCount, 'fail' => $failCount],
            $this->getClientIp(), $failCount === 0 ? 1 : 0, $loginAccount,
            $failCount === 0 ? null : "{$failCount}件の登録に失敗しました。"
        );

        return $this->apiResponseService->success(
            $this->response,
            ['results' => $results, 'ok' => $okCount, 'fail' => $failCount],
            null,
            200
        );
    }
}
