<?php
declare(strict_types=1);

namespace App\Controller;

use App\Service\SystemReportService;
use Authorization\Exception\ForbiddenException;
use Cake\Http\Response;
use Cake\Http\ServerRequest;

/**
 * システムレポートコントローラー
 *
 * システム管理者専用。ユーザー別予約数・使用数の可視化とデータ取得APIを提供する。
 * Excel出力はフロントエンド（SheetJS）が担当する。
 */
class SystemReportController extends AppController
{
    public function __construct(
        private SystemReportService $reportService,
        ServerRequest $request
    ) {
        parent::__construct($request);
    }

    public function initialize(): void
    {
        parent::initialize();
        $this->viewBuilder()->setLayout('default');
    }

    /**
     * GET /SystemReport — グラフ表示ページ（HTML）
     */
    public function index(): ?Response
    {
        try {
            $this->Authorization->authorize($this, 'index');
        } catch (ForbiddenException $e) {
            $this->Flash->error('この機能はシステム管理者のみ利用できます。');
            return $this->redirect(['controller' => 'Pages', 'action' => 'dashboard']);
        }

        $allUsers = $this->reportService->getAllUsers();

        // セッションから除外ユーザーIDを復元
        $session        = $this->request->getSession();
        $excludeUserIds = $session->read('SystemReport.excludeUserIds') ?? [];

        $this->set(compact('allUsers', 'excludeUserIds'));
        return null;
    }

    /**
     * GET /SystemReport/data — ユーザー別集計データJSON API
     *
     * クエリパラメータ:
     *   - date_from    (Y-m-d)
     *   - date_to      (Y-m-d)
     *   - exclude[]    除外するユーザーID（複数指定可）
     */
    public function data(): Response
    {
        try {
            $this->Authorization->authorize($this, 'data');
        } catch (ForbiddenException $e) {
            return $this->jsonError('この機能はシステム管理者のみ利用できます。', 403);
        }

        $this->request->allowMethod(['get']);

        $dateFrom = $this->request->getQuery('date_from') ?: date('Y-m-01');
        $dateTo   = $this->request->getQuery('date_to')   ?: date('Y-m-d');

        $excludeRaw     = $this->request->getQuery('exclude') ?? [];
        $excludeUserIds = array_map('intval', is_array($excludeRaw) ? $excludeRaw : [$excludeRaw]);
        $excludeUserIds = array_filter($excludeUserIds, static fn(int $id): bool => $id > 0);
        $excludeUserIds = array_values($excludeUserIds);

        // 除外ユーザー設定をセッションに保存
        $session = $this->request->getSession();
        $session->write('SystemReport.excludeUserIds', $excludeUserIds);

        $roomStats  = $this->reportService->getRoomStats($excludeUserIds, $dateFrom, $dateTo);
        $dailyStats = $this->reportService->getDailyStats($excludeUserIds, $dateFrom, $dateTo);

        return $this->jsonResponse([
            'room_stats'  => $roomStats,
            'daily_stats' => $dailyStats,
            'date_from'   => $dateFrom,
            'date_to'     => $dateTo,
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
