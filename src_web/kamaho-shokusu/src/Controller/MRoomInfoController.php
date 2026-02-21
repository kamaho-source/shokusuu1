<?php
declare(strict_types=1);

namespace App\Controller;

use Authorization\Exception\ForbiddenException;
use Cake\Event\EventInterface;

/**
 * MRoomInfo Controller
 *
 * @property \App\Model\Table\MRoomInfoTable $MRoomInfo
 */
class MRoomInfoController extends AppController
{
    public $MUserGroup;
    public $MUserInfo;

    public function initialize(): void
    {
        parent::initialize();
        $this->loadComponent('Authentication.Authentication');
        $this->viewBuilder()->setOption('serialize', true);
        $this->viewBuilder()->setLayout('default');

       $this->MUserGroup = $this->fetchTable('MUserGroup');
       $this->MUserInfo = $this->fetchTable('MUserInfo');
    }

    /**
     * Index method
     *
     * @return \Cake\Http\Response|null|void Renders view
     */
    public function index()
    {
        $resource = $this->MRoomInfo->newEmptyEntity();
        try {
            $this->Authorization->authorize($resource, 'index');
        } catch (ForbiddenException $e) {
            $this->Flash->error(__('あなたは閲覧権限がありません。'));
            return $this->redirect(['controller' => 'MUserInfo', 'action' => 'login']);
        }

        $query = $this->MRoomInfo->find()->where(['i_del_flg' => 0]);
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
        // ネームドアーギュメントを使用して部屋情報を取得しつつ、関連するユーザーグループを結合して取得
        $mRoomInfo = $this->MRoomInfo->get($id, ['contain' => ['MUserGroup']]);
        try {
            $this->Authorization->authorize($mRoomInfo, 'view');
        } catch (ForbiddenException $e) {
            $this->Flash->error(__('あなたは閲覧権限がありません。'));
            return $this->redirect(['action' => 'index']);
        }

        // MUserGroupが存在するかどうかをチェックし、存在しない場合は空の配列を使用
        $userGroups = $mRoomInfo->m_user_group ? $mRoomInfo->m_user_group : [];

        // ユーザーIDのリストを作成
        $userIds = array_map(function($group) {
            return $group->i_id_user;
        }, $userGroups);

        // 空のリストを渡さないよう、安全確保
        if (!empty($userIds)) {
            $users = $this->MUserInfo->find('all', [
                'conditions' => ['MUserInfo.i_id_user IN' => $userIds],
            ])->toArray();
        } else {
            $users = [];
        }

        $this->set(compact('mRoomInfo', 'users'));
    }
    /**
     * Add method
     *
     * @return \Cake\Http\Response|null|void Redirects on successful add, renders view otherwise.
     */
    public function add()
    {
        $this->request->allowMethod(['get', 'post']);
        $mRoomInfo = $this->MRoomInfo->newEmptyEntity();
        try {
            $this->Authorization->authorize($mRoomInfo, 'add');
        } catch (ForbiddenException $e) {
            $this->Flash->error(__('あなたは追加権限がありません。'));
            return $this->redirect(['action' => 'index']);
        }

        if ($this->request->is('post')) {
            $data = $this->request->getData();
            $mRoomInfo->dt_create = date('Y-m-d H:i:s');
            $user = $this->request->getAttribute('identity');
            if ($user) {
                $mRoomInfo->c_create_user = $user->get('c_user_name');
            }
            $mRoomInfo->i_del_flg = 0;
            $maxDispNo = (int)($this->MRoomInfo->find()
                ->select(['max_disp_no' => 'MAX(i_disp_no)'])
                ->first()
                ?->max_disp_no ?? 0);
            $mRoomInfo->i_disp_no = $maxDispNo + 1;

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
        $this->request->allowMethod(['get', 'post', 'put', 'patch']);
        $mRoomInfo = $this->MRoomInfo->get($id);
        try {
            $this->Authorization->authorize($mRoomInfo, 'edit');
        } catch (ForbiddenException $e) {
            $this->Flash->error(__('あなたは編集権限がありません。'));
            return $this->redirect(['action' => 'index']);
        }

        if ($this->request->is(['post', 'put', 'patch'])) {
            $data = $this->request->getData();
            $mRoomInfo->dt_update = date('Y-m-d H:i:s');
            $user = $this->request->getAttribute('identity');
            if ($user) {
                $mRoomInfo->c_update_user = $user->get('c_user_name');
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
        try {
            $this->Authorization->authorize($mRoomInfo, 'delete');
        } catch (ForbiddenException $e) {
            $this->Flash->error(__('あなたは削除権限がありません。'));
            return $this->redirect(['action' => 'index']);
        }
        $mRoomInfo->i_del_flg = 1;
        $user = $this->request->getAttribute('identity');
        if($user) {
            $mRoomInfo->c_update_user = $user->get('c_user_name');
        }
        $mRoomInfo->dt_update = date('Y-m-d H:i:s');

        if ($this->MRoomInfo->save($mRoomInfo)) {
            $this->Flash->success(__('部屋情報を削除しました。'));
        } else {
            $this->Flash->error(__('部屋情報を削除できませんでした。もう一度お試しください。'));
        }

        return $this->redirect(['action' => 'index']);
    }
}
