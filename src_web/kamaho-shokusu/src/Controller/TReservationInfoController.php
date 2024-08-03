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

    /**
     * View method
     *
     * @param string|null $id T Reservation Info id.
     * @return \Cake\Http\Response|null|void Renders view
     * @throws \Cake\Datasource\Exception\RecordNotFoundException When record not found.
     */
    public function view($id = null)
    {
        $tReservationInfo = $this->TReservationInfo->get($id, contain: []);
        $this->set(compact('tReservationInfo'));
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
            $tReservationInfo = $this->TReservationInfo->patchEntity($tReservationInfo, $this->request->getData());
            if ($this->TReservationInfo->save($tReservationInfo)) {
                $this->Flash->success(__('The t reservation info has been saved.'));

                return $this->redirect(['action' => 'index']);
            }
            $this->Flash->error(__('The t reservation info could not be saved. Please, try again.'));
        }
        $this->set(compact('tReservationInfo'));
    }

    /**
     * Edit method
     *
     * @param string|null $id T Reservation Info id.
     * @return \Cake\Http\Response|null|void Redirects on successful edit, renders view otherwise.
     * @throws \Cake\Datasource\Exception\RecordNotFoundException When record not found.
     */
    public function edit($id = null)
    {
        $tReservationInfo = $this->TReservationInfo->get($id, contain: []);
        if ($this->request->is(['patch', 'post', 'put'])) {
            $tReservationInfo = $this->TReservationInfo->patchEntity($tReservationInfo, $this->request->getData());
            if ($this->TReservationInfo->save($tReservationInfo)) {
                $this->Flash->success(__('The t reservation info has been saved.'));

                return $this->redirect(['action' => 'index']);
            }
            $this->Flash->error(__('The t reservation info could not be saved. Please, try again.'));
        }
        $this->set(compact('tReservationInfo'));
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
