<?php
declare(strict_types=1);

namespace App\Controller;

use App\Service\FeatureUsageSummaryService;
use Authorization\Exception\ForbiddenException;
use Cake\Http\Response;

/**
 * 機能使用頻度ダッシュボードコントローラー
 *
 * システム管理者（i_admin = 3）専用。
 * audit_log を月次集計し、機能ごとの使用回数・ユニークユーザー数・最終利用日を表示する。
 */
class FeatureUsageSummaryController extends AppController
{
    private FeatureUsageSummaryService $summaryService;

    public function initialize(): void
    {
        parent::initialize();
        $this->summaryService = new FeatureUsageSummaryService();
    }

    /**
     * 機能使用頻度ダッシュボード
     *
     * @return Response|null
     */
    public function index(): ?Response
    {
        try {
            $this->Authorization->authorize($this, 'index');
        } catch (ForbiddenException $e) {
            $this->Flash->error(__('この機能はシステム管理者のみ利用できます。'));
            return $this->redirect(['controller' => 'Pages', 'action' => 'dashboard']);
        }

        $q         = $this->request->getQueryParams();
        $yearMonth = $q['month'] ?? date('Y-m');
        $category  = $q['category'] ?? null;

        // YYYY-MM 形式でない場合は今月にフォールバック
        if (!preg_match('/^\d{4}-\d{2}$/', $yearMonth)) {
            $yearMonth = date('Y-m');
        }

        $summary          = $this->summaryService->getSummary($yearMonth, $category ?: null);
        $hourlyUsage      = $this->summaryService->getHourlyDistribution($yearMonth, $category ?: null);
        $monthOptions     = $this->summaryService->getMonthOptions();
        $categories       = FeatureUsageSummaryService::CATEGORY_LABELS;

        $this->set(compact('summary', 'hourlyUsage', 'monthOptions', 'categories', 'yearMonth', 'category'));
        return null;
    }
}
