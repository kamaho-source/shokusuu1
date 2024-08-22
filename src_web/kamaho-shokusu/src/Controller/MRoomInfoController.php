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
            $data = $this->request->getData();
            $mRoomInfo->dt_create = date('Y-m-d H:i:s');
            $user = $this->request->getAttribute('identity');
            if ($user) {
                $mRoomInfo->c_create_user = $user->get('c__user_name');
            }

            $mRoomInfo = $this->MRoomInfo->patchEntity($mRoomInfo, $data);

            if ($this->MRoomInfo->save($mRoomInfo)) {
                $this->Flash->success(__('部屋情報が正常に追加されました。'));
                return $this->redirect(['action' => 'index']);
            }
            $this->Flash->error(__('部屋情報を保存できませんでした。もう一度お試しください。'));
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
        $mRoomInfo = $this->MRoomInfo->get($id);

        if ($this->request->is(['post', 'put'])) {
            $data = $this->request->getData();
            $mRoomInfo->dt_update = date('Y-m-d H:i:s');
            $user = $this->request->getAttribute('identity');
            if ($user) {
                $mRoomInfo->c_update_user = $user->get('c__user_name');
            }

            $mRoomInfo = $this->MRoomInfo->patchEntity($mRoomInfo, $data);

            if ($this->MRoomInfo->save($mRoomInfo)) {
                $this->Flash->success(__('部屋情報が正常に更新されました。'));
                return $this->redirect(['action' => 'index']);
            }
            $this->Flash->error(__('部屋情報を更新できませんでした。もう一度お試しください。'));
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
