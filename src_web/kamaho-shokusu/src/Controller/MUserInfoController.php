<?php
declare(strict_types=1);

namespace App\Controller;

use AllowDynamicProperties;
use Cake\Event\EventInterface;
use Cake\Log\Log;
use Cake\ORM\TableRegistry;
use InvalidArgumentException;
use Cake\Datasource\ConnectionManager;

class MUserInfoController extends AppController
{
    protected $MUserGroup;
    protected $MUserInfo;
    protected $MRoomInfo;

    public function initialize(): void
    {
        parent::initialize();
        $this->loadComponent('Authentication.Authentication');
        $this->viewBuilder()->setLayout('default');
        $this->viewBuilder()->setOption('serialize', true);

        $this->MUserGroup = $this->fetchTable('MUserGroup');
        $this->MUserInfo = $this->fetchTable('MUserInfo');
        $this->MRoomInfo = $this->fetchTable('MRoomInfo');
    }

    public function beforeFilter(EventInterface $event)
    {
        parent::beforeFilter($event);
        $this->Authentication->addUnauthenticatedActions(['login', 'add']);
    }

    public function index()
    {
        $query = $this->MUserInfo->find()
            ->where(['i_del_flag' => 0])
            ->contain(['MUserGroup' => ['MRoomInfo']]);

        $mUserInfo = $this->paginate($query);
        $userRooms = [];

        foreach ($mUserInfo as $user) {
            if (!empty($user->m_user_group)) {
                foreach ($user->m_user_group as $group) {
                    if (!empty($group->m_room_info)) {
                        $userRooms[$user->i_id_user][] = $group->m_room_info->c_room_name;
                    }
                }
            } else {
                $userRooms[$user->i_id_user] = [];
            }
        }

        $this->set(compact('mUserInfo', 'userRooms'));
    }

    public function getUserRooms($userId)
    {
        if ($userId === null) {
            return ['未所属'];
        }

        $userRooms = $this->MUserGroup->find()
            ->where(['MUserGroup.i_id_user' => $userId, 'MUserGroup.active_flag' => 0])
            ->contain(['MRoomInfo'])
            ->all();

        if ($userRooms->isEmpty()) {
            return ['未所属'];
        }

        $rooms = [];
        foreach ($userRooms as $userRoom) {
            $rooms[] = $userRoom->m_room_info->c_room_name;
        }

        return $rooms;
    }

    public function add()
    {
        date_default_timezone_set('Asia/Tokyo');

        $mUserInfo = $this->MUserInfo->newEmptyEntity();
        $mUserInfo->i_del_flag = 0;
        $mUserInfo->dt_create = date('Y-m-d H:i:s');
        $mUserInfo->i_disp_no = max($this->MUserInfo->find()->select(['max_disp_no' => 'MAX(i_disp_no)'])->first()->max_disp_no + 1, 1);

        if ($this->request->is('post')) {
            // リクエストデータを取得
            $data = $this->request->getData();

            // c_user_nameのデフォルト値設定
            if (empty($data['c_user_name'])) {
                $data['c_user_name'] = 'デフォルトユーザー名';
            }

            // c_create_user設定
            $user = $this->request->getAttribute('identity');
            $data['c_create_user'] = $user ? $user->get('c_user_name') : '不明なユーザー';

            // 部屋情報の処理
            if (!empty($data['MUserGroup'])) {
                foreach ($data['MUserGroup'] as $index => $groupData) {
                    if (empty($groupData['i_id_room']) || $groupData['i_id_room'] == '0') {
                        unset($data['MUserGroup'][$index]);
                    }
                }
            }

            // エンティティにデータをパッチ
            $mUserInfo = $this->MUserInfo->patchEntity($mUserInfo, $data, [
                'associated' => ['MUserGroup']
            ]);

            // エラーログを確認
            if ($mUserInfo->hasErrors()) {
                $errors = $mUserInfo->getErrors();
                Log::debug('Validation Errors: ' . json_encode($errors));
            }

            // データ保存
            if ($this->MUserInfo->save($mUserInfo)) {
                $this->Flash->success(__('ユーザー情報が保存されました。'));
                return $this->redirect(['action' => 'index']);
            } else {
                $this->Flash->error(__('ユーザー情報の保存に失敗しました。もう一度お試しください。'));
                Log::debug('Failed to save user info: ' . json_encode($mUserInfo->getErrors()));
            }
        }

        $rooms = $this->MRoomInfo->find('list', [
            'keyField' => 'i_id_room',
            'valueField' => 'c_room_name'
        ])->toArray();

        // 年齢と役職のオプションをビューに渡す
        $ages = range(1, 80); // 年齢の範囲
        $roles = [0 => '職員', 1 => '児童', 3 => 'その他']; // 役職の選択肢

        $this->set(compact('mUserInfo', 'rooms', 'ages', 'roles'));
    }



