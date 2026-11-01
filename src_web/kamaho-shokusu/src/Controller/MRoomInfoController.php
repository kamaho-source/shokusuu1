<?php
declare(strict_types=1);

namespace App\Controller;

use App\Domain\Exception\InvalidInputException;
use App\Service\RoomMemberOrderService;
use App\Service\RoomService;
use Authorization\Exception\ForbiddenException;
use Cake\I18n\DateTime;

/**
 * MRoomInfo Controller
 *
 * @property \App\Infrastructure\Persistence\Table\MRoomInfoTable $MRoomInfo
 */
class MRoomInfoController extends AppController
{
    private RoomService $roomService;

    public function initialize(): void
    {
        parent::initialize();
        $this->loadComponent('Authentication.Authentication');
        $this->viewBuilder()->setOption('serialize', true);
        $this->viewBuilder()->setLayout('default');

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
        // 編集ボタンを出す部屋（管理者は全部屋。ブロック長は所属している部屋で、並び替えのため）
        $editableRoomIds = [];
        foreach ($mRoomInfo as $room) {
            if ($this->Authorization->can($room, 'edit') || $this->Authorization->can($room, 'reorderMembers')) {
                $editableRoomIds[] = (int)$room->i_id_room;
            }
        }

        $this->set(compact('mRoomInfo', 'editableRoomIds'));
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
        $mRoomInfo = $this->MRoomInfo->get($id, contain: ['MUserGroup']);
        // 部屋名の変更は管理者だけ。所属メンバーの並び替え（#871）は所属部屋のブロック長も、この画面で行う
        $canEditRoom = $this->Authorization->can($mRoomInfo, 'edit');
        $canReorder = (int)($mRoomInfo->i_del_flg ?? 0) === 0 && $this->Authorization->can($mRoomInfo, 'reorderMembers');
        try {
            $this->Authorization->authorize($mRoomInfo, $canEditRoom || !$canReorder ? 'edit' : 'reorderMembers');
        } catch (ForbiddenException $e) {
            $this->Flash->error(__('あなたは編集権限がありません。'));
            return $this->redirect(['action' => 'index']);
        }

        if ($this->request->is(['post', 'put', 'patch'])) {
            // 部屋名と所属メンバーの並び順を、1つの［保存］でまとめて保存する
            $user = $this->request->getAttribute('identity');
            $userName = (string)($user?->get('c_user_name') ?? '');
            if ($canEditRoom) {
                $mRoomInfo = $this->MRoomInfo->patchEntity($mRoomInfo, $this->request->getData(), [
                    'fieldList' => ['c_room_name'],
                ]);
                if ($mRoomInfo->hasErrors()) {
                    $this->Flash->error(__('部屋情報を更新できませんでした。もう一度お試しください。'));
                    $users = $this->roomService->getUsersForRoom($mRoomInfo);
                    $this->set(compact('mRoomInfo', 'users', 'canEditRoom', 'canReorder'));
                    return null;
                }
            }

            $userIds = $this->request->getData('user_ids');
            $changed = 0;
            if ($canReorder && is_array($userIds)) {
                try {
                    $changed = (new RoomMemberOrderService())->reorder((int)$mRoomInfo->i_id_room, array_map('intval', array_values($userIds)), $userName);
                } catch (InvalidInputException $e) {
                    $this->Flash->error($e->getMessage());
                    return $this->redirect(['action' => 'edit', $mRoomInfo->i_id_room]);
                }
            }

            if ($canEditRoom && $mRoomInfo->isDirty('c_room_name')) {
                $mRoomInfo->dt_update = DateTime::now('Asia/Tokyo');
                $mRoomInfo->c_update_user = $userName;
                if (!$this->MRoomInfo->save($mRoomInfo)) {
                    $this->Flash->error(__('部屋情報を更新できませんでした。もう一度お試しください。'));
                    return $this->redirect(['action' => 'edit', $mRoomInfo->i_id_room]);
                }
                \App\Service\AuditLogService::record('master', 'room_update', $userName ?: '不明', $user ? (int)$user->get('i_id_user') : 0, 'm_room_info', (string)$mRoomInfo->i_id_room, ['room_name' => $mRoomInfo->c_room_name], $this->getClientIp(), 1, (string)($user?->get('c_login_account') ?? ''));
            }
            // 並びが変わらなかった保存は記録しない
            if ($changed > 0) {
                \App\Service\AuditLogService::record('master', 'room_member_reorder', $userName ?: '不明', $user ? (int)$user->get('i_id_user') : 0, 'm_room_info', (string)$mRoomInfo->i_id_room, ['room_name' => $mRoomInfo->c_room_name, 'changed' => $changed], $this->getClientIp(), 1, (string)($user?->get('c_login_account') ?? ''));
            }
            $this->Flash->success(__('部屋情報が正常に更新されました。'));
            // 並びを続けて直せるよう、保存後も編集画面に残る（一覧へは画面の「部屋一覧に戻る」から）
            return $this->redirect(['action' => 'edit', $mRoomInfo->i_id_room]);
        }
        $users = $this->roomService->getUsersForRoom($mRoomInfo);
        $this->set(compact('mRoomInfo', 'users', 'canEditRoom', 'canReorder'));
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
