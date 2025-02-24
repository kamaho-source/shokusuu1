<?php
declare(strict_types=1);

namespace App\Controller;

/**
 * MMealPriceInfo Controller
 *
 */
class MMealPriceInfoController extends AppController
{
    protected $MMealPriceInfo;
    protected $TIndividualReservationInfo;
    protected $MUserInfo;

    public function initialize(): void
    {
        parent::initialize();
        $this ->MMealPriceInfo = $this->fetchTable('MMealPriceInfo');
        $this->TIndividualReservationInfo = $this->fetchTable('TIndividualReservationInfo');
        $this->MUserInfo = $this->fetchTable('MUserInfo');




    }
    /**
     * Index method
     *
     * @return \Cake\Http\Response|null|void Renders view
     */
    public function index()
    {
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
        $this->set(compact('mMealPriceInfo'));
    }

    /**
     * Add method
     *
     * @return \Cake\Http\Response|null|void Redirects on successful add, renders view otherwise.
     */
    public function add()
    {
        $mMealPriceInfo = $this->MMealPriceInfo->newEmptyEntity();
        if ($this->request->is('post')) {
            $mMealPriceInfo = $this->MMealPriceInfo->patchEntity($mMealPriceInfo, $this->request->getData());
            if ($this->MMealPriceInfo->save($mMealPriceInfo)) {
                $this->Flash->success(__('The m meal price info has been saved.'));

                return $this->redirect(['action' => 'index']);
            }
            $this->Flash->error(__('The m meal price info could not be saved. Please, try again.'));
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
        $mMealPriceInfo = $this->MMealPriceInfo->get($id, contain: []);
        if ($this->request->is(['patch', 'post', 'put'])) {
            $mMealPriceInfo = $this->MMealPriceInfo->patchEntity($mMealPriceInfo, $this->request->getData());
            if ($this->MMealPriceInfo->save($mMealPriceInfo)) {
                $this->Flash->success(__('The m meal price info has been saved.'));

                return $this->redirect(['action' => 'index']);
            }
            $this->Flash->error(__('The m meal price info could not be saved. Please, try again.'));
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
        if ($this->MMealPriceInfo->delete($mMealPriceInfo)) {
            $this->Flash->success(__('The m meal price info has been deleted.'));
        } else {
            $this->Flash->error(__('The m meal price info could not be deleted. Please, try again.'));
        }

        return $this->redirect(['action' => 'index']);
    }



    public function getMealSummary()
    {

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
        $this->autoRender = false;

        // リクエストから年度と月を取得
        $year = $this->request->getQuery('year', date('Y'));
        $month = $this->request->getQuery('month', date('n')); // 月が指定されていなければ現在の月をデフォルトとする

        // 食事単価を取得 (年度単位)
        $mealPricesData = $this->MMealPriceInfo->find()
            ->select(['i_morning_price', 'i_lunch_price', 'i_dinner_price', 'i_bento_price'])
            ->where(['i_fiscal_year' => $year])
            ->first();

        $mealPrices = [
            'morning' => $mealPricesData->i_morning_price ?? 0,
            'lunch'   => $mealPricesData->i_lunch_price ?? 0,
            'dinner'  => $mealPricesData->i_dinner_price ?? 0,
            'bento'   => $mealPricesData->i_bento_price ?? 0,
        ];

        // 職員IDが存在するユーザーを取得
        $users = $this->MUserInfo->find()
            ->select(['i_id_user', 'c_user_name', 'i_id_staff'])
            ->where(['i_id_staff IS NOT' => null]) // 職員IDが null でないユーザー
            ->all();

        // 該当月のデータを収集
        $monthlyData = [];

        foreach ($users as $user) {
            // 食事の回数を初期化
            $mealCounts = [
                'bento'   => 0,
                'morning' => 0,
                'lunch'   => 0,
                'dinner'  => 0,
            ];

            // 該当ユーザーの食事の回数を集計
            $reservationData = $this->TIndividualReservationInfo->find()
                ->select(['i_reservation_type', 'count' => 'COUNT(*)'])
                ->where([
                    'i_id_user' => $user->i_id_user,
                    'YEAR(d_reservation_date)' => $year,
                    'MONTH(d_reservation_date)' => $month,
                ])
                ->group(['i_reservation_type'])
                ->toArray();

            foreach ($reservationData as $data) {
                // 各タイプの食事数を設定
                if ($data->i_reservation_type === 4) {
                    $mealCounts['bento'] = $data->count;
                } elseif ($data->i_reservation_type === 1) {
                    $mealCounts['morning'] = $data->count;
                } elseif ($data->i_reservation_type === 2) {
                    $mealCounts['lunch'] = $data->count;
                } elseif ($data->i_reservation_type === 3) {
                    $mealCounts['dinner'] = $data->count;
                }
            }

            // 食事料金の計算
            $mealTotalPrice = (
                $mealCounts['bento'] * $mealPrices['bento'] +
                $mealCounts['morning'] * $mealPrices['morning'] +
                $mealCounts['lunch'] * $mealPrices['lunch'] +
                $mealCounts['dinner'] * $mealPrices['dinner']
            );

            // 結果を格納
            $monthlyData[] = [
                'name' => $user->c_user_name,
                'staff_id' => $user->i_id_staff,
                'meal_counts' => $mealCounts,
                'total_price' => $mealTotalPrice,
            ];
        }

        // JSON形式で該当月のデータのみをレスポンスとして返す
        $this->response = $this->response
            ->withType('application/json')
            ->withStringBody(json_encode($monthlyData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        return $this->response;
    }


}
