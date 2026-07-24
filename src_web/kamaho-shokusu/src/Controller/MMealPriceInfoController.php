<?php
declare(strict_types=1);

namespace App\Controller;

use App\Service\ApiResponseService;
use App\Service\MealSummaryExportService;
use Authorization\Exception\ForbiddenException;

/**
 * MMealPriceInfo Controller
 *
 */
class MMealPriceInfoController extends AppController
{

    protected $MMealPriceInfo;
    private MealSummaryExportService $mealSummaryExportService;

    public function initialize(): void
    {
        parent::initialize();
        $this->MMealPriceInfo = $this->fetchTable('MMealPriceInfo');
        $this->mealSummaryExportService = new MealSummaryExportService();
    }

    /**
     * Index method
     *
     * @return \Cake\Http\Response|null|void Renders view
     */
    public function index()
    {
        $resource = $this->MMealPriceInfo->newEmptyEntity();
        try {
            $this->Authorization->authorize($resource, 'index');
        } catch (ForbiddenException $e) {
            $this->Flash->error(__('あなたは閲覧権限がありません。'));
            return $this->redirect(['controller' => 'MUserInfo', 'action' => 'login']);
        }

        $query = $this->MMealPriceInfo->find();
        $mMealPriceInfo = $this->paginate($query);

        $this->set(compact('mMealPriceInfo'));
    }

    /**
     * View method
     *
     * @param string|null $id M Meal Price Info id.
     * @return \Cake\Http\Response|null|void Renders view
     * @throws \Cake\Datasource\Exception\RecordNotFoundException When record not found.
     */
    public function view($id = null)
    {
        $mMealPriceInfo = $this->MMealPriceInfo->get($id, contain: []);
        try {
            $this->Authorization->authorize($mMealPriceInfo, 'view');
        } catch (ForbiddenException $e) {
            $this->Flash->error(__('あなたは閲覧権限がありません。'));
            return $this->redirect(['action' => 'index']);
        }
        $this->set(compact('mMealPriceInfo'));
    }

    /**
     * Add method
     *
     * @return \Cake\Http\Response|null|void Redirects on successful add, renders view otherwise.
     */
    public function add()
    {
        $this->request->allowMethod(['get', 'post']);
        $mMealPriceInfo = $this->MMealPriceInfo->newEmptyEntity();
        try {
            $this->Authorization->authorize($mMealPriceInfo, 'add');
        } catch (ForbiddenException $e) {
            $this->Flash->error(__('あなたは追加権限がありません。'));
            return $this->redirect(['action' => 'index']);
        }
        $user = $this->request->getAttribute('identity');
        if ($this->request->is('post')) {
            $mMealPriceInfo = $this->MMealPriceInfo->patchEntity($mMealPriceInfo, $this->request->getData());
            $mMealPriceInfo->dt_create = date('Y-m-d H:i:s');
            $mMealPriceInfo->c_create_user = $user ? $user->get('c_user_name') : null;

            if ($this->MMealPriceInfo->save($mMealPriceInfo)) {
                \App\Service\AuditLogService::record('master', 'meal_price_create', $mMealPriceInfo->c_create_user ?? '不明', $user ? (int)$user->get('i_id_user') : 0, 'm_meal_price_info', (string)$mMealPriceInfo->i_id_price, null, $this->getClientIp(), 1, (string)($user?->get('c_login_account') ?? ''));
                $this->Flash->success(__('食事料金情報が正常に保存されました。'));

                return $this->redirect(['action' => 'index']);
            }
            $this->Flash->error(__('食事料金情報を保存できませんでした。もう一度お試しください。'));
        }
        $this->set(compact('mMealPriceInfo'));
    }

    /**
     * Edit method
     *
     * @param string|null $id M Meal Price Info id.
     * @return \Cake\Http\Response|null|void Redirects on successful edit, renders view otherwise.
     * @throws \Cake\Datasource\Exception\RecordNotFoundException When record not found.
     */
    public function edit($id = null)
    {
        $this->request->allowMethod(['get', 'post', 'put', 'patch']);
        $mMealPriceInfo = $this->MMealPriceInfo->get($id, contain: []);
        try {
            $this->Authorization->authorize($mMealPriceInfo, 'edit');
        } catch (ForbiddenException $e) {
            $this->Flash->error(__('あなたは編集権限がありません。'));
            return $this->redirect(['action' => 'index']);
        }
        $user = $this->request->getAttribute('identity');
        if ($this->request->is(['patch', 'post', 'put'])) {
            $mMealPriceInfo = $this->MMealPriceInfo->patchEntity($mMealPriceInfo, $this->request->getData());
            $mMealPriceInfo->dt_update = date('Y-m-d H:i:s');
            $mMealPriceInfo->c_update_user = $user ? $user->get('c_user_name') : null;
            if ($this->MMealPriceInfo->save($mMealPriceInfo)) {
                \App\Service\AuditLogService::record('master', 'meal_price_update', $mMealPriceInfo->c_update_user ?? '不明', $user ? (int)$user->get('i_id_user') : 0, 'm_meal_price_info', (string)$mMealPriceInfo->i_id_price, null, $this->getClientIp(), 1, (string)($user?->get('c_login_account') ?? ''));
                $this->Flash->success(__('食事料金情報が正常に更新されました。'));

                return $this->redirect(['action' => 'index']);
            }
            $this->Flash->error(__('食事料金情報を更新できませんでした。もう一度お試しください。'));
        }
        $this->set(compact('mMealPriceInfo'));
    }