    public function edit($id = null)
    {
        date_default_timezone_set('Asia/Tokyo');

        $mUserInfo = $this->MUserInfo->get($id, [
            'contain' => ['MUserGroup']
        ]);

        if ($this->request->is(['patch', 'post', 'put'])) {
            $data = $this->request->getData();
            pr($data);
            // フィールド名の修正とnullチェック (c_user_nameがnullの場合のデフォルト値を設定)
            $data['c_user_name'] = $data['c_user_name'] ?? 'デフォルトユーザー名';

            $user = $this->request->getAttribute('identity');
            $data['c_update_user'] = $user ? $user->get('c_user_name') : '不明なユーザー';

            if (!empty($data['MUserGroup'])) {
                $this->MUserInfo->MUserGroup->deleteAll(['i_id_user' => $id]);

                $newUserGroups = [];
                foreach ($data['MUserGroup'] as $groupData) {
                    if (!empty($groupData['i_id_room'])) {
                        $newUserGroups[] = $this->MUserGroup->newEntity([
                            'i_id_user' => $id,
                            'i_id_room' => $groupData['i_id_room']
                        ]);
                    }
                }
                $data['MUserGroup'] = $newUserGroups;
            } else {
                $data['MUserGroup'] = [];
            }

            $mUserInfo = $this->MUserInfo->patchEntity($mUserInfo, $data, [
                'associated' => ['MUserGroup']
            ]);

            if ($this->MUserInfo->save($mUserInfo, ['associated' => ['MUserGroup']])) {
                $this->Flash->success(__('ユーザー情報が更新されました。'));
                return $this->redirect(['action' => 'index']);
            } else {
                $this->Flash->error(__('ユーザー情報の更新に失敗しました。もう一度お試しください。'));
            }
        }

        $rooms = $this->MRoomInfo->find('list', keyField: 'i_id_room', valueField: 'c_room_name')->toArray();

        $selectedRooms = [];
        if (!empty($mUserInfo->m_user_group)) {
            foreach ($mUserInfo->m_user_group as $group) {
                $selectedRooms[] = $group->i_id_room;
            }
        }

        $this->set(compact('mUserInfo', 'rooms', 'selectedRooms'));
    }

    public function view($id = null)
    {
        $mUserInfo = $this->MUserInfo->get($id, [
            'contain' => ['MUserGroup' => ['MRoomInfo']]
        ]);

        $userRooms = [];
        if (!empty($mUserInfo->m_user_group)) {
            foreach ($mUserInfo->m_user_group as $group) {
                $userRooms[] = $group->m_room_info->c_room_name;
            }
        }

        $this->set(compact('mUserInfo', 'userRooms'));
    }

    public function delete($id = null)
    {
        date_default_timezone_set('Asia/Tokyo');
        $this->request->allowMethod(['post', 'delete']);
        $mUserInfo = $this->MUserInfo->get($id);
        $mUserInfo->i_del_flag = 1;
        $mUserInfo->dt_update = date('Y-m-d H:i:s');
        $user = $this->request->getAttribute('identity');
        $mUserInfo->c_update_user = $user ? $user->get('c_user_name') : '不明なユーザー';

        if ($this->MUserInfo->save($mUserInfo)) {
            $this->Flash->success(__('ユーザー情報が削除されました。'));
        } else {
            $this->Flash->error(__('ユーザー情報を削除できませんでした。'));
        }

        return $this->redirect(['action' => 'index']);
    }

    public function addRoomToUser($userId)
    {
        $user = $this->request->getAttribute('identity');
        $createUser = $user ? $user->get('c_user_name') : '不明なユーザー';

        if ($this->request->is('post')) {
            $roomId = $this->request->getData('i_id_room');
            if ($this->MUserInfo->saveUserRoom($userId, $roomId, $createUser)) {
                $this->Flash->success(__('ユーザーに部屋が追加されました。'));
                return $this->redirect(['action' => 'view', $userId]);
            }
            $this->Flash->error(__('部屋の追加に失敗しました。'));
        }
    }

    public function removeRoomFromUser($userId, $roomId)
    {
        if ($this->MUserInfo->deleteUserRoom($userId, $roomId)) {
            $this->Flash->success(__('部屋の関連が削除されました。'));
        } else {
            $this->Flash->error(__('部屋の関連を削除できませんでした。'));
        }

        return $this->redirect(['action' => 'view', $userId]);
    }

    public function login()
    {
        $this->request->allowMethod(['get', 'post']);
        $result = $this->Authentication->getResult();
        if ($result && $result->isValid()) {
            $redirect = $this->request->getQuery('redirect', [
                'controller' => 'TReservationInfo',
                'action' => 'index',
            ]);
            return $this->redirect($redirect);
        }

        if ($this->request->is('post') && !$result->isValid()) {
            $this->Flash->error(__('ユーザー名またはパスワードが正しくありません。'));
            return $this->redirect(['controller' => 'MUserInfo', 'action' => 'login']);
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
