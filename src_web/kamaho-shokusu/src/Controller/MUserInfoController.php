<?php
declare(strict_types=1);

namespace App\Controller;

use Cake\Event\EventInterface;

/**
 * MUserInfo Controller
 *
 * @property \App\Model\Table\MUserInfoTable $MUserInfo
 */
class MUserInfoController extends AppController
{
    public function initialize(): void
    {
        parent::initialize();
        $this->loadComponent('Authentication.Authentication');
        $this->viewBuilder()->setLayout('default');
        $this->viewBuilder()->setOption('serialize', true);
    }

    public function beforeFilter(EventInterface $event)
    {
        parent::beforeFilter($event);
        $this->Authentication->addUnauthenticatedActions(['login', 'add']);

    }
    /**
     * Index method
     *
     * @return \Cake\Http\Response|null|void Renders view
     */
    public function index()
    {
        $query = $this->MUserInfo->find()->where(['i_del_flag' => 0]);
        $mUserInfo = $this->paginate($query);

        $this->set(compact('mUserInfo'));
    }

    /**
     * View method
     *
     * @param string|null $id M User Info id.
     * @return \Cake\Http\Response|null|void Renders view
     * @throws \Cake\Datasource\Exception\RecordNotFoundException When record not found.
     */
    public function view($id = null)
    {
        $mUserInfo = $this->MUserInfo->get($id, contain: []);
        $this->set(compact('mUserInfo'));
    }

    /**
     * Add method
     *
     * @return \Cake\Http\Response|null|void Redirects on successful add, renders view otherwise.
     */
    public function add() {
        $mUserInfo = $this->MUserInfo->newEmptyEntity();
        $mUserInfo->i_del_flag = 0;
        $mUserInfo->dt_create = date('Y-m-d H:i:s');
        if ($this->request->is('post')) {
            $mUserInfo = $this->MUserInfo->patchEntity($mUserInfo, $this->request->getData());
            if ($this->MUserInfo->save($mUserInfo)) {
                $this->Flash->success(__('The m user info has been saved.'));
                return $this->redirect(['action' => 'index']);
            }
            $this->Flash->error(__('The m user info could not be saved. Please, try again.'));
        }
        $this->set(compact('mUserInfo'));
    }

    /**
     * Edit method
     *
     * @param string|null $id M User Info id.
     * @return \Cake\Http\Response|null|void Redirects on successful edit, renders view otherwise.
     * @throws \Cake\Datasource\Exception\RecordNotFoundException When record not found.
     */
    public function edit($id = null)
    {


        $mUserInfo = $this->MUserInfo->get($id, contain: []);
        if ($this->request->is(['patch', 'post', 'put'])) {
            $mUserInfo = $this->MUserInfo->patchEntity($mUserInfo, $this->request->getData());
            if ($this->MUserInfo->save($mUserInfo)) {
                $this->Flash->success(__('The m user info has been saved.'));

                return $this->redirect(['action' => 'index']);
            }
            $this->Flash->error(__('The m user info could not be saved. Please, try again.'));
        }
        $this->set(compact('mUserInfo'));
    }

    /**
     * Delete method
     *
     * @param string|null $id M User Info id.
     * @return \Cake\Http\Response|null Redirects to index.
     * @throws \Cake\Datasource\Exception\RecordNotFoundException When record not found.
     */
    public function delete($id = null)
    {
        $this->request->allowMethod(['post', 'delete']);
        $mUserInfo = $this->MUserInfo->get($id);

        // Set the 'i_del_flag' to 1
        $mUserInfo->i_del_flag = 1;

        if ($this->MUserInfo->save($mUserInfo)) {
            $this->Flash->success(__('The m user info has been deleted.'));
        } else {
            $this->Flash->error(__('The m user info could not be deleted. Please, try again.'));
        }

        return $this->redirect(['action' => 'index']);
    }


    public function login()
    {
        $this->request->allowMethod(['get', 'post']);
        $result = $this->Authentication->getResult();
        // regardless of POST or GET, redirect if user is logged in
        if ($result && $result->isValid()) {
            // redirect to /articles after login success
            $redirect = $this->request->getQuery('redirect', [
                'controller' => 'TReservationInfo',
                'action' => 'index',
            ]);

            return $this->redirect($redirect);
        }
        // display error if user submitted and authentication failed
        if ($this->request->is('post') && !$result->isValid()) {
            $this->Flash->error(__('Invalid username or password'));
        }
    }

    public function logout()
    {
        $result = $this->Authentication->getResult();
        if ($result->isValid()) {
            $this->Authentication->logout();
            $this->Flash->success('正常にログアウトされました。');
        }
        return $this->redirect(['controller' => 'MUserInfo', 'action' => 'login']);
    }





}
