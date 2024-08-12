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

    public function add()
    {
        $tReservationInfo = $this->TReservationInfo->newEmptyEntity();
        if ($this->request->is('post')) {
            $data = $this->request->getData();
            debug($data); // 送信されたデータを確認するためのデバッグ出力

            // 主キーがすべて存在しているか確認
            if (empty($data['d_reservation_date']) || empty($data['i_id_room']) || empty($data['c_reservation_type'])) {
                $this->Flash->error(__('予約日、部屋ID、または予約タイプが選択されていません。'));
            } else {
                $tReservationInfo = $this->TReservationInfo->patchEntity($tReservationInfo, $data);
                if ($this->TReservationInfo->save($tReservationInfo)) {
                    $this->Flash->success(__('The reservation has been saved.'));
                    return $this->redirect(['action' => 'index']);
                }
                $this->Flash->error(__('The reservation could not be saved. Please, try again.'));
            }
        }

        $MRoomInfoTable = $this->fetchTable('MRoomInfo');
        $rooms = $MRoomInfoTable->find('list', [
            'keyField' => 'i_id_room',
            'valueField' => 'c_room_name'
        ])->toArray();

        $this->set(compact('tReservationInfo', 'rooms'));
    }




    /**
     * Add method
     *
     * @return \Cake\Http\Response|null|void Redirects on successful add, renders view otherwise.
     */



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
