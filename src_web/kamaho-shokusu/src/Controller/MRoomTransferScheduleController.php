<?php
declare(strict_types=1);

namespace App\Controller;

use App\Application\Query\RoomTransferQueryInterface;
use App\Infrastructure\Persistence\Entity\MRoomTransferSchedule;
use App\Application\UseCase\CancelRoomTransfer\CancelRoomTransferUseCase;
use App\Application\UseCase\CreateRoomTransfer\CreateRoomTransferInput;
use App\Application\UseCase\CreateRoomTransfer\CreateRoomTransferUseCase;
use App\Domain\Exception\NotFoundException as DomainNotFoundException;
use Authorization\Exception\ForbiddenException;
use Cake\Http\Exception\NotFoundException;

/**
 * 部屋異動予約コントローラー
 *
 * 管理者が将来日付での部屋異動を事前登録・確認・キャンセルする画面を提供する。
 */
class MRoomTransferScheduleController extends AppController
{
    public function initialize(): void
    {
        parent::initialize();
        $this->viewBuilder()->setLayout('default');
    }

    /**
     * 異動予約一覧
     */
    public function index(RoomTransferQueryInterface $transferQuery): void
    {
        $resource = new MRoomTransferSchedule();

        try {
            $this->Authorization->authorize($resource, 'index');
        } catch (ForbiddenException $e) {
            $this->Flash->error(__('この機能は管理者のみ利用できます。'));
            $this->redirect(['controller' => 'TReservationInfo', 'action' => 'index']);
            return;
        }

        $statusFilter = $this->request->getQuery('status');
        $schedules = $transferQuery->schedules(in_array($statusFilter, ['0', '1', '2'], true) ? (int)$statusFilter : null);

        $this->set(compact('schedules', 'statusFilter'));
    }

    /**
     * 異動予約登録フォーム・保存
     */
    public function add(CreateRoomTransferUseCase $createTransfer, RoomTransferQueryInterface $transferQuery): void
    {
        $this->request->allowMethod(['get', 'post']);

        $resource = new MRoomTransferSchedule();

        try {
            $this->Authorization->authorize($resource, 'add');
        } catch (ForbiddenException $e) {
            $this->Flash->error(__('この機能は管理者のみ利用できます。'));
            $this->redirect(['action' => 'index']);
            return;
        }

        if ($this->request->is('post')) {
            // 日付が配列だと、入力し直しの画面を描くときに日付の入力欄が TypeError で 500 になる。空（必須エラー）として扱う
            if (!is_string($this->request->getData('d_effective_date') ?? '')) {
                $this->request = $this->request->withData('d_effective_date', '');
            }
            $data     = $this->request->getData();
            $identity = $this->request->getAttribute('identity');
            try {
                $error = $createTransfer->execute(new CreateRoomTransferInput(
                    isset($data['i_id_user']) ? (int)$data['i_id_user'] : null,
                    isset($data['i_id_room_from']) && $data['i_id_room_from'] !== '' ? (int)$data['i_id_room_from'] : null,
                    isset($data['i_id_room_to']) ? (int)$data['i_id_room_to'] : null,
                    $this->dataString('d_effective_date'),
                    $identity ? (string)$identity->get('c_user_name') : '管理者',
                ));
                if ($error === null) {
                    $this->Flash->success(__('部屋異動予約を登録しました。'));
                    $this->redirect(['action' => 'index']);
                    return;
                }
                $this->Flash->error($error);
            } catch (\RuntimeException $e) {
                $this->Flash->error($e->getMessage());
            } catch (\Throwable $e) {
                $this->Flash->error(__('登録処理中にエラーが発生しました。'));
            }
        }

        $users       = $transferQuery->selectableUsers();
        $rooms       = $transferQuery->selectableRooms();
        $userRoomMap = $transferQuery->activeRoomsByUser();

        $this->set(compact('users', 'rooms', 'userRoomMap'));
    }

    /**
     * 異動予約キャンセル
     *
     * @param int $id スケジュールID
     */
    public function cancel(CancelRoomTransferUseCase $cancelTransfer, int $id): void
    {
        $this->request->allowMethod(['post']);

        $resource = new MRoomTransferSchedule();

        try {
            $this->Authorization->authorize($resource, 'cancel');
        } catch (ForbiddenException $e) {
            $this->Flash->error(__('この機能は管理者のみ利用できます。'));
            $this->redirect(['action' => 'index']);
            return;
        }

        $identity = $this->request->getAttribute('identity');
        try {
            $error = $cancelTransfer->execute($id, $identity ? (string)$identity->get('c_user_name') : '管理者');
            if ($error === null) {
                $this->Flash->success(__('異動予約をキャンセルしました。'));
            } else {
                $this->Flash->error($error);
            }
        } catch (DomainNotFoundException $e) {
            throw new NotFoundException('指定された予約が見つかりません。');
        } catch (\RuntimeException $e) {
            $this->Flash->error($e->getMessage());
        } catch (\Throwable $e) {
            $this->Flash->error(__('キャンセル処理中にエラーが発生しました。'));
        }

        $this->redirect(['action' => 'index']);
    }
}
