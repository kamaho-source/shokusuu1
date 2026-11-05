<?php
declare(strict_types=1);

namespace App\Controller;

use Cake\Http\ServerRequest;
use App\Application\Query\ApprovalQueryInterface;
use App\Application\Query\RoomQueryInterface;
use App\Application\Port\AuditActor;
use App\Application\UseCase\ApproveByAdmin\ApproveByAdminInput;
use App\Application\UseCase\ApproveByAdmin\ApproveByAdminUseCase;
use App\Application\UseCase\ApproveByBlockLeader\ApproveByBlockLeaderInput;
use App\Application\UseCase\ApproveByBlockLeader\ApproveByBlockLeaderUseCase;
use App\Application\UseCase\ReflectApprovedToReservation\ReflectApprovedToReservationInput;
use App\Application\UseCase\ReflectApprovedToReservation\ReflectApprovedToReservationUseCase;
use App\Application\UseCase\RejectApproval\RejectApprovalInput;
use App\Application\UseCase\RejectApproval\RejectApprovalUseCase;
use App\Domain\ValueObject\ApprovalStatus;
use App\Domain\ValueObject\UserRole;
use Cake\Core\Configure;
use Cake\Event\EventInterface;
use Cake\Http\Exception\NotFoundException;
use Cake\Http\Response;
use Cake\I18n\Date;

/**
 * 承認フロー コントローラー
 *
 * ブロック長（i_admin = 2）と管理者（i_admin = 1）向けの承認画面を提供する。
 * 認可は ApprovalPolicy を通じて Authorization::authorize() で制御する。
 */
class ApprovalController extends AppController
{
    use JsonResponseTrait;

    public function __construct(
        ServerRequest $request,
        private readonly RoomQueryInterface $roomAccessService,
        private readonly ApprovalQueryInterface $approvalQuery,
    ) {
        parent::__construct($request);
    }

    /**
     * 承認機能(Features.approval)が無効のときは全アクションを 404 にする。
     * （ダッシュボードから導線を隠しているが、直接URLアクセスも塞ぐ）
     *
     * @param \Cake\Event\EventInterface $event
     * @return Response|null 無効化された人の締め出しなど、親が返した応答
     */
    public function beforeFilter(EventInterface $event): ?Response
    {
        $response = parent::beforeFilter($event);
        if ($response !== null) {
            return $response;
        }

        if (!Configure::read('Features.approval')) {
            throw new NotFoundException('予約承認機能は現在無効です。');
        }

        return null;
    }

    public function initialize(): void
    {
        parent::initialize();
        // これらのアクションは JSON ボディを受け取る AJAX エンドポイントのため
        // FormProtection のフォームトークン検証対象外にする。
        // CSRF 保護は CsrfProtectionMiddleware がミドルウェア層で適用済み。
        $this->FormProtection->setConfig('unlockedActions', [
            'blockLeaderApprove',
            'blockLeaderReject',
            'adminApprove',
            'adminReject',
            'adminReflect',
        ]);
    }

    /**
     * GET /Approval
     *
     * 直アクセス時のフォールバック。権限に応じた承認画面へリダイレクトする。
     */
    public function index(): Response
    {
        $this->Authorization->skipAuthorization();
        $user  = $this->Authentication->getIdentity();
        $admin = (int)($user?->get('i_admin') ?? 0);

        if (UserRole::isAdmin($admin)) {
            return $this->redirect(['action' => 'adminIndex']);
        }
        if (UserRole::isBlockLeader($admin)) {
            return $this->redirect(['action' => 'blockLeaderIndex']);
        }

        $this->Flash->error('この画面を表示する権限がありません。');
        return $this->redirect(['controller' => 'Pages', 'action' => 'display', 'home']);
    }

    // ------------------------------------------------------------------
    // ブロック長用 承認一覧
    // ------------------------------------------------------------------

