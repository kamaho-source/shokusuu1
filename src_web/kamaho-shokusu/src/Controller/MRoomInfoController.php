<?php
declare(strict_types=1);

namespace App\Controller;

use Cake\Http\ServerRequest;
use App\Infrastructure\Persistence\Entity\MRoomInfo;
use App\Application\Query\RoomQueryInterface;
use App\Application\UseCase\CreateRoom\CreateRoomInput;
use App\Application\UseCase\CreateRoom\CreateRoomUseCase;
use App\Application\UseCase\DeleteRoom\DeleteRoomInput;
use App\Application\UseCase\DeleteRoom\DeleteRoomUseCase;
use App\Application\UseCase\UpdateRoom\UpdateRoomInput;
use App\Application\UseCase\UpdateRoom\UpdateRoomOutput;
use App\Application\UseCase\UpdateRoom\UpdateRoomUseCase;
use Authorization\Exception\ForbiddenException;

/**
 * MRoomInfo Controller
 *
 * @property \App\Infrastructure\Persistence\Table\MRoomInfoTable $MRoomInfo
 */
class MRoomInfoController extends AppController
{
    public function __construct(ServerRequest $request, private readonly RoomQueryInterface $roomQuery)
    {
        parent::__construct($request);
    }

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
        $resource = new MRoomInfo();
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
        // ID なしは見つからない（404）。null のまま渡すと InvalidPrimaryKeyException で 500 になる
        $mRoomInfo = $this->MRoomInfo->get((int)$id, ['contain' => ['MUserGroup']]);
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

        $users = $this->roomQuery->getRoomMembers((int)$mRoomInfo->i_id_room);
        $canReorder = $this->Authorization->can($mRoomInfo, 'reorderMembers');

        $this->set(compact('mRoomInfo', 'users', 'canReorder'));
    }

    /**
     * Add method
     *
     * @return \Cake\Http\Response|null|void Redirects on successful add, renders view otherwise.
     */
    public function add(CreateRoomUseCase $createRoom)
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
            $result = $createRoom->execute(new CreateRoomInput(
                (array)$this->request->getData(),
                $this->auditActor(),
                $this->request->getAttribute('identity') !== null,
            ));
            if ($result->created) {
                $this->Flash->success(__('部屋情報が正常に追加されました。'));
                return $this->redirect(['action' => 'index']);
            }
            $mRoomInfo->setErrors($result->fieldErrors);
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
    public function edit(UpdateRoomUseCase $updateRoom, $id = null)
    {
        $this->request->allowMethod(['get', 'post', 'put', 'patch']);
        $mRoomInfo = $this->MRoomInfo->get((int)$id, contain: ['MUserGroup']);
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
            $userIds = $this->request->getData('user_ids');
            $result = $updateRoom->execute(new UpdateRoomInput(
                (int)$mRoomInfo->i_id_room,
                (array)$this->request->getData(),
                is_array($userIds) ? array_map('intval', array_values($userIds)) : null,
                $this->auditActor(),
                $canEditRoom,
                $canReorder,
            ));
            if ($result->status === UpdateRoomOutput::INVALID_FIELDS) {
                $mRoomInfo->setErrors($result->fieldErrors);
                $this->Flash->error(__('部屋情報を更新できませんでした。もう一度お試しください。'));
                $users = $this->roomQuery->getRoomMembers((int)$mRoomInfo->i_id_room);
                $this->set(compact('mRoomInfo', 'users', 'canEditRoom', 'canReorder'));
                return null;
            }
            if ($result->status === UpdateRoomOutput::REJECTED) {
                $this->Flash->error((string)$result->message);
                return $this->redirect(['action' => 'edit', $mRoomInfo->i_id_room]);
            }
            if ($result->status === UpdateRoomOutput::SAVE_FAILED) {
                $this->Flash->error(__('部屋情報を更新できませんでした。もう一度お試しください。'));
                return $this->redirect(['action' => 'edit', $mRoomInfo->i_id_room]);
            }
            $this->Flash->success(__('部屋情報が正常に更新されました。'));
            // 並びを続けて直せるよう、保存後も編集画面に残る（一覧へは画面の「部屋一覧に戻る」から）
            return $this->redirect(['action' => 'edit', $mRoomInfo->i_id_room]);
        }
        $users = $this->roomQuery->getRoomMembers((int)$mRoomInfo->i_id_room);
        $this->set(compact('mRoomInfo', 'users', 'canEditRoom', 'canReorder'));
    }

    /**
     * Delete method
     *
     * @param string|null $id M Room Info id.
     * @return \Cake\Http\Response|null Redirects to index.
     * @throws \Cake\Datasource\Exception\RecordNotFoundException When record not found.
     */
    public function delete(DeleteRoomUseCase $deleteRoom, $id = null)
    {
        $this->request->allowMethod(['post', 'delete']);
        $mRoomInfo = $this->MRoomInfo->get($id);
        try {
            $this->Authorization->authorize($mRoomInfo, 'delete');
        } catch (ForbiddenException $e) {
            $this->Flash->error(__('あなたは削除権限がありません。'));
            return $this->redirect(['action' => 'index']);
        }
        $deleted = $deleteRoom->execute(new DeleteRoomInput(
            (int)$mRoomInfo->i_id_room,
            $this->auditActor(),
            $this->request->getAttribute('identity') !== null,
        ))->deleted;
        if ($deleted) {
            $this->Flash->success(__('部屋情報を削除しました。'));
        } else {
            $this->Flash->error(__('部屋情報を削除できませんでした。もう一度お試しください。'));
        }

        return $this->redirect(['action' => 'index']);
    }
}
