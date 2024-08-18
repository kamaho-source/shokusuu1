<?php
declare(strict_types=1);

namespace App\Controller;


use Cake\Event\EventInterface;
use Cake\Http\Exception\NotFoundException;
use App\Controller\InvalidArgumentException;

/**
 * TReservationInfo Controller
 *
 * @property \App\Model\Table\TReservationInfoTable $TReservationInfo
 */
class TReservationInfoController extends AppController
{

    public function initialize(): void
    {
        parent::initialize();
        $this->fetchTable('TReservationInfo');
        $this->fetchTable('MRoomInfo');
        $this->viewBuilder()->setOption('serialize', true);
        $this->viewBuilder()->setLayout('default');
    }
    /**
     * Index method
     *
     * @return \Cake\Http\Response|null|void Renders view
     */

    public function index()
    {
        $mealData = $this->TReservationInfo->find()
            ->select([
                'd_reservation_date',
                'c_reservation_type',
                'i_taberu_ninzuu',
            ])
            ->toArray();

        // 連想配列を作成
        $mealDataArray = [];
        // 各日付について処理
        foreach ($mealData as $data) {
            $date = $data->d_reservation_date->format('Y-m-d');
            $mealType = $data->c_reservation_type; // 1: 朝, 2: 昼, 3: 夜

            // 日付ごとのデータが存在しなければ初期化
            if (!isset($mealDataArray[$date])) {
                $mealDataArray[$date] = [1 => 0, 2 => 0, 3 => 0];
            }

            // 食数をセット、値がnullの場合は0をセット

            switch ($mealType) {
                case 1:
                    $mealDataArray[$date][1] = is_numeric($data->i_taberu_ninzuu) ? $data->i_taberu_ninzuu : 0;
                    break;
                case 2:
                    $mealDataArray[$date][2] = is_numeric($data->i_taberu_ninzuu) ? $data->i_taberu_ninzuu : 0;
                    break;
                case 3:
                    $mealDataArray[$date][3] = is_numeric($data->i_taberu_ninzuu) ? $data->i_taberu_ninzuu : 0;
                    break;
            }
        }

        $this->set(compact('mealDataArray'));
   //∂    pr($mealDataArray);

    }




    /**
     * event method
     *
     */
    public function events()
    {
        $this->request->allowMethod(['get', 'ajax']);

        // 全ての予約情報を取得する
        $reservations = $this->TReservationInfo->find('all');

        // FullCalendarに渡す形式に変換
        $events = [];
        foreach ($reservations as $reservation) {
            $events[] = [
                'title' => '合計食数: ' . $reservation->i_taberu_ninzuu,
                'start' => $reservation->d_reservation_date->format('Y-m-d'),
                'allDay' => true
            ];
        }

        $this->set(compact('events'));
        $this->viewBuilder()->setOption('serialize', 'events');
    }



    /**
     * View method
     *
     * @param string|null $id T Reservation Info id.
     * @return \Cake\Http\Response|null|void Renders view
     * @throws \Cake\Datasource\Exception\RecordNotFoundException When record not found.
     */
    public function view()
    {
        // クエリパラメータから日付を取得
        $date = $this->request->getQuery('date');

        if ($date === null) {
            throw new \InvalidArgumentException('日付が指定されていません。');
        }

        // 選択された日付の予約データを取得し、関連する部屋名も含める
        $roomInfos = $this->TReservationInfo->find()
            ->contain(['MRoomInfo']) // MRoomInfoテーブルからc_room_nameを取得
            ->where(['d_reservation_date' => $date]) // クエリパラメータから取得した日付でフィルタリング
            ->all();

        // 連想配列を作成
        $groupedRoomInfos = [
            '朝' => [],
            '昼' => [],
            '夜' => []
        ];

        // データを予約タイプ（朝、昼、夜）でグループ化
        foreach ($roomInfos as $roomInfo) {
            $mealType = '';
            switch ($roomInfo->c_reservation_type) {
                case 1:
                    $mealType = '朝';
                    break;
                case 2:
                    $mealType = '昼';
                    break;
                case 3:
                    $mealType = '夜';
                    break;
            }

            if ($mealType) {
                // 部屋名を取得して連想配列に追加
                $groupedRoomInfos[$mealType][] = [
                    'room_name' => $roomInfo->m_room_info->c_room_name,
                    'reservation_type' => $mealType,
                    'taberu_ninzuu' => $roomInfo->i_taberu_ninzuu,
                    'tabenai_ninzuu' => $roomInfo->i_tabenai_ninzuu
                ];
            }
        }

        // ビューで使用するデータをセット
        $this->set(compact('groupedRoomInfos', 'date'));
    }




