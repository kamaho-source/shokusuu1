<?php
declare(strict_types=1);

namespace App\Controller;

use App\Service\RoomUsageService;
use Authorization\Exception\ForbiddenException;
use Cake\Http\Response;

/**
 * 部屋使用率コントローラー
 *
 * システム管理者専用。部屋ごとの使用率集計・低使用率部屋のピックアップを提供する。
 */
class RoomUsageController extends AppController
{
    private RoomUsageService $roomUsageService;

    public function initialize(): void
    {
        parent::initialize();
        $this->roomUsageService = new RoomUsageService();
        $this->viewBuilder()->setLayout('default');
    }

    /**
     * GET /RoomUsage — 部屋使用率一覧ページ（HTML）
     */
    public function index(): ?Response
    {
        try {
            $this->Authorization->authorize($this, 'index');
        } catch (ForbiddenException $e) {
            $this->Flash->error('この機能はシステム管理者のみ利用できます。');
            return $this->redirect(['controller' => 'Pages', 'action' => 'dashboard']);
        }

        $dateFrom  = $this->request->getQuery('date_from') ?: date('Y-m-01');
        $dateTo    = $this->request->getQuery('date_to')   ?: date('Y-m-d');
        $mealType  = $this->request->getQuery('meal_type') !== null && $this->request->getQuery('meal_type') !== ''
            ? (int)$this->request->getQuery('meal_type')
            : null;
        $threshold = $this->request->getQuery('threshold') !== null && $this->request->getQuery('threshold') !== ''
            ? (float)$this->request->getQuery('threshold')
            : 50.0;

        $rooms    = $this->roomUsageService->getRoomUsage($dateFrom, $dateTo, $mealType);
        $lowRooms = $this->roomUsageService->getLowUsageRooms($threshold, $dateFrom, $dateTo, $mealType);

        $this->set(compact('rooms', 'lowRooms', 'dateFrom', 'dateTo', 'mealType', 'threshold'));
        return null;
    }

    /**
     * GET /RoomUsage/roomUsage
     *
     * 部屋ごとの使用率一覧を返す。
     *
     * クエリパラメータ:
     *   - date_from  (Y-m-d)
     *   - date_to    (Y-m-d)
     *   - meal_type  (1=朝 2=昼 3=夕 4=弁当)
     */
    public function roomUsage(): Response
    {
        try {
            $this->Authorization->authorize($this, 'roomUsage');
        } catch (ForbiddenException $e) {
            return $this->jsonError('この機能はシステム管理者のみ利用できます。', 403);
        }

        $dateFrom = $this->request->getQuery('date_from') ?: null;
        $dateTo   = $this->request->getQuery('date_to')   ?: null;
        $mealType = $this->request->getQuery('meal_type')  !== null && $this->request->getQuery('meal_type') !== ''
            ? (int)$this->request->getQuery('meal_type')
            : null;

        $rooms = $this->roomUsageService->getRoomUsage($dateFrom, $dateTo, $mealType);

        return $this->jsonResponse(['rooms' => $rooms]);
    }

    /**
     * GET /RoomUsage/lowUsageRooms
     *
     * 使用率が閾値以下の部屋を返す。
     *
     * クエリパラメータ:
     *   - threshold  (float, デフォルト 50.0)
     *   - date_from  (Y-m-d)
     *   - date_to    (Y-m-d)
     *   - meal_type  (1=朝 2=昼 3=夕 4=弁当)
     */
    public function lowUsageRooms(): Response
    {
        try {
            $this->Authorization->authorize($this, 'lowUsageRooms');
        } catch (ForbiddenException $e) {
            return $this->jsonError('この機能はシステム管理者のみ利用できます。', 403);
        }

        $threshold = $this->request->getQuery('threshold') !== null && $this->request->getQuery('threshold') !== ''
            ? (float)$this->request->getQuery('threshold')
            : 50.0;
        $dateFrom = $this->request->getQuery('date_from') ?: null;
        $dateTo   = $this->request->getQuery('date_to')   ?: null;
        $mealType = $this->request->getQuery('meal_type') !== null && $this->request->getQuery('meal_type') !== ''
            ? (int)$this->request->getQuery('meal_type')
            : null;

        $rooms = $this->roomUsageService->getLowUsageRooms($threshold, $dateFrom, $dateTo, $mealType);

        return $this->jsonResponse([
            'threshold' => $threshold,
            'rooms'     => $rooms,
        ]);
    }

    private function jsonResponse(array $data, int $status = 200): Response
    {
        return $this->response
            ->withStatus($status)
            ->withType('application/json')
            ->withStringBody((string)json_encode($data, JSON_UNESCAPED_UNICODE));
    }

    private function jsonError(string $message, int $status = 400): Response
    {
        return $this->jsonResponse(['success' => false, 'error' => $message], $status);
    }
}
