<?php
declare(strict_types=1);

namespace App\Controller;

/**
 * MRoomInfo Controller
 *
 * @property \App\Model\Table\MRoomInfoTable $MRoomInfo
 */
class MRoomInfoController extends AppController
{
    /**
     * Index method
     *
     * @return \Cake\Http\Response|null|void Renders view
     */
    public function index()
    {
        $query = $this->MRoomInfo->find();
        $mRoomInfo = $this->paginate($query);

        $this->set(compact('mRoomInfo'));
    }

    /**
     * View method
     *
     * @param string|null $id M Room Info id.
     * @return \Cake\Http\Response|null|void Renders view
     * @throws \Cake\Datasource\Exception\RecordNotFoundException When record not found.
     */
    public function view($id = null)
    {
        $mRoomInfo = $this->MRoomInfo->get($id, contain: []);
        $this->set(compact('mRoomInfo'));
    }

    /**
     * Add method
     *
     * @return \Cake\Http\Response|null|void Redirects on successful add, renders view otherwise.
     */
    public function add()
    {
        $mRoomInfo = $this->MRoomInfo->newEmptyEntity();
        if ($this->request->is('post')) {
            $mRoomInfo = $this->MRoomInfo->patchEntity($mRoomInfo, $this->request->getData());
            if ($this->MRoomInfo->save($mRoomInfo)) {
                $this->Flash->success(__('The m room info has been saved.'));

                return $this->redirect(['action' => 'index']);
            }
            $this->Flash->error(__('The m room info could not be saved. Please, try again.'));
        }
        $this->set(compact('mRoomInfo'));
    }

    /**
     * Edit method
     *
     * @param string|null $id M Room Info id.
     * @return \Cake\Http\Response|null|void Redirects on successful edit, renders view otherwise.
     * @throws \Cake\Datasource\Exception\RecordNotFoundException When record not found.
     */
    public function edit($id = null)
    {
        $mRoomInfo = $this->MRoomInfo->get($id, contain: []);
        if ($this->request->is(['patch', 'post', 'put'])) {
            $mRoomInfo = $this->MRoomInfo->patchEntity($mRoomInfo, $this->request->getData());
            if ($this->MRoomInfo->save($mRoomInfo)) {
                $this->Flash->success(__('The m room info has been saved.'));

                return $this->redirect(['action' => 'index']);
            }
            $this->Flash->error(__('The m room info could not be saved. Please, try again.'));
        }
        $this->set(compact('mRoomInfo'));
    }

    /**
     * Delete method
     *
     * @param string|null $id M Room Info id.
     * @return \Cake\Http\Response|null Redirects to index.
     * @throws \Cake\Datasource\Exception\RecordNotFoundException When record not found.
     */
    public function delete($id = null)
    {
        $this->request->allowMethod(['post', 'delete']);
        $mRoomInfo = $this->MRoomInfo->get($id);
        if ($this->MRoomInfo->delete($mRoomInfo)) {
            $this->Flash->success(__('The m room info has been deleted.'));
        } else {
            $this->Flash->error(__('The m room info could not be deleted. Please, try again.'));
        }

        return $this->redirect(['action' => 'index']);
    }
}
