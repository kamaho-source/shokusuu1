<?php
declare(strict_types=1);

namespace App\Controller;

use App\Service\ApiResponseService;
use App\Service\UserBulkImportService;
use App\Service\UserCreateService;
use App\Service\UserDeletionService;
use App\Service\UserEditService;
use App\Service\UserPermissionService;
use App\Service\UserRestoreService;
use App\Service\UserRoomAssignmentService;
use Authorization\Exception\ForbiddenException;
use Cake\Event\EventInterface;
use Cake\Http\Exception\BadRequestException;
use Cake\Http\Exception\GoneException;

class MUserInfoController extends AppController
{
    protected $MUserInfo;
    protected $MRoomInfo;

    private UserBulkImportService   $userBulkImportService;
    private UserCreateService       $userCreateService;
    private UserDeletionService     $userDeletionService;
    private UserEditService         $userEditService;
    private UserPermissionService   $userPermissionService;
    private UserRestoreService      $userRestoreService;
    private UserRoomAssignmentService $userRoomAssignmentService;

    public function initialize(): void
    {
        parent::initialize();
        $this->loadComponent('Authentication.Authentication');
        $this->viewBuilder()->setLayout('default');
        $this->viewBuilder()->setOption('serialize', true);

        $this->MUserInfo = $this->fetchTable('MUserInfo');
        $this->MRoomInfo = $this->fetchTable('MRoomInfo');

        $this->userBulkImportService    = new UserBulkImportService();
        $this->userCreateService        = new UserCreateService();
        $this->userDeletionService      = new UserDeletionService();
        $this->userEditService          = new UserEditService();
        $this->userPermissionService    = new UserPermissionService();
        $this->userRestoreService       = new UserRestoreService();
        $this->userRoomAssignmentService = new UserRoomAssignmentService();

        if (isset($this->FormProtection)) {
            $this->FormProtection->setConfig('unlockedActions', [
                'importJson',
                'updateAdminStatus',
                'updateUserLevel',
                'addUserRooms',
            ]);
        }
    }

    public function beforeFilter(EventInterface $event)
    {
        parent::beforeFilter($event);
        $this->Authentication->addUnauthenticatedActions(['login']);
    }

    public function importForm()
    {
        $resource = $this->MUserInfo->newEmptyEntity();
        try {
            $this->Authorization->authorize($resource, 'importForm');
        } catch (ForbiddenException $e) {
            $this->Flash->error(__('この機能は管理者のみ利用できます。'));
            return $this->redirect(['action' => 'index']);
        }

        $this->viewBuilder()->setLayout('default');
        $this->viewBuilder()->setOption('serialize', true);
        $this->set('title', 'ユーザー一括登録');
    }

    public function import()
    {
        $this->request->allowMethod(['post']);
        throw new GoneException('このエンドポイントは廃止されました。importJson を利用してください。');
    }

    /**
     * クライアント（ブラウザ）でパース済みの JSON を受け取り登録します。
     */
    public function importJson()
    {
        $this->request->allowMethod(['post']);
        $this->viewBuilder()->setClassName('Json');
        $resource = $this->MUserInfo->newEmptyEntity();
        $this->Authorization->authorize($resource, 'importJson');

        $payload = $this->request->getData();
        if (empty($payload)) {
            $payload = json_decode((string)$this->request->getBody(), true) ?? [];
        }
        $records = $payload['records'] ?? null;
        if (!is_array($records)) {
            throw new BadRequestException('records 配列が必要です。');
        }

        $identity   = $this->request->getAttribute('identity');
        $createUser = $identity ? $identity->get('c_user_name') : 'インポート';

        try {
            $results = $this->userBulkImportService->import($records, $createUser);
        } catch (\Throwable $e) {
            throw new BadRequestException('インポート処理でエラー: ' . $e->getMessage());
        }

        $this->set(['ok' => true, 'summary' => $results, '_serialize' => ['ok', 'summary']]);
    }