    /**
     * GET /Approval/blockLeaderIndex
     */
    public function blockLeaderIndex(): ?Response
    {
        $this->Authorization->authorize($this, 'blockLeaderIndex');

        $user    = $this->Authentication->getIdentity();
        $userId  = (int)$user->get('i_id_user');
        $roomIds = $this->roomAccessService->getUserRoomIds($userId);

        $filterRoomId = $this->request->getQuery('room_id') ? (int)$this->request->getQuery('room_id') : null;
        $filterStatus = $this->request->getQuery('status') !== null && $this->request->getQuery('status') !== ''
            ? (int)$this->request->getQuery('status')
            : ApprovalStatus::PENDING;
        $dateFrom = $this->request->getQuery('date_from') ?? Date::today()->startOfWeek()->format('Y-m-d');
        $dateTo   = $this->request->getQuery('date_to')   ?? Date::today()->endOfWeek()->format('Y-m-d');

        $records = $this->approvalQuery->getBlockLeaderList(
            $userId, $filterRoomId, $dateFrom, $dateTo, $filterStatus
        );

        $rooms = $this->roomAccessService->getRoomsByIds($roomIds);

        $this->set(compact('records', 'rooms', 'filterRoomId', 'filterStatus', 'dateFrom', 'dateTo'));
        return null;
    }

    /**
     * POST /Approval/blockLeaderApprove
     */
    public function blockLeaderApprove(ApproveByBlockLeaderUseCase $approve): Response
    {
        $this->Authorization->authorize($this, 'blockLeaderApprove');
        $this->request->allowMethod('post');

        $keys = (array)($this->request->getData('keys') ?? []);
        if (empty($keys)) {
            return $this->jsonError('対象が指定されていません', 400);
        }

        try {
            // ブロック長は所属している部屋だけ承認できる。管理者は全部屋（#989）
            $output = $approve->execute(new ApproveByBlockLeaderInput($keys, $this->approver(), $this->approverIsAdmin()));
            return $this->jsonResponse(['success' => $output->succeeded()]);
        } catch (\Throwable $e) {
            $this->log('blockLeaderApprove error: ' . $e->getMessage(), 'error');
            return $this->jsonError('承認処理中にエラーが発生しました。', 500);
        }
    }

    /**
     * POST /Approval/blockLeaderReject
     */
    public function blockLeaderReject(RejectApprovalUseCase $reject): Response
    {
        $this->Authorization->authorize($this, 'blockLeaderReject');
        $this->request->allowMethod('post');

        $keys = (array)($this->request->getData('keys') ?? []);
        if (empty($keys)) {
            return $this->jsonError('対象が指定されていません', 400);
        }

        try {
            // ブロック長は所属している部屋だけ差し戻せる。管理者は全部屋（#989）
            $input  = RejectApprovalInput::byBlockLeader($keys, $this->approver(), $this->request->getData('reason'), $this->approverIsAdmin());
            $output = $reject->execute($input);
            return $this->jsonResponse(['success' => $output->succeeded()]);
        } catch (\Throwable $e) {
            $this->log('blockLeaderReject error: ' . $e->getMessage(), 'error');
            return $this->jsonError('却下処理中にエラーが発生しました。', 500);
        }
    }

    // ------------------------------------------------------------------
    // 管理者用 承認一覧
    // ------------------------------------------------------------------

    /**
     * GET /Approval/adminIndex
     */
    public function adminIndex(): ?Response
    {
        $this->Authorization->authorize($this, 'adminIndex');

        $filterRoomId = $this->request->getQuery('room_id') ? (int)$this->request->getQuery('room_id') : null;
        $filterStatus = $this->request->getQuery('status') !== null && $this->request->getQuery('status') !== ''
            ? (int)$this->request->getQuery('status')
            : ApprovalStatus::BLOCK_LEADER;
        $dateFrom = $this->request->getQuery('date_from') ?? Date::today()->startOfWeek()->format('Y-m-d');
        $dateTo   = $this->request->getQuery('date_to')   ?? Date::today()->endOfWeek()->format('Y-m-d');

        $records = $this->approvalQuery->getAdminList($filterRoomId, $dateFrom, $dateTo, $filterStatus);
        $summary = $this->approvalQuery->getAdminSummary($dateFrom, $dateTo);

        $rooms = $this->roomAccessService->getAllActiveRooms();

        $this->set(compact('records', 'summary', 'rooms', 'filterRoomId', 'filterStatus', 'dateFrom', 'dateTo'));
        return null;
    }

