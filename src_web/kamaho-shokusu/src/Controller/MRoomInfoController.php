<?php
declare(strict_types=1);

namespace App\Controller;

use App\Domain\Exception\InvalidInputException;
use App\Service\RoomMemberOrderService;
use App\Service\RoomService;
use Authorization\Exception\ForbiddenException;
use Cake\Http\Response;
use Cake\I18n\DateTime;

/**
 * MRoomInfo Controller
 *
 * @property \App\Model\Table\MRoomInfoTable $MRoomInfo
 */
class MRoomInfoController extends AppController
{
    use JsonResponseTrait;

    private RoomService $roomService;

    public function initialize(): void
    {
        parent::initialize();
        $this->loadComponent('Authentication.Authentication');
        $this->viewBuilder()->setOption('serialize', true);
        $this->viewBuilder()->setLayout('default');
        // fetch から JSON で POST される API のため FormProtection のフォームトークン検証対象外にする（CSRF は検証する）
        $this->FormProtection->setConfig('unlockedActions', ['reorderMembers']);

        $this->roomService = new RoomService();
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
        $mRoomInfo = $this->MRoomInfo->get($id, ['contain' => ['MUserGroup']]);
        try {
            $this->Authorization->authorize($mRoomInfo, 'view');
        } catch (ForbiddenException $e) {
            $this->Flash->error(__('あなたは閲覧権限がありません。'));
            return $this->redirect(['action' => 'index']);
        }
        if ((int)($mRoomInfo->i_del_flg ?? 1) !== 0) {
            $this->Flash->error(__('この部屋は削除されています。'));
            return $this->redirect(['action' => 'index']);
        }

        $users = $this->roomService->getUsersForRoom($mRoomInfo);
        $canReorder = $this->Authorization->can($mRoomInfo, 'reorderMembers');

        $this->set(compact('mRoomInfo', 'users', 'canReorder'));
    }

    /**
     * 所属メンバーの並び順を保存する（#871）。JSON: {"user_ids": [並べた順のユーザーID]}
     */
    public function reorderMembers($id = null): Response
    {
        $this->request->allowMethod(['post']);
        $mRoomInfo = $this->MRoomInfo->find()->where(['i_id_room' => (int)$id, 'i_del_flg' => 0])->first();
        if ($mRoomInfo === null) {
            $this->Authorization->skipAuthorization();
            return $this->jsonError(__('部屋が見つかりません。'), 404);
        }
        try {
            $this->Authorization->authorize($mRoomInfo, 'reorderMembers');
        } catch (ForbiddenException $e) {
            return $this->jsonError(__('この部屋のメンバーを並び替える権限がありません。'), 403);
        }

        $userIds = $this->request->getData('user_ids');
        if (!is_array($userIds) || $userIds === []) {
            return $this->jsonError(__('並び順が送られていません。'), 400);
        }

        $user = $this->request->getAttribute('identity');
        $userName = (string)($user?->get('c_user_name') ?? '');
        try {
            $changed = (new RoomMemberOrderService())->reorder(
                (int)$mRoomInfo->i_id_room,
                array_map('intval', array_values($userIds)),
                $userName
            );
        } catch (InvalidInputException $e) {
            return $this->jsonError($e->getMessage(), $e->getStatusCode());
        }
        // 並びが変わらなかった保存は記録しない
        if ($changed > 0) {
            \App\Service\AuditLogService::record('master', 'room_member_reorder', $userName ?: '不明', $user ? (int)$user->get('i_id_user') : 0, 'm_room_info', (string)$mRoomInfo->i_id_room, ['room_name' => $mRoomInfo->c_room_name, 'changed' => $changed], $this->getClientIp(), 1, (string)($user?->get('c_login_account') ?? ''));
        }

        return $this->jsonResponse(['success' => true, 'changed' => $changed]);
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
            $mRoomInfo->dt_create    = DateTime::now('Asia/Tokyo');
            $user = $this->request->getAttribute('identity');
            if ($user) {
                $mRoomInfo->c_create_user = $user->get('c_user_name');
            }
            $mRoomInfo->i_del_flg  = 0;
            $mRoomInfo->i_disp_no  = $this->roomService->nextDisplayNo();

            $mRoomInfo = $this->MRoomInfo->patchEntity($mRoomInfo, $data, [
                'fieldList' => ['c_room_name'],
            ]);

            if ($this->MRoomInfo->save($mRoomInfo)) {
                \App\Service\AuditLogService::record('master', 'room_create', $mRoomInfo->c_create_user ?? '不明', $user ? (int)$user->get('i_id_user') : 0, 'm_room_info', (string)$mRoomInfo->i_id_room, ['room_name' => $mRoomInfo->c_room_name], $this->getClientIp(), 1, (string)($user?->get('c_login_account') ?? ''));
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
            $mRoomInfo->dt_update = DateTime::now('Asia/Tokyo');
            $user = $this->request->getAttribute('identity');
            if ($user) {
                $mRoomInfo->c_update_user = $user->get('c_user_name');
            }

            $mRoomInfo = $this->MRoomInfo->patchEntity($mRoomInfo, $data, [
                'fieldList' => ['c_room_name'],
            ]);

            if ($this->MRoomInfo->save($mRoomInfo)) {
                \App\Service\AuditLogService::record('master', 'room_update', $mRoomInfo->c_update_user ?? '不明', $user ? (int)$user->get('i_id_user') : 0, 'm_room_info', (string)$mRoomInfo->i_id_room, ['room_name' => $mRoomInfo->c_room_name], $this->getClientIp(), 1, (string)($user?->get('c_login_account') ?? ''));
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
        $user      = $this->request->getAttribute('identity');
        $updatedBy = $user?->get('c_user_name');

        $deleted = $this->roomService->softDelete($mRoomInfo, $updatedBy);
        \App\Service\AuditLogService::record('master', 'room_delete', $updatedBy ?? '不明', $user ? (int)$user->get('i_id_user') : 0, 'm_room_info', (string)$mRoomInfo->i_id_room, ['room_name' => $mRoomInfo->c_room_name], $this->getClientIp(), $deleted ? 1 : 0, (string)($user?->get('c_login_account') ?? ''), $deleted ? null : '部屋マスタの削除に失敗しました（対象が存在しない、またはDBエラー）');
        if ($deleted) {
            $this->Flash->success(__('部屋情報を削除しました。'));
        } else {
            $this->Flash->error(__('部屋情報を削除できませんでした。もう一度お試しください。'));
        }

        return $this->redirect(['action' => 'index']);
    }
}
