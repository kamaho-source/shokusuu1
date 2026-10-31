<?php
declare(strict_types=1);

namespace App\Controller;

use App\Application\UseCase\ToggleMeal\ToggleMealInput;
use App\Infrastructure\ReservationUseCases;
use Cake\Http\Response;

/**
 * 予約トグル専用コントローラー。
 *
 * Kid UI からの単一食事フラグ ON/OFF を担当する。
 */
class ReservationToggleController extends ReservationBaseController
{
    private ReservationUseCases $useCases;

    public function initialize(): void
    {
        parent::initialize();

        $this->useCases = new ReservationUseCases($this->TIndividualReservationInfo, $this->MUserInfo);

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
            $payload = (array)(json_decode((string)$this->request->getBody(), true) ?? []);
        }
        if ($roomId === null) {
            $roomId = isset($payload['roomId']) ? (int)$payload['roomId'] : (int)($payload['i_id_room'] ?? 0);
        }
        if ($roomId <= 0) {
            // 権限判定の前に返すため、未判定のまま 500 にならないよう判定を省いたことを明示する
            $this->Authorization->skipAuthorization();

            return $this->apiResponseService->error($this->response, 'roomId is required.', 400);
        }
        $targetUserId = isset($payload['userId']) ? (int)$payload['userId'] : 0;

        if ($denied = $this->authorizeReservation('toggle', [
            'i_id_room' => (int)$roomId,
            'i_id_user' => $targetUserId,
            'cancel_only' => isset($payload['value']) && (int)$payload['value'] === 0,
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

        // user_id（対象者）は予約履歴画面が変更の経緯を引くのに使う。末尾に置く（検索が "user_id":N, と "user_id":N} を見る）
        $auditContext = ['date' => $payload['date'] ?? null, 'meal' => $payload['meal'] ?? null, 'value' => $payload['value'] ?? null, 'user_id' => $targetUserId > 0 ? $targetUserId : $loginUserId];
        try {
            $result = $this->useCases->toggleMeal()
                ->execute(ToggleMealInput::fromPayload($roomId, $payload, $loginUserId, $loginUserName))
                ->toArray();

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
     * BulkToggleMealsUseCase（内部で ToggleMealUseCase） が item 単位で行い、
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
        // 部屋・対象ユーザー単位の詳細な権限は ToggleMealUseCase で item ごとに判定する。
        if ($denied = $this->authorizeReservation('mealCountGrid', [], true)) {
            return $denied;
        }

        $payload = (array)$this->request->getData();
        if (empty($payload)) {
            $payload = (array)(json_decode((string)$this->request->getBody(), true) ?? []);
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

        $results = $this->useCases->bulkToggleMeals()->execute($items, $loginUserId, $loginUserName);

        $okCount   = count(array_filter($results, static fn($r) => !empty($r['ok'])));
        $failCount = count($results) - $okCount;

        // 失敗した item を理由つきで集計する（監査ログで「なぜ登録できなかったか」を追えるようにする）。
        $itemList = array_values($items);
        $failures = [];
        $reasonCounts = [];
        foreach ($results as $i => $r) {
            if (!empty($r['ok'])) {
                continue;
            }
            $msg = (string)($r['message'] ?? '登録に失敗しました。');
            $it  = (array)($itemList[$i] ?? []);
            $reasonCounts[$msg] = ($reasonCounts[$msg] ?? 0) + 1;
            if (count($failures) < 50) {
                $failures[] = [
                    'userId' => (int)($it['userId'] ?? 0),
                    'roomId' => (int)($it['roomId'] ?? $it['i_id_room'] ?? 0),
                    'date'   => (string)($it['date'] ?? ''),
                    'meal'   => $it['meal'] ?? null,
                    'reason' => $msg,
                ];
            }
        }
        // 理由の種類が多すぎても監査ログの列（TEXT）を溢れさせないよう、多い順に上位10種類までにする。
        arsort($reasonCounts);
        $reasonParts = [];
        foreach (array_slice($reasonCounts, 0, 10, true) as $msg => $n) {
            $reasonParts[] = "{$msg}({$n}件)";
        }
        if (count($reasonCounts) > 10) {
            $reasonParts[] = 'ほか' . (count($reasonCounts) - 10) . '種類';
        }
        $failureReason = $failCount === 0
            ? null
            : "{$failCount}件の登録に失敗しました。理由: " . implode(' / ', $reasonParts);

        \App\Service\AuditLogService::record(
            'reservation', 'reservation_bulk_toggle', $loginUserName, $loginUserId,
            't_reservation_info', null,
            ['total' => count($results), 'ok' => $okCount, 'fail' => $failCount, 'failures' => $failures],
            $this->getClientIp(), $failCount === 0 ? 1 : 0, $loginAccount,
            $failureReason
        );

        return $this->apiResponseService->success(
            $this->response,
            ['results' => $results, 'ok' => $okCount, 'fail' => $failCount],
            null,
            200
        );
    }
}