    /**
     * Add method
     *
     * @return \Cake\Http\Response|null|void Redirects on successful add, renders view otherwise.
     */

    public function add()
    {
        $tReservationInfo = $this->TReservationInfo->newEmptyEntity();

        if ($this->request->is('post')) {
            $data = $this->request->getData();

            // デバッグ: 送信されたデータを確認

            // URLのクエリパラメータから予約日を取得
            $reservationDate = $this->request->getQuery('date');

            // 予約日が空でないか確認し、エンティティに設定
            if (!empty($reservationDate)) {
                $tReservationInfo->d_reservation_date = $reservationDate;
            } else {
                $this->Flash->error(__('予約日が選択されていません。'));
                return $this->redirect(['action' => 'add']);
            }

            // i_id_room と c_reservation_type が設定されているか確認
            if (!empty($data['i_id_room']) && !empty($data['c_reservation_type'])) {
                $tReservationInfo->i_id_room = $data['i_id_room'];
                $tReservationInfo->c_reservation_type = $data['c_reservation_type'];
            } else {
                $this->Flash->error(__('部屋IDまたは予約タイプが選択されていません。'));
                return $this->redirect(['action' => 'add']);
            }


            // その他のフィールドをエンティティにパッチ
            $tReservationInfo = $this->TReservationInfo->patchEntity($tReservationInfo, $data);

            // データベースに保存
            if ($this->TReservationInfo->save($tReservationInfo)) {
                $this->Flash->success('予約を承りました。' );
                return $this->redirect(['action' => 'index']);
            }
            $this->Flash->error(__('The reservation could not be saved. Please, try again.'));
        }

        // 部屋情報を取得してビューに渡す
        $MRoomInfoTable = $this->fetchTable('MRoomInfo');
        $rooms = $MRoomInfoTable->find('list', [
            'keyField' => 'i_id_room',
            'valueField' => 'c_room_name'
        ])->toArray();

        $this->set(compact('tReservationInfo', 'rooms'));
    }




    /**
     * Edit method
     *
     * @param string|null $id T Reservation Info id.
     * @return \Cake\Http\Response|null|void Redirects on successful edit, renders view otherwise.
     */

    public function edit($id = null)
    {
        // まず `id` で予約情報を取得する
        if ($id !== null) {
            $tReservationInfo = $this->TReservationInfo->get($id);
        } else {
            // `id` がない場合、クエリパラメータから `date` を取得し、予約情報を取得する
            $reservationDate = $this->request->getQuery('date');

            if (!$reservationDate) {
                $this->Flash->error(__('Invalid reservation date.'));
                return $this->redirect(['action' => 'index']);
            }

            $tReservationInfo = $this->TReservationInfo->find()
                ->where(['d_reservation_date' => $reservationDate])
                ->first();

            if (!$tReservationInfo) {
                $this->Flash->error(__('Reservation not found.'));
                return $this->redirect(['action' => 'index']);
            }
        }

        // リクエストが POST または PUT の場合、データを保存する
        if ($this->request->is(['post', 'put'])) {
            $data = $this->request->getData();

            // データをパッチ
            $tReservationInfo = $this->TReservationInfo->patchEntity($tReservationInfo, $data);

            // データベースに保存
            if ($this->TReservationInfo->save($tReservationInfo)) {
                $this->Flash->success(__('The reservation has been updated.'));
                return $this->redirect(['action' => 'index']);
            }
            $this->Flash->error(__('The reservation could not be updated. Please, try again.'));
        }

        // 部屋情報を取得してビューに渡す
        $MRoomInfoTable = $this->fetchTable('MRoomInfo');
        $rooms = $MRoomInfoTable->find('list', [
            'keyField' => 'i_id_room',
            'valueField' => 'c_room_name'
        ])->toArray();

        $this->set(compact('tReservationInfo', 'rooms'));
    }

    /**
     * Delete method
     *
     * @param string|null $id T Reservation Info id.
     * @return \Cake\Http\Response|null Redirects to index.
     * @throws \Cake\Datasource\Exception\RecordNotFoundException When record not found.
     */
    public function delete($id = null)
    {
        $this->request->allowMethod(['post', 'delete']);
        $tReservationInfo = $this->TReservationInfo->get($id);
        if ($this->TReservationInfo->delete($tReservationInfo)) {
            $this->Flash->success(__('The t reservation info has been deleted.'));
        } else {
            $this->Flash->error(__('The t reservation info could not be deleted. Please, try again.'));
        }

        return $this->redirect(['action' => 'index']);
    }
}
