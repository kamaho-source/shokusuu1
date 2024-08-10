<?php
declare(strict_types=1);

namespace App\Controller;

use Cake\Event\EventInterface;


/**
 * MRoomInfo Controller
 *
 * @property \App\Model\Table\MRoomInfoTable $MRoomInfo
 */
class MRoomInfoController extends AppController
{

    public function initialize(): void
    {
        parent::initialize();
        $this->loadComponent('Authentication.Authentication');
    }
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
        date_default_timezone_set('Asia/Tokyo');
        $mRoomInfo = $this->MRoomInfo->newEmptyEntity();

        // set defaults
        $mRoomInfo->i_del_flag = 0;
        $mRoomInfo->i_enable = 0;
        $identity = $this->Authentication->getIdentity();
        if($identity) {
            $mRoomInfo->c_create_user = $identity->username;
            $mRoomInfo->c_update_user = $identity->username;
            \Cake\Log\Log::debug("Authenticated user: " . print_r($identity, true));
        } else {
            \Cake\Log\Log::debug("No authenticated user");
        }
        $mRoomInfo->dt_create = date('Y-m-d H:i:s');

        if ($this->request->is('post')) {
            $mRoomInfo = $this->MRoomInfo->patchEntity($mRoomInfo, $this->request->getData());

            if ($this->MRoomInfo->save($mRoomInfo)) {
                $this->Flash->success(__('The m room info has been saved.'));
                return $this->redirect(['action' => 'index']);
            }

            \Cake\Log\Log::debug("Failed to save m room info: " . print_r($mRoomInfo->getErrors(), true));
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
        date_default_timezone_set('Asia/Tokyo');
        $mRoomInfo =
        $mRoomInfo = $this->MRoomInfo->get($id, contain: []);
        if ($this->request->is(['patch', 'post', 'put'])) {
            $mRoomInfo = $this->MRoomInfo->patchEntity($mRoomInfo, $this->request->getData());
            $mRoomInfo->dt_update = date('Y-m-d H:i:s',);
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
        $mRoomInfo->i_del_flag = 1;
        if ($this->MRoomInfo->delete($mRoomInfo)) {
            $this->Flash->success(__('The m room info has been deleted.'));
        } else {
            $this->Flash->error(__('The m room info could not be deleted. Please, try again.'));
        }

        return $this->redirect(['action' => 'index']);
    }
}