    /**
     * Delete method
     *
     * @param string|null $id M Meal Price Info id.
     * @return \Cake\Http\Response|null Redirects to index.
     * @throws \Cake\Datasource\Exception\RecordNotFoundException When record not found.
     */
    public function delete($id = null)
    {
        $this->request->allowMethod(['post', 'delete']);
        $mMealPriceInfo = $this->MMealPriceInfo->get($id);
        try {
            $this->Authorization->authorize($mMealPriceInfo, 'delete');
        } catch (ForbiddenException $e) {
            $this->Flash->error(__('あなたは削除権限がありません。'));
            return $this->redirect(['action' => 'index']);
        }
        $user = $this->request->getAttribute('identity');
        $deleted = $this->MMealPriceInfo->delete($mMealPriceInfo);
        \App\Service\AuditLogService::record('master', 'meal_price_delete', $user?->get('c_user_name') ?? '不明', $user ? (int)$user->get('i_id_user') : 0, 'm_meal_price_info', (string)$mMealPriceInfo->i_id_price, null, $this->getClientIp(), $deleted ? 1 : 0, (string)($user?->get('c_login_account') ?? ''));
        if ($deleted) {
            $this->Flash->success(__('食事料金情報が正常に削除されました。'));
        } else {
            $this->Flash->error(__('食事料金情報を削除できませんでした。もう一度お試しください。'));
        }

        return $this->redirect(['action' => 'index']);
    }

    public function getMealSummary()
    {
        $this->Authorization->authorize($this->MMealPriceInfo->newEmptyEntity(), 'index');

        // 年度と月をリクエストから取得 (デフォルト値は今年と現在の月)
        $Year = $this->request->getQuery('year', date('Y'));
        $month = $this->request->getQuery('month', date('n')); // 月は1月から12月で選択

        // 年度と月のリストをテンプレートに渡す
        $yearList = $this->MMealPriceInfo->find()
            ->select(['i_fiscal_year'])
            ->distinct(['i_fiscal_year'])
            ->orderBy(['i_fiscal_year'])
            ->toArray();

        $monthList = range(1, 12); // 月のリスト (1〜12)

        $this->set(compact('yearList', 'monthList', 'Year', 'month'));
    }

    public function exportMealSummary()
    {
        $this->Authorization->authorize($this->MMealPriceInfo->newEmptyEntity(), 'index');

        $this->autoRender = false;
        $apiResponse = new ApiResponseService();

        $year  = (int)$this->request->getQuery('year', date('Y'));
        $month = (int)$this->request->getQuery('month', date('n'));

        $monthlyData = $this->mealSummaryExportService->aggregate($year, $month);

        $identity = $this->request->getAttribute('identity');
        \App\Service\AuditLogService::record(
            'master',
            'meal_price_excel_export',
            $identity?->get('c_user_name') ?? '不明',
            $identity ? (int)$identity->get('i_id_user') : 0,
            'm_meal_price_info',
            null,
            ['year' => $year, 'month' => $month],
            $this->getClientIp(),
            1,
            (string)($identity?->get('c_login_account') ?? '')
        );

        return $apiResponse->success($this->response, ['rows' => $monthlyData]);
    }

    /**
     * GET /MMealPriceInfo/exportMealSummaryPreview
     *
     * 未承認(status=0)・ブロック長承認済(status=1) のみを集計したプレビューデータを返す。
     * 管理者のみ使用可能（canAdd ポリシーで管理者判定）。
     */
    public function exportMealSummaryPreview()
    {
        $this->Authorization->authorize($this->MMealPriceInfo->newEmptyEntity(), 'add');

        $this->autoRender = false;
        $apiResponse = new ApiResponseService();

        $year  = (int)$this->request->getQuery('year', date('Y'));
        $month = (int)$this->request->getQuery('month', date('n'));

        $result = $this->mealSummaryExportService->aggregatePreview($year, $month);

        return $apiResponse->success($this->response, $result);
    }
}
