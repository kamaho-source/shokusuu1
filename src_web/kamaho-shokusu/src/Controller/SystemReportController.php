<?php
declare(strict_types=1);

namespace App\Controller;

use App\Service\SystemReportService;
use Authorization\Exception\ForbiddenException;
use Cake\Http\Response;
use Cake\Http\ServerRequest;

/**
 * システムレポートコントローラー（システム管理者専用）
 *
 * - index          : 部屋別使用率ページ
 * - data           : 部屋別集計 JSON API
 * - dailyChildren  : 日別子供総数ページ
 * - dailyChildrenData : 日別子供総数 JSON API
 * - loginReport    : ログイン情報ページ
 * - loginReportData: ログイン情報 JSON API
 *
 * Excel出力はフロントエンド（ExcelJS）が担当する。
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

    // ----------------------------------------------------------------
    // 部屋別使用率
    // ----------------------------------------------------------------

    /** GET /SystemReport */
    public function index(): ?Response
    {
        try {
            $this->Authorization->authorize($this, 'index');
        } catch (ForbiddenException $e) {
            $this->Flash->error('この機能はシステム管理者のみ利用できます。');
            return $this->redirect(['controller' => 'Pages', 'action' => 'dashboard']);
        }

        $allUsers       = $this->reportService->getAllUsers();
        $session        = $this->request->getSession();
        $excludeUserIds = $session->read('SystemReport.excludeUserIds') ?? [];

        $this->set(compact('allUsers', 'excludeUserIds'));
        return null;
    }

    /** GET /SystemReport/data */
    public function data(): Response
    {
        try {
            $this->Authorization->authorize($this, 'data');
        } catch (ForbiddenException $e) {
            return $this->jsonError('この機能はシステム管理者のみ利用できます。', 403);
        }

        $this->request->allowMethod(['get']);

        [$dateFrom, $dateTo, $excludeUserIds] = $this->resolveParams();

        $session = $this->request->getSession();
        $session->write('SystemReport.excludeUserIds', $excludeUserIds);

        $roomStats = $this->reportService->getRoomStats($excludeUserIds, $dateFrom, $dateTo);

        return $this->jsonResponse([
            'room_stats' => $roomStats,
            'date_from'  => $dateFrom,
            'date_to'    => $dateTo,
        ]);
    }

    // ----------------------------------------------------------------
    // 日別子供総数
    // ----------------------------------------------------------------

    /** GET /SystemReport/dailyChildren */
    public function dailyChildren(): ?Response
    {
        try {
            $this->Authorization->authorize($this, 'dailyChildren');
        } catch (ForbiddenException $e) {
            $this->Flash->error('この機能はシステム管理者のみ利用できます。');
            return $this->redirect(['controller' => 'Pages', 'action' => 'dashboard']);
        }

        $allUsers       = $this->reportService->getAllUsers();
        $session        = $this->request->getSession();
        $excludeUserIds = $session->read('SystemReport.excludeChildIds') ?? [];

        $this->set(compact('allUsers', 'excludeUserIds'));
        return null;
    }

    /** GET /SystemReport/dailyChildrenData */
    public function dailyChildrenData(): Response
    {
        try {
            $this->Authorization->authorize($this, 'dailyChildrenData');
        } catch (ForbiddenException $e) {
            return $this->jsonError('この機能はシステム管理者のみ利用できます。', 403);
        }

        $this->request->allowMethod(['get']);

        [$dateFrom, $dateTo, $excludeUserIds] = $this->resolveParams();

        $session = $this->request->getSession();
        $session->write('SystemReport.excludeChildIds', $excludeUserIds);

        $stats = $this->reportService->getDailyChildrenStats($excludeUserIds, $dateFrom, $dateTo);

        return $this->jsonResponse([
            'stats'     => $stats,
            'date_from' => $dateFrom,
            'date_to'   => $dateTo,
        ]);
    }

    // ----------------------------------------------------------------
    // ログイン情報
    // ----------------------------------------------------------------

    /** GET /SystemReport/loginReport */
    public function loginReport(): ?Response
    {
        try {
            $this->Authorization->authorize($this, 'loginReport');
        } catch (ForbiddenException $e) {
            $this->Flash->error('この機能はシステム管理者のみ利用できます。');
            return $this->redirect(['controller' => 'Pages', 'action' => 'dashboard']);
        }

        return null;
    }

    /** GET /SystemReport/loginReportData */
    public function loginReportData(): Response
    {
        try {
            $this->Authorization->authorize($this, 'loginReportData');
        } catch (ForbiddenException $e) {
            return $this->jsonError('この機能はシステム管理者のみ利用できます。', 403);
        }

        $this->request->allowMethod(['get']);

        $dateFrom = $this->request->getQuery('date_from') ?: date('Y-m-01');
        $dateTo   = $this->request->getQuery('date_to')   ?: date('Y-m-d');

        $stats = $this->reportService->getLoginStats($dateFrom, $dateTo);

        return $this->jsonResponse([
            'daily'     => $stats['daily'],
            'logs'      => $stats['logs'],
            'date_from' => $dateFrom,
            'date_to'   => $dateTo,
        ]);
    }

    // ----------------------------------------------------------------
    // 内部ヘルパー
    // ----------------------------------------------------------------

    /** @return array{0:string, 1:string, 2:array<int>} */
    private function resolveParams(): array
    {
        $dateFrom = $this->request->getQuery('date_from') ?: date('Y-m-01');
        $dateTo   = $this->request->getQuery('date_to')   ?: date('Y-m-d');

        $excludeRaw     = $this->request->getQuery('exclude') ?? [];
        $excludeUserIds = array_map('intval', is_array($excludeRaw) ? $excludeRaw : [$excludeRaw]);
        $excludeUserIds = array_values(array_filter($excludeUserIds, static fn(int $id): bool => $id > 0));

        return [$dateFrom, $dateTo, $excludeUserIds];
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