    public function index()
    {
        $resource = $this->MUserInfo->newEmptyEntity();
        try {
            $this->Authorization->authorize($resource, 'index');
        } catch (ForbiddenException $e) {
            $this->Flash->error(__('あなたは閲覧権限がありません。'));
            return $this->redirect(['action' => 'login']);
        }

        $user          = $this->request->getAttribute('identity');
        $isAdmin       = $user->i_admin === 1;
        $currentUserId = $user->i_id_user;
        $showDeleted   = $isAdmin && $this->request->getQuery('show_deleted') === '1';

        $query = $this->MUserInfo->find()
            ->where(['i_del_flag' => $showDeleted ? 1 : 0])
            ->contain(['MUserGroup' => ['MRoomInfo']]);

        if (!$isAdmin) {
            $query->where(['i_id_user' => $currentUserId]);
        }

        $mUserInfo = $this->paginate($query, ['limit' => 200, 'maxLimit' => 200]);

        $userRooms = [];
        foreach ($mUserInfo as $u) {
            if (!empty($u->m_user_group)) {
                foreach ($u->m_user_group as $group) {
                    if (!empty($group->m_room_info)) {
                        $userRooms[$u->i_id_user][] = $group->m_room_info->c_room_name;
                    }
                }
            } else {
                $userRooms[$u->i_id_user] = [];
            }
        }

        $this->set(compact('mUserInfo', 'userRooms', 'isAdmin', 'currentUserId', 'showDeleted'));
    }

    public function add()
    {
        $this->request->allowMethod(['get', 'post']);

        $maxDispNo  = $this->userCreateService->nextDisplayNo();
        $mUserInfo  = $this->MUserInfo->newEmptyEntity();

        try {
            $this->Authorization->authorize($mUserInfo, 'add');
        } catch (ForbiddenException $e) {
            $this->Flash->error(__('あなたは追加権限がありません。'));
            return $this->redirect(['action' => 'index']);
        }

        $mUserInfo->i_del_flag   = 0;
        $mUserInfo->dt_create    = date('Y-m-d H:i:s');
        $mUserInfo->i_enable     = 0;
        $mUserInfo->i_disp_no    = $maxDispNo;
        $mUserInfo->i_user_age   = (int)$this->request->getData('age');
        $mUserInfo->i_user_level = (int)$this->request->getData('role');
        if ($mUserInfo->i_user_level === 0) {
            $mUserInfo->i_id_staff = $this->request->getData('staff_id');
        }
        $mUserInfo->i_user_gender = (int)$this->request->getData('i_user_gender');
        $mUserInfo->i_user_rank   = (int)$this->request->getData('age_group');

        if ($this->request->is('post')) {
            $data = $this->request->getData();

            if (empty($data['c_user_name'])) {
                $data['c_user_name'] = 'デフォルトユーザー名';
            }

            if ($this->userCreateService->loginAccountExists($data['c_login_account'])) {
                $this->Flash->error(__('このログインIDは既に使用されています。他のIDをお試しください。'));
            } else {
                $user              = $this->request->getAttribute('identity');
                $data['c_create_user'] = $user ? $user->get('c_user_name') : '不明なユーザー';

                try {
                    $mUserInfo = $this->MUserInfo->patchEntity($mUserInfo, $data);

                    if ($mUserInfo->hasErrors()) {
                        throw new \Exception('バリデーションエラーが発生しました。');
                    }

                    $groupData = $data['MUserGroup'] ?? [];
                    $createdBy = $user ? $user->get('c_user_name') : '不明なユーザー';

                    if ($this->userCreateService->saveWithRooms($mUserInfo, $groupData, $createdBy)) {
                        $this->Flash->success(__('ユーザー情報が保存されました。'));
                        return $this->redirect(['action' => 'index']);
                    }
                    $this->Flash->error(__('ユーザー情報の保存に失敗しました。もう一度お試しください。'));
                } catch (\Exception $e) {
                    $this->Flash->error(__('予期しないエラーが発生しました。もう一度お試しください。'));
                }
            }
        }

        $rooms = $this->MRoomInfo->find('list', [
            'keyField'   => 'i_id_room',
            'valueField' => 'c_room_name',
        ])->toArray();

        $ages  = range(1, 80);
        $roles = [0 => '職員', 1 => '児童', 3 => 'その他'];
        $this->set(compact('mUserInfo', 'rooms', 'ages', 'roles'));
    }

