<?php
declare(strict_types=1);

namespace App\Controller;

use App\Service\SystemReportService;
use Authorization\Exception\ForbiddenException;
use Cake\Http\Exception\BadRequestException;
use Cake\Http\Response;
use Cake\Http\ServerRequest;
use Cake\Log\Log;

/**
 * システムレポートコントローラー（レポート閲覧権限ユーザー向け）
 *
 * - index / data              : 部屋別使用率
 * - dailyChildren / Data     : 日別子供総数
 * - loginReport / Data       : ログイン情報
 *
 * Excel出力はフロントエンド（ExcelJS）が担当する。
 */
final class SystemReportController extends AppController
{
    private const FORBIDDEN_MESSAGE = 'この機能を利用する権限がありません。';
    private const MAX_RANGE_DAYS = 366;

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
     * GET /SystemReport
     *
     * @throws \Cake\Http\Exception\RedirectException
     */
    public function index(): ?Response
    {
        try {
            $this->Authorization->authorize($this, 'index');
        } catch (ForbiddenException $e) {
            $this->Flash->error(self::FORBIDDEN_MESSAGE);
            return $this->redirect(['controller' => 'Pages', 'action' => 'dashboard']);
        }

        $allUsers       = $this->reportService->getAllUsers();
        $session        = $this->request->getSession();
        $excludeUserIds = $session->read('SystemReport.excludeUserIds') ?? [];

        $this->set(compact('allUsers', 'excludeUserIds'));
        return null;
    }

    /**
     * GET /SystemReport/data
     */
    public function data(): Response
    {
        try {
            $this->Authorization->authorize($this, 'data');
        } catch (ForbiddenException $e) {
            return $this->jsonError(self::FORBIDDEN_MESSAGE, 403);
        }

        $this->request->allowMethod(['get']);

        try {
            [$dateFrom, $dateTo, $excludeUserIds] = $this->resolveParams();
        } catch (BadRequestException $e) {
            return $this->jsonError($e->getMessage(), 422);
        }

        $session = $this->request->getSession();
        $session->write('SystemReport.excludeUserIds', $excludeUserIds);

        try {
            $roomStats = $this->reportService->getRoomStats($excludeUserIds, $dateFrom, $dateTo);
        } catch (\Throwable $e) {
            Log::error('SystemReport#data failed: ' . $e->getMessage());
            return $this->jsonError('集計処理に失敗しました。', 500);
        }

        return $this->jsonResponse([
            'room_stats' => $roomStats,
            'date_from'  => $dateFrom,
            'date_to'    => $dateTo,
        ]);
    }

    /**
     * GET /SystemReport/dailyChildren
     */
    public function dailyChildren(): ?Response
    {
        try {
            $this->Authorization->authorize($this, 'dailyChildren');
        } catch (ForbiddenException $e) {
            $this->Flash->error(self::FORBIDDEN_MESSAGE);
            return $this->redirect(['controller' => 'Pages', 'action' => 'dashboard']);
        }

        $allUsers       = $this->reportService->getAllUsers();
        $session        = $this->request->getSession();
        $excludeUserIds = $session->read('SystemReport.excludeChildIds') ?? [];

        $this->set(compact('allUsers', 'excludeUserIds'));
        return null;
    }

    /**
     * GET /SystemReport/dailyChildrenData
     */
    public function dailyChildrenData(): Response
    {
        try {
            $this->Authorization->authorize($this, 'dailyChildrenData');
        } catch (ForbiddenException $e) {
            return $this->jsonError(self::FORBIDDEN_MESSAGE, 403);
        }

        $this->request->allowMethod(['get']);

        try {
            [$dateFrom, $dateTo, $excludeUserIds] = $this->resolveParams();
        } catch (BadRequestException $e) {
            return $this->jsonError($e->getMessage(), 422);
        }

        $session = $this->request->getSession();
        $session->write('SystemReport.excludeChildIds', $excludeUserIds);

        try {
            $stats = $this->reportService->getDailyChildrenStats($excludeUserIds, $dateFrom, $dateTo);
        } catch (\Throwable $e) {
            Log::error('SystemReport#dailyChildrenData failed: ' . $e->getMessage());
            return $this->jsonError('集計処理に失敗しました。', 500);
        }

        return $this->jsonResponse([
            'stats'     => $stats,
            'date_from' => $dateFrom,
            'date_to'   => $dateTo,
        ]);
    }

    /**
     * GET /SystemReport/loginReport
     */
    public function loginReport(): ?Response
    {
        try {
            $this->Authorization->authorize($this, 'loginReport');
        } catch (ForbiddenException $e) {
            $this->Flash->error(self::FORBIDDEN_MESSAGE);
            return $this->redirect(['controller' => 'Pages', 'action' => 'dashboard']);
        }

        return null;
    }

    /**
     * GET /SystemReport/loginReportData
     */
    public function loginReportData(): Response
    {
        try {
            $this->Authorization->authorize($this, 'loginReportData');
        } catch (ForbiddenException $e) {
            return $this->jsonError(self::FORBIDDEN_MESSAGE, 403);
        }

        $this->request->allowMethod(['get']);

        try {
            [$dateFrom, $dateTo] = $this->resolveDateRange();
        } catch (BadRequestException $e) {
            return $this->jsonError($e->getMessage(), 422);
        }

        try {
            $stats = $this->reportService->getLoginStats($dateFrom, $dateTo);
        } catch (\Throwable $e) {
            Log::error('SystemReport#loginReportData failed: ' . $e->getMessage());
            return $this->jsonError('集計処理に失敗しました。', 500);
        }

        return $this->jsonResponse([
            'daily'     => $stats['daily'],
            'logs'      => $stats['logs'],
            'date_from' => $dateFrom,
            'date_to'   => $dateTo,
        ]);
    }

    /**
     * @return array{0:string, 1:string, 2:array<int>}
     * @throws \Cake\Http\Exception\BadRequestException
     */
    private function resolveParams(): array
    {
        [$dateFrom, $dateTo] = $this->resolveDateRange();

        $excludeRaw     = $this->request->getQuery('exclude') ?? [];
        $excludeUserIds = array_map('intval', is_array($excludeRaw) ? $excludeRaw : [$excludeRaw]);
        $excludeUserIds = array_values(array_filter($excludeUserIds, static fn(int $id): bool => $id > 0));

        return [$dateFrom, $dateTo, $excludeUserIds];
    }

    /**
     * @return array{0:string, 1:string}
     * @throws \Cake\Http\Exception\BadRequestException
     */
    private function resolveDateRange(): array
    {
        $dateFrom = (string)($this->request->getQuery('date_from') ?: date('Y-m-01'));
        $dateTo   = (string)($this->request->getQuery('date_to') ?: date('Y-m-d'));

        if (!$this->isValidYmd($dateFrom) || !$this->isValidYmd($dateTo)) {
            throw new BadRequestException('日付は YYYY-MM-DD 形式で指定してください。');
        }

        $from = new \DateTimeImmutable($dateFrom);
        $to   = new \DateTimeImmutable($dateTo);
        if ($from > $to) {
            throw new BadRequestException('開始日は終了日以前を指定してください。');
        }

        $days = (int)$from->diff($to)->days + 1;
        if ($days > self::MAX_RANGE_DAYS) {
            throw new BadRequestException('集計期間は最大 ' . self::MAX_RANGE_DAYS . ' 日までです。');
        }

        return [$dateFrom, $dateTo];
    }

    private function isValidYmd(string $value): bool
    {
        $dt = \DateTimeImmutable::createFromFormat('Y-m-d', $value);
        return $dt !== false && $dt->format('Y-m-d') === $value;
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
