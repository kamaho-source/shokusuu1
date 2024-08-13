<?php
declare(strict_types=1);

namespace App\Controller;

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
        $this->viewBuilder()->setOption('serialize', true);
    }
    /**
     * Index method
     *
     * @return \Cake\Http\Response|null|void Renders view
     */
    public function index()
    {
        $query = $this->TReservationInfo->find();
        $tReservationInfo = $this->paginate($query);

        $this->set(compact('tReservationInfo'));
    }

    public function events()
    {

        $reservations = $this->TReservationInfo->getTotalMealsByDate()->toArray();
        $formattedEvents = [];
        foreach ($reservations as $reservation) {
            $formattedEvents[] = [
                'title' => '総食数: ' . $reservation->total_meals . '食',
                'start' => $reservation->reservation_date->format('Y-m-d'),
                'url' => '/t_reservation_info/view/' . $reservation->reservation_date->format('Y-m-d')
            ];
        }

        $this->set(compact('formattedEvents'));

    }

    /**
     * View method
     *
     * @param string|null $id T Reservation Info id.
     * @return \Cake\Http\Response|null|void Renders view
     * @throws \Cake\Datasource\Exception\RecordNotFoundException When record not found.
     */
    public function view($date = null)
    {
        if($date != null){
            $orderQuantities = $this->TReservationInfo->find('all', [
                'conditions' => ['d_reservation_date' => $date]
            ]);

            $totalQuantity = 0;
            foreach ($orderQuantities as $record) {
                $totalQuantity += $record->i_taberu_ninzuu;
            }
        }
        else{
            $totalQuantity = 0;
        }

        $this->set(compact('date', 'totalQuantity'));
        $this->viewBuilder()->setOption('serialize', ['date', 'totalQuantity']);
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
                $this->Flash->success(__('The reservation has been saved.'));
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
