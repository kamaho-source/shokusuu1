<?php
declare(strict_types=1);

namespace App\Controller;

use AllowDynamicProperties;
use Cake\Event\EventInterface;
use Cake\Log\Log;
use InvalidArgumentException;

/**
 * MUserInfo Controller
 *
 * @property \App\Model\Table\MUserInfoTable $MUserInfo
 */

class MUserInfoController extends AppController
{

    protected $MUserGroup;
    protected $MUserInfo;
    protected $MRoomInfo;
    /**
     * @var \Cake\Controller\Component|\Cake\ORM\Table|mixed|null
     */
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

    /**
     * Index method
     *
     * @return \Cake\Http\Response|null|void Renders view
     */
    public function index()
    {
        $query = $this->MUserInfo->find()->where(['i_del_flag' => 0]);
        $mUserInfo = $this->paginate($query);

        // 各ユーザーの部屋情報を取得
        $userRooms = [];
        foreach ($mUserInfo as $user) {
            // ユーザーIDがnullでない場合のみ取得
            if ($user->id !== null) {
                $userRooms[$user->id] = $this->getUserRooms($user->id);
            } else {
                // nullのユーザーIDには適切な処理を行う
                $userRooms[$user->id] = []; // 例えば空の配列を返す
            }
        }

        $this->set(compact('mUserInfo', 'userRooms'));
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

        // ユーザーの部屋情報を取得
        $userRooms = $this->getUserRooms($id);

        $this->set(compact('mUserInfo', 'userRooms'));
    }

    /**
     * Add method
     *
     * @return \Cake\Http\Response|null|void Redirects on successful add, renders view otherwise.
     */

    public function add()
    {
        // 新規ユーザー情報の作成
        $mUserInfo = $this->MUserInfo->newEmptyEntity();

        if ($this->request->is('post')) {
            // フォームから送信されたデータをエンティティにパッチ
            $mUserInfo = $this->MUserInfo->patchEntity($mUserInfo, $this->request->getData());

            // ユーザー情報を保存
            if ($this->MUserInfo->save($mUserInfo)) {
                // 保存したユーザーIDを取得
                $userId = $mUserInfo->i_id_user;

                if (!empty($userId)) {
                    // 部屋の所属情報の保存処理
                    $roomIds = $this->request->getData('i_id_room');
                    if (!empty($roomIds) && is_array($roomIds)) {
                        foreach ($roomIds as $roomId) {
                            // 部屋IDが0でない場合に処理
                            if ($roomId != 0) {
                                $userGroup = $this->MUserGroup->newEmptyEntity();
                                $user = $this->request->getAttribute('identity');
                                $userGroup->i_id_user = $userId;  // ユーザーIDを設定
                                $userGroup->i_id_room = $roomId;  // 部屋IDを設定
                                $userGroup->activeflag = 0;  // activeflagを設定
                                $userGroup->dt_create = date('Y-m-d H:i:s');  // 現在時刻をdt_createに設定
                                $userGroup->c_create_user = $user->get('c__user_name');  // 作成ユーザーIDを設定

                                // MUserGroupテーブルに保存
                                if (!$this->MUserGroup->save($userGroup)) {
                                    $this->Flash->error(__('部屋の所属情報の保存に失敗しました: ') . implode(", ", $userGroup->getErrors()));
                                }
                            }
                        }
                    }
                } else {
                    // ユーザーIDがnullの場合のエラーハンドリング
                    $this->Flash->error(__('ユーザーIDの取得に失敗しました。'));
                }

                // 成功メッセージを表示してリダイレクト
                $this->Flash->success(__('ユーザー情報が正常に保存されました。'));
                return $this->redirect(['action' => 'index']);
            }
            // 保存が失敗した場合のエラーメッセージ
            $this->Flash->error(__('ユーザー情報の保存に失敗しました。もう一度お試しください。'));
        }

        // 部屋情報を取得してビューに渡す
        $rooms = $this->MRoomInfo->find('list',keyField:'i_id_room',valueField:'c_room_name')->toArray();  // 部屋情報のリストを取得
        $this->set(compact('mUserInfo', 'rooms'));
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
            $user = $this->request->getAttribute('identity');
            if ($user) {
                $mUserInfo->c_update_user = $user->get('c_user_name');
            } else {
                $this->Flash->error(__('ユーザー情報が取得できませんでした。'));
                return $this->redirect(['action' => 'edit', $id]);
            }

            $mUserInfo->dt_update = date('Y-m-d H:i:s');
            $mUserInfo = $this->MUserInfo->patchEntity($mUserInfo, $this->request->getData());

            if ($this->MUserInfo->save($mUserInfo)) {
                $this->Flash->success(__('ユーザー情報が更新されました。'));
                return $this->redirect(['action' => 'index']);
            }
            $this->Flash->error(__('ユーザー情報を更新できませんでした。'));
        }

        // ユーザーの所属する部屋情報を取得
        $userRooms = $this->getUserRooms($id);
        $this->set(compact('mUserInfo', 'userRooms'));
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
        $mUserInfo->i_del_flag = 1;

        if ($this->MUserInfo->save($mUserInfo)) {
            $this->Flash->success(__('ユーザー情報が削除されました。'));
        } else {
            $this->Flash->error(__('ユーザー情報を削除できませんでした。'));
        }

        return $this->redirect(['action' => 'index']);
    }

    /**
     * ユーザーの部屋情報を取得するメソッド
     *
     * @param int $userId ユーザーID
     * @return \Cake\Datasource\ResultSetInterface 部屋情報
     */
    private function getUserRooms($userId)
    {
        // ユーザーIDがnullでないことを確認
        if ($userId === null) {
            throw new InvalidArgumentException('User ID cannot be null');
        }

        // MUserGroup モデルからデータを取得
        $MUserGroup = $this->fetchTable('MUserGroup');
        return $MUserGroup->find()->select('i_id_room')->where(['i_id_user' => $userId])->all();
    }
    /**
     * ユーザーに部屋を追加するアクション
     */
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

    /**
     * ユーザーから部屋を削除するアクション
     */
    public function removeRoomFromUser($userId, $roomId)
    {
        if ($this->MUserInfo->deleteUserRoom($userId, $roomId)) {
            $this->Flash->success(__('部屋の関連が削除されました。'));
        } else {
            $this->Flash->error(__('部屋の関連を削除できませんでした。'));
        }

        return $this->redirect(['action' => 'view', $userId]);
    }

    /**
     * ログイン機能
     */
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
        }
    }

    /**
     * ログアウト機能
     */
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