    /**
     * POST /Approval/adminApprove
     */
    public function adminApprove(ApproveByAdminUseCase $approve): Response
    {
        $this->Authorization->authorize($this, 'adminApprove');
        $this->request->allowMethod('post');

        $keys = (array)($this->request->getData('keys') ?? []);
        if (empty($keys)) {
            return $this->jsonError('対象が指定されていません', 400);
        }

        try {
            $output = $approve->execute(new ApproveByAdminInput($keys, $this->approver()));
            return $this->jsonResponse(['success' => $output->succeeded()]);
        } catch (\Throwable $e) {
            $this->log('adminApprove error: ' . $e->getMessage(), 'error');
            return $this->jsonError('承認処理中にエラーが発生しました。', 500);
        }
    }

    /**
     * POST /Approval/adminReject
     */
    public function adminReject(RejectApprovalUseCase $reject): Response
    {
        $this->Authorization->authorize($this, 'adminReject');
        $this->request->allowMethod('post');

        $keys = (array)($this->request->getData('keys') ?? []);
        if (empty($keys)) {
            return $this->jsonError('対象が指定されていません', 400);
        }

        try {
            $output = $reject->execute(RejectApprovalInput::byAdmin($keys, $this->approver(), $this->request->getData('reason')));
            return $this->jsonResponse(['success' => $output->succeeded()]);
        } catch (\Throwable $e) {
            $this->log('adminReject error: ' . $e->getMessage(), 'error');
            return $this->jsonError('却下処理中にエラーが発生しました。', 500);
        }
    }

    /**
     * POST /Approval/adminReflect
     */
    public function adminReflect(ReflectApprovedToReservationUseCase $reflect): Response
    {
        $this->Authorization->authorize($this, 'adminReflect');
        $this->request->allowMethod('post');

        $user  = $this->Authentication->getIdentity();
        $actor = (string)($user->get('c_user_name') ?? '不明');
        $roomId   = $this->request->getData('room_id') ? (int)$this->request->getData('room_id') : null;
        $dateFrom = $this->request->getData('date_from') ?: null;
        $dateTo   = $this->request->getData('date_to') ?: null;

        try {
            $output = $reflect->execute(new ReflectApprovedToReservationInput($roomId, $dateFrom, $dateTo, $actor));
            return $this->jsonResponse(['success' => true, 'reflected_count' => $output->recordCount, 'group_count' => $output->groupCount]);
        } catch (\Throwable $e) {
            $this->log('adminReflect error: ' . $e->getMessage(), 'error');
            return $this->jsonError('反映処理中にエラーが発生しました。', 500);
        }
    }

    /**
     * 承認履歴（ログ）一覧を表示
     */
    public function approvalLog(): ?Response
    {
        $this->Authorization->skipAuthorization();
        $user = $this->Authentication->getIdentity();
        $isAdmin = UserRole::isAdmin((int)($user->get('i_admin') ?? 0));
        $isBlockLeader = UserRole::isBlockLeader((int)($user->get('i_admin') ?? 0));

        if (!$isAdmin && !$isBlockLeader) {
            $this->Flash->error('この画面を表示する権限がありません。');
            return $this->redirect(['controller' => 'Pages', 'action' => 'display', 'home']);
        }

        // ブロック長は担当部屋の履歴か、自分が承認・差し戻しした履歴だけ
        $logs = $this->approvalQuery->approvalLogs((int)$user->get('i_id_user'), $isAdmin, $this->request->getQueryParams());

        $this->set(compact('logs'));
        return null;
    }

    // ------------------------------------------------------------------
    // helpers
    // ------------------------------------------------------------------

    /** 承認者（ログイン中の利用者）。名前が無ければ「不明」（承認の更新者・監査ログの操作者に使う）。 */
    private function approver(): AuditActor
    {
        $user = $this->Authentication->getIdentity();

        return new AuditActor(
            (string)($user->get('c_user_name') ?? '不明'),
            (int)$user->get('i_id_user'),
            (string)($user->get('c_login_account') ?? ''),
            $this->getClientIp(),
        );
    }

    /** 承認者が管理者か（管理者でなければ担当部屋だけを対象にする）。 */
    private function approverIsAdmin(): bool
    {
        return UserRole::isAdmin((int)($this->Authentication->getIdentity()->get('i_admin') ?? 0));
    }
}