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

    // src/Controller/MUserInfoController.php

    // src/Controller/MUserInfoController.php

    private function castGroupData(array $groupData): array {
        return array_map(function ($group) {
            return [
                'i_id_room' => isset($group['i_id_room']) ? (int)$group['i_id_room'] : 0,
                'c_create_user' => $group['c_create_user'],
                'dt_create' => $group['dt_create']
            ];
        }, $groupData);
    }

    public function add()
    {
        date_default_timezone_set('Asia/Tokyo');

        // i_disp_noフィールドの最大値取得
        $maxDispNoQuery = $this->MUserInfo->find()
            ->select(['max_disp_no' => $this->MUserInfo->find()->func()->max('i_disp_no')])
            ->first();
        $maxDispNo = $maxDispNoQuery ? $maxDispNoQuery->max_disp_no + 1 : 1;

        $mUserInfo = $this->MUserInfo->newEmptyEntity();
        $mUserInfo->i_del_flag = 0;
        $mUserInfo->dt_create = date('Y-m-d H:i:s');
        $mUserInfo->i_disp_no = $maxDispNo;
        $mUserInfo->i_user_age = (int)$this->request->getData('age');
        $mUserInfo->i_user_level = (int)$this->request->getData('role');

        if ($this->request->is('post')) {
            $data = $this->request->getData();

            // デフォルトユーザー名の設定
            if (empty($data['c_user_name'])) {
                $data['c_user_name'] = 'デフォルトユーザー名';
            }

            // 作成ユーザーの設定
            $user = $this->request->getAttribute('identity');
            $data['c_create_user'] = $user ? $user->get('c_user_name') : '不明なユーザー';

            try {
                // patchEntityでMUserInfoにデータをパッチ
                $mUserInfo = $this->MUserInfo->patchEntity($mUserInfo, $data);

                // バリデーションエラーの確認
                if ($mUserInfo->hasErrors()) {
                    throw new \Exception('バリデーションエラーが発生しました。');
                }

                // ユーザー情報の保存
                if ($this->MUserInfo->save($mUserInfo)) {
                    $this->Flash->success(__('ユーザー情報が保存されました。'));
                    $i_id_user = $mUserInfo->i_id_user;

                    // MUserGroupデータを手動で作成・保存
                    $userGroups = [];
                    foreach ($data['MUserGroup'] as $groupData) {
                        if (!empty($groupData['i_id_room'])) {
                            $userGroups[] = $this->MUserGroup->newEntity([
                                'i_id_user' => (int)$i_id_user,
                                'i_id_room' => (int)$groupData['i_id_room'],
                                'active_flag' => 0,
                                'dt_create' => date('Y-m-d H:i:s'),
                                'c_create_user' => $user ? $user->get('c_user_name') : '不明なユーザー'
                            ]);
                        }
                    }

                    // userGroupsが空でない場合に保存を実行
                    if (!empty($userGroups)) {
                        if ($this->MUserGroup->saveMany($userGroups)) {
                            $this->Flash->success(__('部屋の所属情報が保存されました。'));
                        } else {
                            $this->Flash->error(__('部屋の所属情報の保存に失敗しました。'));
                        }
                    }

                    return $this->redirect(['action' => 'index']);
                } else {
                    $this->Flash->error(__('ユーザー情報の保存に失敗しました。もう一度お試しください。'));
                }
            } catch (\Exception $e) {
                $this->Flash->error(__('予期しないエラーが発生しました。もう一度お試しください。'));
            }
        }

        $rooms = $this->MRoomInfo->find('list', [
            'keyField' => 'i_id_room',
            'valueField' => 'c_room_name'
        ])->toArray();

        $ages = range(1, 80);
        $roles = [0 => '職員', 1 => '児童', 3 => 'その他'];
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

            // フィールド名の修正とnullチェック (c_user_nameがnullの場合のデフォルト値を設定)
            $data['c_user_name'] = $data['c_user_name'] ?? 'デフォルトユーザー名';

            $user = $this->request->getAttribute('identity');
            $data['c_update_user'] = $user ? $user->get('c_user_name') : '不明なユーザー';
            // MUserGroupデータのセットアップ
            $newUserGroups = [];
            if (!empty($data['rooms'])) {
                foreach ($data['rooms'] as $roomId => $activeFlag) {
                    if ($activeFlag === '1') {
                        $newUserGroups[] = $this->MUserInfo->MUserGroup->newEntity([
                            'i_id_user' => $id,
                            'i_id_room' => (int)$roomId,
                            'active_flag' => 0, // 元のコードのロジックに従って 0 を設定
                            'dt_create' => date('Y-m-d H:i:s'),
                            'c_create_user' => $user ? $user->get('c_user_name') : '不明なユーザー',
                            'dt_update' => date('Y-m-d H:i:s'),
                            'c_update_user' => $user ? $user->get('c_user_name') : '不明なユーザー',
                        ]);
                    }
                }
            }


            // トランザクション開始
            $conn = $this->MUserInfo->getConnection();
            $conn->begin();

            try {
                // 現在のMUserGroup関係を削除
                $this->MUserInfo->MUserGroup->deleteAll(['i_id_user' => $id]);

                // パッチを適用して関連付けを設定
                $mUserInfo = $this->MUserInfo->patchEntity($mUserInfo, $data, ['associated' => ['MUserGroup']]);
                $mUserInfo->m_user_group = $newUserGroups;


                // 保存処理
                if ($this->MUserInfo->save($mUserInfo, ['associated' => ['MUserGroup']])) {
                    $conn->commit();
                    $this->Flash->success(__('ユーザー情報が更新されました。'));
                    return $this->redirect(['action' => 'index']);
                } else {
                    // デバッグメッセージ2: 保存失敗
                    $this->Flash->error(__('ユーザー情報の保存に失敗しました。もう一度お試しください。'));
                    $conn->rollback();
                }
            } catch (\Exception $e) {
                // ロールバックを行い、エラー処理
                $conn->rollback();
                $this->Flash->error(__('予期しないエラーが発生しました。もう一度お試しください。'));
            }
        }

        $rooms = $this->MRoomInfo->find('list', ['keyField' => 'i_id_room', 'valueField' => 'c_room_name'])->toArray();

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