    public function edit($id = null)
    {
        $this->request->allowMethod(['get', 'post', 'put', 'patch']);

        $mUserInfo = $this->MUserInfo->get($id, ['contain' => ['MUserGroup']]);

        try {
            $this->Authorization->authorize($mUserInfo, 'edit');
        } catch (ForbiddenException $e) {
            $this->Flash->error(__('あなたは編集権限がありません。'));
            return $this->redirect(['action' => 'index']);
        }

        if ($this->request->is(['patch', 'post', 'put'])) {
            $data = $this->request->getData();
            $data['c_user_name']   = $data['c_user_name'] ?? 'デフォルトユーザー名';
            $user                  = $this->request->getAttribute('identity');
            $data['c_update_user'] = $user ? $user->get('c_user_name') : '不明なユーザー';
            $data['dt_update']     = date('Y-m-d H:i:s');

            $roomIds = [];
            if (!empty($data['rooms'])) {
                foreach ($data['rooms'] as $roomId => $activeFlag) {
                    if ($activeFlag === '1') {
                        $roomIds[] = (int)$roomId;
                    }
                }
            }

            $updatedBy = $user ? $user->get('c_user_name') : '不明なユーザー';

            try {
                if ($this->userEditService->updateWithRooms($mUserInfo, $data, $roomIds, $updatedBy)) {
                    $this->Flash->success(__('ユーザー情報が更新されました。'));
                    return $this->redirect(['action' => 'index']);
                }
                $this->Flash->error(__('ユーザー情報の保存に失敗しました。もう一度お試しください。'));
            } catch (\Exception $e) {
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

    public function updateAdminStatus()
    {
        $this->request->allowMethod(['post']);
        $apiResponse = new ApiResponseService();

        $data   = $this->request->getData();
        $userId = $data['i_id_user'] ?? null;
        $isAdmin = $data['i_admin'] ?? null;

        if (is_null($userId) || is_null($isAdmin)) {
            return $apiResponse->error($this->response, 'ユーザーIDまたは管理者権限が指定されていません。', 400);
        }

        $user = $this->MUserInfo->find()->where(['i_id_user' => (int)$userId])->first();
        if (!$user) {
            return $apiResponse->error($this->response, '対象ユーザーが見つかりません。', 404);
        }

        try {
            $this->Authorization->authorize($user, 'updateAdminStatus');
        } catch (ForbiddenException $e) {
            return $apiResponse->error($this->response, 'この操作は管理者のみ実行できます。', 403);
        }

        $identity  = $this->request->getAttribute('identity');
        $updatedBy = $identity ? $identity->get('c_user_name') : '不明なユーザー';

        if ($this->userPermissionService->updatePermission($user, (int)$isAdmin, $updatedBy)) {
            return $apiResponse->success($this->response, [], '管理者権限が正常に更新されました。');
        }
        return $apiResponse->error($this->response, '管理者権限の更新に失敗しました。', 500);
    }

    public function updateUserLevel()
    {
        $this->request->allowMethod(['post']);
        $apiResponse = new ApiResponseService();

        $data   = $this->request->getData();
        $userId = $data['i_id_user'] ?? null;
        $level  = $data['i_admin'] ?? null;

        if (is_null($userId) || is_null($level)) {
            return $apiResponse->error($this->response, 'ユーザーIDまたは権限レベルが指定されていません。', 400);
        }

        $user = $this->MUserInfo->find()->where(['i_id_user' => (int)$userId])->first();
        if (!$user) {
            return $apiResponse->error($this->response, '対象ユーザーが見つかりません。', 404);
        }

        try {
            $this->Authorization->authorize($user, 'updateAdminStatus');
        } catch (ForbiddenException $e) {
            return $apiResponse->error($this->response, 'この操作は管理者のみ実行できます。', 403);
        }

        $identity  = $this->request->getAttribute('identity');
        $updatedBy = $identity ? $identity->get('c_user_name') : '不明なユーザー';

        if ($this->userPermissionService->updatePermission($user, (int)$level, $updatedBy)) {
            return $apiResponse->success($this->response, [], 'ブロック長権限が正常に更新されました。');
        }
        return $apiResponse->error($this->response, 'ブロック長権限の更新に失敗しました。', 500);
    }

    public function view($id = null)
    {
        $mUserInfo = $this->MUserInfo->get($id, [
            'contain' => ['MUserGroup' => ['MRoomInfo']],
        ]);
        try {
            $this->Authorization->authorize($mUserInfo, 'view');
        } catch (ForbiddenException $e) {
            $this->Flash->error(__('あなたは閲覧権限がありません。'));
            return $this->redirect(['action' => 'index']);
        }

        $userRooms = [];
        if (!empty($mUserInfo->m_user_group)) {
            foreach ($mUserInfo->m_user_group as $group) {
                if (!empty($group->m_room_info)) {
                    $userRooms[] = $group->m_room_info->c_room_name;
                }
            }
        }

        $this->set(compact('mUserInfo', 'userRooms'));
    }

    public function delete($id = null)
    {
        $this->request->allowMethod(['post', 'delete']);
        $mUserInfo = $this->MUserInfo->get($id);

        try {
            $this->Authorization->authorize($mUserInfo, 'delete');
        } catch (ForbiddenException $e) {
            $this->Flash->error(__('あなたは削除権限がありません。'));
            return $this->redirect(['action' => 'index']);
        }

        $user      = $this->request->getAttribute('identity');
        $updatedBy = $user ? $user->get('c_user_name') : '不明なユーザー';

        if ($this->userDeletionService->softDelete($mUserInfo, $updatedBy)) {
            $this->Flash->success(__('ユーザー情報が削除されました。'));
        } else {
            $this->Flash->error(__('ユーザー情報を削除できませんでした。'));
        }

        return $this->redirect(['action' => 'index']);
    }

    public function addRoomToUser($userId)
    {
        $this->request->allowMethod(['post']);
        $targetUser = $this->MUserInfo->get($userId);
        try {
            $this->Authorization->authorize($targetUser, 'addRoomToUser');
        } catch (ForbiddenException $e) {
            $this->Flash->error(__('あなたは部屋追加権限がありません。'));
            return $this->redirect(['action' => 'index']);
        }

        $user       = $this->request->getAttribute('identity');
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
        $this->request->allowMethod(['post', 'delete']);
        $targetUser = $this->MUserInfo->get($userId);
        try {
            $this->Authorization->authorize($targetUser, 'removeRoomFromUser');
        } catch (ForbiddenException $e) {
            $this->Flash->error(__('あなたは部屋削除権限がありません。'));
            return $this->redirect(['action' => 'index']);
        }

        if ($this->MUserInfo->deleteUserRoom($userId, $roomId)) {
            $this->Flash->success(__('部屋の関連が削除されました。'));
        } else {
            $this->Flash->error(__('部屋の関連を削除できませんでした。'));
        }

        return $this->redirect(['action' => 'view', $userId]);
    }

    public function login()
    {
        $this->Authorization->skipAuthorization();
        $this->request->allowMethod(['get', 'post']);
        $result = $this->Authentication->getResult();

        if ($result && $result->isValid()) {
            $user = $result->getData();

            if ($user->i_del_flag === 1 || $user->i_enable === 1) {
                $this->Authentication->logout();
                $this->Flash->error(__('このアカウントは無効化されています。'));
                return $this->redirect(['action' => 'login']);
            }

            if ((int)$user->i_admin === 1) {
                $defaultRedirect = ['controller' => 'TReservationInfo', 'action' => 'index'];
            } elseif ((int)$user->i_user_level === 1) {
                $defaultRedirect = ['controller' => 'TReservationInfo', 'action' => 'index'];
            } else {
                $defaultRedirect = ['controller' => 'Pages', 'action' => 'display', 'home'];
            }
            $redirectParam = $this->request->getQuery('redirect');
            $redirect = $this->isSafeRedirect($redirectParam) ? $redirectParam : $defaultRedirect;
            return $this->redirect($redirect);
        }

        if ($this->request->is('post') && !$result->isValid()) {
            $status = $result ? $result->getStatus() : 'Result is null';
            $this->log('Login failed. status=' . preg_replace('/[\r\n\t]/', ' ', (string)$status), 'debug');
            $this->Flash->error(__('ユーザー名またはパスワードが正しくありません。'));
        }
    }

    public function logout()
    {
        $this->Authorization->skipAuthorization();
        $result = $this->Authentication->getResult();
        if ($result->isValid()) {
            $this->Authentication->logout();
            $this->Flash->success('正常にログアウトされました。');
        }
        return $this->redirect(['controller' => 'MUserInfo', 'action' => 'login']);
    }

    public function adminChangePassword()
    {
        $this->request->allowMethod(['get', 'post', 'put']);
        $resource = $this->MUserInfo->newEmptyEntity();
        try {
            $this->Authorization->authorize($resource, 'adminChangePassword');
        } catch (ForbiddenException $e) {
            $this->Flash->error(__('この機能は管理者のみ利用できます。'));
            return $this->redirect(['action' => 'index']);
        }

        $users = $this->fetchTable('MUserInfo')->find('list', [
            'keyField'   => 'i_id_user',
            'valueField' => 'c_user_name',
        ])->where(['i_del_flag' => 0])->toArray();

        $selectedUser = null;

        if ($this->request->is(['post', 'put'])) {
            $data            = $this->request->getData();
            $userId          = $data['user_id'] ?? null;
            $newPassword     = $data['new_password'] ?? '';
            $confirmPassword = $data['confirm_password'] ?? '';

            if (!$userId || !isset($users[$userId])) {
                $this->Flash->error(__('ユーザーを選択してください。'));
                return $this->redirect(['action' => 'adminChangePassword']);
            }

            $selectedUser = $this->fetchTable('MUserInfo')->get($userId);

            if ($newPassword !== $confirmPassword) {
                $this->Flash->error(__('新しいパスワードが一致しません。'));
                return $this->redirect(['action' => 'adminChangePassword']);
            }

            if (strlen($newPassword) < 6) {
                $this->Flash->error(__('新しいパスワードは6文字以上にしてください。'));
                return $this->redirect(['action' => 'adminChangePassword']);
            }

            $selectedUser->c_login_passwd = $newPassword;

            if ($this->fetchTable('MUserInfo')->save($selectedUser)) {
                $this->Flash->success(__('パスワードを変更しました。'));
                return $this->redirect(['action' => 'index']);
            }

            $this->Flash->error(__('パスワードの変更に失敗しました。'));
        }

        $this->set(compact('users', 'selectedUser'));
    }

    public function generalPasswordReset(): ?\Cake\Http\Response
    {
        $this->request->allowMethod(['get', 'post', 'put', 'patch']);
        $identity = $this->request->getAttribute('identity');
        if (!$identity) {
            $this->Flash->error('ログインしてください。');
            return $this->redirect(['controller' => 'MUserInfo', 'action' => 'login']);
        }

        $userId = $identity->getIdentifier() ?? $identity->get('i_id_user');
        $Users  = $this->fetchTable('MUserInfo');
        $user   = $Users->get($userId);

        try {
            $this->Authorization->authorize($user, 'generalPasswordReset');
        } catch (ForbiddenException $e) {
            $this->Flash->error('この操作は許可されていません。');
            return $this->redirect(['action' => 'index']);
        }

        if ($this->request->is(['post', 'put', 'patch'])) {
            $data            = (array)$this->request->getData();
            $newPassword     = (string)($data['new_password'] ?? '');
            $confirmPassword = (string)($data['confirm_password'] ?? '');

            if ($newPassword !== $confirmPassword) {
                $this->Flash->error('新しいパスワードが一致しません。');
                return $this->redirect($this->request->getRequestTarget());
            }
            if (mb_strlen($newPassword) < 4) {
                $this->Flash->error('新しいパスワードは4文字以上にしてください。');
                return $this->redirect($this->request->getRequestTarget());
            }

            $user->c_login_passwd = $newPassword;

            if ($Users->save($user)) {
                $this->request->getSession()->renew();
                $this->Flash->success('パスワードを変更しました。');
                return $this->redirect(['controller' => 'TReservationInfo', 'action' => 'index']);
            }

            $this->Flash->error('パスワードの変更に失敗しました。');
        }

        $this->set(compact('user'));
        return null;
    }

    /**
     * ユーザーの所属部屋登録API
     * POST: i_id_user, room_names[]
     * 既存所属はactive_flag=1に更新し、新規所属をactive_flag=0で登録（最大2部屋）
     */
    public function addUserRooms()
    {
        $this->request->allowMethod(['post']);
        $this->viewBuilder()->setClassName('Json');

        $userId    = $this->request->getData('i_id_user');
        $roomNames = $this->request->getData('room_names');

        if (!is_numeric($userId) || empty($roomNames) || !is_array($roomNames)) {
            $this->set(['ok' => false, 'message' => 'i_id_userとroom_names[]が必要です', '_serialize' => ['ok', 'message']]);
            return;
        }

        $userId = (int)$userId;
        $user   = $this->MUserInfo->find()->where(['i_id_user' => $userId, 'i_del_flag' => 0])->first();

        if (!$user) {
            $this->set(['ok' => false, 'message' => 'ユーザーが見つかりません', '_serialize' => ['ok', 'message']]);
            return;
        }

        try {
            $this->Authorization->authorize($user, 'addUserRooms');
        } catch (ForbiddenException $e) {
            $this->set(['ok' => false, 'message' => '権限がありません', '_serialize' => ['ok', 'message']]);
            return;
        }

        $identity = $this->request->getAttribute('identity');
        $actor    = $identity ? $identity->get('c_user_name') : 'API';

        try {
            $result = $this->userRoomAssignmentService->assign($userId, $roomNames, $actor);
            $this->set([
                'ok'      => true,
                'created' => $result['created'],
                'errors'  => $result['errors'],
                '_serialize' => ['ok', 'created', 'errors'],
            ]);
        } catch (\Throwable $e) {
            $this->set(['ok' => false, 'message' => $e->getMessage(), '_serialize' => ['ok', 'message']]);
        }
    }

    /**
     * 削除済みユーザーを復元する（管理者のみ）
     */
    public function restore($id = null)
    {
        $this->request->allowMethod(['post', 'put']);
        $user     = $this->MUserInfo->get($id);
        $identity = $this->request->getAttribute('identity');

        try {
            $this->Authorization->authorize($user, 'restore');
        } catch (ForbiddenException $e) {
            $this->Flash->error(__('この機能は管理者のみ利用できます。'));
            return $this->redirect(['action' => 'index']);
        }

        if ($user->i_del_flag !== 1) {
            $this->Flash->error(__('このユーザーは削除されていません。'));
            return $this->redirect(['action' => 'index', '?' => ['show_deleted' => '1']]);
        }

        $updatedBy = $identity->get('c_user_name') ?? 'admin';

        try {
            $this->userRestoreService->restore($user, $updatedBy);
            $this->Flash->success(__('ユーザー「{0}」を復元しました。', $user->c_user_name));
            return $this->redirect(['action' => 'index']);
        } catch (\Exception $e) {
            $this->Flash->error(__('ユーザーの復元に失敗しました: {0}', $e->getMessage()));
            return $this->redirect(['action' => 'index', '?' => ['show_deleted' => '1']]);
        }
    }
}
