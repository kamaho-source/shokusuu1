<?php
declare(strict_types=1);

namespace App\Controller;

use App\Application\Tenant\TenantContextHolder;
use App\Model\Table\MRoomTransferScheduleTable;
use App\Service\RoomTransferScheduleService;
use Authorization\Exception\ForbiddenException;
use Cake\Http\Exception\BadRequestException;
use Cake\Http\Exception\NotFoundException;

/**
 * 部屋異動予約コントローラー
 *
 * 管理者が将来日付での部屋異動を事前登録・確認・キャンセルする画面を提供する。
 */
class MRoomTransferScheduleController extends AppController
{
    private RoomTransferScheduleService $transferService;

    public function initialize(): void
    {
        parent::initialize();
        $this->viewBuilder()->setLayout('default');
        $this->transferService = new RoomTransferScheduleService();
    }

    /**
     * 異動予約一覧
     */
    public function index(): void
    {
        $table    = $this->fetchTable('MRoomTransferSchedule');
        $resource = $table->newEmptyEntity();

        try {
            $this->Authorization->authorize($resource, 'index');
        } catch (ForbiddenException $e) {
            $this->Flash->error(__('この機能は管理者のみ利用できます。'));
            $this->redirect(['controller' => 'TReservationInfo', 'action' => 'index']);
            return;
        }

        $statusFilter = $this->request->getQuery('status');
        $conditions   = [];
        if (in_array($statusFilter, ['0', '1', '2'], true)) {
            $conditions['MRoomTransferSchedule.i_status'] = (int)$statusFilter;
        }

        $ctx = TenantContextHolder::get();
        if ($ctx !== null) {
            $conditions['MRoomTransferSchedule.tenant_id'] = $ctx->tenantId();
        }

        $schedules = $table->find()
            ->contain(['MUserInfo', 'RoomFrom', 'RoomTo'])
            ->where($conditions)
            ->orderByDesc('MRoomTransferSchedule.d_effective_date')
            ->all();

        $this->set(compact('schedules', 'statusFilter'));
    }

    /**
     * 異動予約登録フォーム・保存
     */
    public function add(): void
    {
        $this->request->allowMethod(['get', 'post']);

        $table    = $this->fetchTable('MRoomTransferSchedule');
        $resource = $table->newEmptyEntity();

        try {
            $this->Authorization->authorize($resource, 'add');
        } catch (ForbiddenException $e) {
            $this->Flash->error(__('この機能は管理者のみ利用できます。'));
            $this->redirect(['action' => 'index']);
            return;
        }

        if ($this->request->is('post')) {
            $data = $this->request->getData();

            $userId      = isset($data['i_id_user'])      ? (int)$data['i_id_user']      : null;
            $roomFromId  = isset($data['i_id_room_from']) && $data['i_id_room_from'] !== ''
                ? (int)$data['i_id_room_from']
                : null;
            $roomToId    = isset($data['i_id_room_to'])   ? (int)$data['i_id_room_to']   : null;
            $effectiveDate = (string)($data['d_effective_date'] ?? '');

            if (!$userId || !$roomToId || $effectiveDate === '') {
                $this->Flash->error(__('ユーザー・異動先部屋・有効開始日は必須です。'));
            } else {
                $identity = $this->request->getAttribute('identity');
                $actor    = $identity ? $identity->get('c_user_name') : '管理者';

                try {
                    $this->transferService->create($userId, $roomFromId, $roomToId, $effectiveDate, $actor);
                    $this->Flash->success(__('部屋異動予約を登録しました。'));
                    $this->redirect(['action' => 'index']);
                    return;
                } catch (\RuntimeException $e) {
                    $this->Flash->error($e->getMessage());
                } catch (\Throwable $e) {
                    $this->Flash->error(__('登録処理中にエラーが発生しました。'));
                }
            }
        }

        $addCtx = TenantContextHolder::get();

        $usersQuery = $this->fetchTable('MUserInfo')->find('list', keyField: 'i_id_user', valueField: 'c_user_name')
            ->where(['i_del_flag' => 0])->orderByAsc('i_disp_no');
        if ($addCtx !== null) {
            $usersQuery->where(['MUserInfo.tenant_id' => $addCtx->tenantId()]);
        }
        $users = $usersQuery->toArray();

        $roomsQuery = $this->fetchTable('MRoomInfo')->find('list', keyField: 'i_id_room', valueField: 'c_room_name');
        if ($addCtx !== null) {
            $roomsQuery->where(['tenant_id' => $addCtx->tenantId()]);
        }
        $rooms = $roomsQuery->toArray();

        $userRoomRows = $this->fetchTable('MUserGroup')->find()
            ->select(['i_id_user', 'i_id_room'])
            ->where(['active_flag' => 0])
            ->toArray();

        $userRoomMap = [];
        foreach ($userRoomRows as $row) {
            $userRoomMap[$row->i_id_user][] = $row->i_id_room;
        }

        $this->set(compact('users', 'rooms', 'userRoomMap'));
    }

    /**
     * 異動予約キャンセル
     *
     * @param int $id スケジュールID
     */
    public function cancel(int $id): void
    {
        $this->request->allowMethod(['post']);

        $table    = $this->fetchTable('MRoomTransferSchedule');
        $resource = $table->newEmptyEntity();

        try {
            $this->Authorization->authorize($resource, 'cancel');
        } catch (ForbiddenException $e) {
            $this->Flash->error(__('この機能は管理者のみ利用できます。'));
            $this->redirect(['action' => 'index']);
            return;
        }

        try {
            $table->get($id);
        } catch (\Cake\Datasource\Exception\RecordNotFoundException $e) {
            throw new NotFoundException('指定された予約が見つかりません。');
        }

        $identity = $this->request->getAttribute('identity');
        $actor    = $identity ? $identity->get('c_user_name') : '管理者';

        try {
            $this->transferService->cancel($id, $actor);
            $this->Flash->success(__('異動予約をキャンセルしました。'));
        } catch (\RuntimeException $e) {
            $this->Flash->error($e->getMessage());
        } catch (\Throwable $e) {
            $this->Flash->error(__('キャンセル処理中にエラーが発生しました。'));
        }

        $this->redirect(['action' => 'index']);
    }
}
