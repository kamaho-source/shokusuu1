<?php
declare(strict_types=1);

namespace App\Controller;

use App\Application\Query\RoomQueryInterface;
use App\Application\Query\UserQueryInterface;
use App\Infrastructure\Persistence\Entity\MUserInfo;
use App\Application\Port\AuditActor;
use App\Application\UseCase\AssignUserRooms\AssignUserRoomsInput;
use App\Application\UseCase\AssignUserRooms\AssignUserRoomsUseCase;
use App\Application\UseCase\ChangePassword\ChangePasswordInput;
use App\Application\UseCase\ChangePassword\ChangePasswordOutput;
use App\Application\UseCase\ChangePassword\ChangePasswordUseCase;
use App\Application\UseCase\CreateUser\CreateUserInput;
use App\Application\UseCase\CreateUser\CreateUserOutput;
use App\Application\UseCase\CreateUser\CreateUserUseCase;
use App\Application\UseCase\DeleteUser\DeleteUserInput;
use App\Application\UseCase\DeleteUser\DeleteUserUseCase;
use App\Application\UseCase\EditUser\EditUserInput;
use App\Application\UseCase\EditUser\EditUserOutput;
use App\Application\UseCase\EditUser\EditUserUseCase;
use App\Application\UseCase\RestoreUser\RestoreUserInput;
use App\Application\UseCase\RestoreUser\RestoreUserUseCase;
use App\Domain\Service\DeletedMembershipRule;
use App\Domain\Service\RoomAffiliationLabel;
use App\Domain\ValueObject\AccountStatus;
use App\Domain\ValueObject\RoomMembershipStatus;
use App\Domain\ValueObject\UserProfileOptions;
use App\Domain\ValueObject\UserRole;
use Authorization\Exception\ForbiddenException;
use Cake\Event\EventInterface;
use Cake\Http\Exception\NotFoundException;
use Cake\Http\Response;

class MUserInfoController extends AppController
{
    protected $MUserInfo;
    protected $MRoomInfo;


    public function initialize(): void
    {
        parent::initialize();
        $this->loadComponent('Authentication.Authentication');
        $this->viewBuilder()->setLayout('default');
        $this->viewBuilder()->setOption('serialize', true);

        $this->MUserInfo = $this->fetchTable('MUserInfo');
        $this->MRoomInfo = $this->fetchTable('MRoomInfo');


        // これらのアクションは FormProtection のフォームトークン検証対象外にする。
        // CSRF 保護は CsrfProtectionMiddleware がミドルウェア層で適用済み。
        // login/logout: セッション切れや複数タブでトークンが失効し「不正なアクセス」になるため除外。
        // add: 年齢セレクトが Form ヘルパー外の生 HTML のためトークン不一致になるため除外。
        // addUserRooms: JSON を返す AJAX エンドポイントのため除外。
        $this->FormProtection->setConfig('unlockedActions', [
            'login',
            'logout',
            'add',
            'addUserRooms',
        ]);
    }

    public function beforeFilter(EventInterface $event)
    {
        parent::beforeFilter($event);
        $this->Authentication->addUnauthenticatedActions(['login']);
    }

    public function index(RoomQueryInterface $roomQuery)
    {
        $resource = new MUserInfo();
        try {
            $this->Authorization->authorize($resource, 'index');
        } catch (ForbiddenException $e) {
            $this->Flash->error(__('あなたは閲覧権限がありません。'));
            return $this->redirect(['action' => 'login']);
        }

        $user          = $this->request->getAttribute('identity');
        $isAdmin       = UserRole::isAdmin((int)$user->i_admin);
        $isSystemAdmin = UserRole::isSystemAdmin((int)$user->i_admin);
        $currentUserId = $user->i_id_user;
        $showDeleted   = ($isAdmin || $isSystemAdmin) && $this->request->getQuery('show_deleted') === '1';

        $query = $this->MUserInfo->find()
            ->where(['i_del_flag' => $showDeleted ? 1 : 0])
            ->contain(['MUserGroup' => ['MRoomInfo']]);

        if (!$isAdmin && !$isSystemAdmin) {
            $query->where(['i_id_user' => $currentUserId]);
        }

        $mUserInfo = $this->paginate($query, ['limit' => 200, 'maxLimit' => 200]);

        $userRooms = [];
        foreach ($mUserInfo as $u) {
            $userRooms[$u->i_id_user] = $this->activeRoomNames($u->m_user_group ?? [], $u);
        }

        $totalActiveRooms = $roomQuery->countActiveRooms();
        $userRoomLabels   = [];
        foreach ($userRooms as $uid => $roomNames) {
            $userRoomLabels[$uid] = RoomAffiliationLabel::build($roomNames, $totalActiveRooms);
        }

        $canRegisterUsers = $this->Authorization->can($resource, 'add');

        $this->set(compact('mUserInfo', 'userRooms', 'userRoomLabels', 'isAdmin', 'isSystemAdmin', 'currentUserId', 'showDeleted', 'canRegisterUsers'));
    }

    /**
     * 登録画面のログインID重複チェック（GET ?c_login_account=...）。応答は {"unique": bool}。
     * 認可は add と同じ（登録できる人だけ）。判定は送信時の CreateUserUseCase と同じく削除済みの人も含む。
     * 空・配列の入力は 400、登録の権限が無ければ 403。
     *
     * @throws \Cake\Http\Exception\MethodNotAllowedException GET 以外のとき
     */
    public function checkUniqueLoginId(UserQueryInterface $userQuery): Response
    {
        $this->request->allowMethod(['get']);
        // $this->Authorization は PHPStan が型を知らない（baseline 入り）ため、型付きで受け取る
        /** @var \Authorization\Controller\Component\AuthorizationComponent $authorization */
        $authorization = $this->components()->get('Authorization');
        try {
            $authorization->authorize(new MUserInfo(), 'add');
        } catch (ForbiddenException $e) {
            return $this->loginIdCheckResponse(['success' => false, 'error' => 'この機能を利用する権限がありません。'], 403);
        }

        $loginAccount = $this->request->getQuery('c_login_account');
        if (!is_string($loginAccount) || trim($loginAccount) === '') {
            return $this->loginIdCheckResponse(['success' => false, 'error' => 'c_login_account を指定してください。'], 400);
        }

        return $this->loginIdCheckResponse(['unique' => !$userQuery->loginAccountExists($loginAccount)], 200);
    }

    /**
     * @param array<string, bool|string> $data
     */
    private function loginIdCheckResponse(array $data, int $status): Response
    {
        return $this->response
            ->withStatus($status)
            ->withType('application/json')
            // ログインIDの有無を返すので、ブラウザ・中継にキャッシュさせない
            ->withHeader('Cache-Control', 'no-store')
            ->withStringBody((string)json_encode($data, JSON_UNESCAPED_UNICODE));
    }

    public function add(CreateUserUseCase $createUser, UserQueryInterface $userQuery)
    {
        $this->request->allowMethod(['get', 'post']);

        $mUserInfo = $this->MUserInfo->newEmptyEntity();
        try {
            $this->Authorization->authorize($mUserInfo, 'add');
        } catch (ForbiddenException $e) {
            $this->Flash->error(__('あなたは追加権限がありません。'));
            return $this->redirect(['action' => 'index']);
        }

        // 管理者以外（職員・ブロック長）は児童のみ。登録先は管理者も含めて自分が所属している部屋だけ（#821）
        $identity  = $this->request->getAttribute('identity');
        $childOnly = !UserRole::isAdmin((int)($identity?->get('i_admin') ?? 0));
        $actorId   = (int)($identity?->get('i_id_user') ?? 0);
        $rooms     = $userQuery->selectableRoomsFor($actorId);
        $allowedRoomIds = array_keys($rooms);

        if ($this->request->is('post')) {
            try {
                $result = $createUser->execute(new CreateUserInput(
                    (array)$this->request->getData(),
                    $childOnly,
                    $allowedRoomIds,
                    $this->auditActor(),
                ));
                switch ($result->status) {
                    case CreateUserOutput::CREATED:
                        $this->Flash->success(__('ユーザー情報が保存されました。'));
                        return $this->redirect(['action' => 'index']);
                    case CreateUserOutput::REJECTED:
                        $this->Flash->error(__((string)$result->message));
                        break;
                    case CreateUserOutput::INVALID_FIELDS:
                        // 項目ごとの理由はフォームの各欄に出す（従来どおり、上には定型文）
                        $mUserInfo->setErrors($result->fieldErrors);
                        $this->log('userCreate error: バリデーションエラーが発生しました。', 'error');
                        $this->Flash->error(__('予期しないエラーが発生しました。もう一度お試しください。'));
                        break;
                    default:
                        $this->Flash->error(__('ユーザー情報の保存に失敗しました。もう一度お試しください。'));
                }
            } catch (\Exception $e) {
                $this->log('userCreate error: ' . $e->getMessage(), 'error');
                $this->Flash->error(__('予期しないエラーが発生しました。もう一度お試しください。'));
            }
        }

        $ages  = range(UserProfileOptions::MIN_AGE, UserProfileOptions::MAX_AGE);
        $roles = $childOnly ? [1 => UserProfileOptions::USER_LEVELS[1]] : UserProfileOptions::USER_LEVELS;
        $this->set(compact('mUserInfo', 'rooms', 'ages', 'roles'));
    }

    public function edit(EditUserUseCase $editUser, UserQueryInterface $userQuery, $id = null)
    {
        $this->request->allowMethod(['get', 'post', 'put', 'patch']);

        // ID なしは見つからない（404）。null のまま渡すと InvalidPrimaryKeyException で 500 になる
        $mUserInfo = $this->MUserInfo->get((int)$id, ['contain' => ['MUserGroup']]);

        try {
            $this->Authorization->authorize($mUserInfo, 'edit');
        } catch (ForbiddenException $e) {
            $this->Flash->error(__('あなたは編集権限がありません。'));
            return $this->redirect(['action' => 'index']);
        }

        $canChangeRole = $this->Authorization->can($mUserInfo, 'changeRole');
        // 管理者・本人以外（職員・ブロック長が児童を編集する場合）は、所属部屋を変更できない
        $canEditRooms = $canChangeRole
            || (int)$this->request->getAttribute('identity')?->get('i_id_user') === (int)$mUserInfo->i_id_user;
        // 削除済みの人は所属部屋を変えられない（所属中になると「削除済みなのに所属中」になる）。復元すると削除前の所属に戻る
        $roomsLocked = (int)$mUserInfo->i_del_flag === 1;

        $selectedRooms = [];
        foreach ($mUserInfo->m_user_group ?? [] as $group) {
            // active_flag=1 は部屋異動で無効化された旧所属。選択済みに含めると保存で復活する。
            if (RoomMembershipStatus::isActive($group->active_flag)) {
                $selectedRooms[] = $group->i_id_room;
            }
        }

        if ($this->request->is(['patch', 'post', 'put'])) {
            try {
                $result = $editUser->execute(new EditUserInput(
                    (int)$mUserInfo->i_id_user,
                    (array)$this->request->getData(),
                    $this->auditActor(),
                    $canChangeRole,
                    $canEditRooms,
                    UserRole::isAdmin((int)($this->request->getAttribute('identity')?->get('i_admin') ?? 0)),
                ));
                if ($result->status === EditUserOutput::UPDATED) {
                    $this->Flash->success(__('ユーザー情報が更新されました。'));
                    return $this->redirect(['action' => 'index']);
                }
                if ($result->status === EditUserOutput::SAVE_FAILED) {
                    // 入力欄の下に理由を出し、選んだ部屋はチェックしたまま出し直す
                    $mUserInfo->setErrors($result->fieldErrors);
                    $selectedRooms = $result->roomIds;
                }
                // 入力の誤りはその理由だけを出す（汎用の「保存に失敗」を重ねない）
                $this->Flash->error($result->message ?? __('ユーザー情報の保存に失敗しました。もう一度お試しください。'));
            } catch (\Exception $e) {
                $this->log('userEdit error: ' . $e->getMessage(), 'error');
                $this->Flash->error(__('予期しないエラーが発生しました。もう一度お試しください。'));
            }
        }

        $rooms = $userQuery->selectableRoomsFor((int)($this->request->getAttribute('identity')?->get('i_id_user') ?? 0));

        $this->set(compact('mUserInfo', 'rooms', 'selectedRooms', 'canChangeRole', 'canEditRooms', 'roomsLocked'));
    }

    public function view($id = null)
    {
        $mUserInfo = $this->MUserInfo->get((int)$id, [
            'contain' => ['MUserGroup' => ['MRoomInfo']],
        ]);
        try {
            $this->Authorization->authorize($mUserInfo, 'view');
        } catch (ForbiddenException $e) {
            $this->Flash->error(__('あなたは閲覧権限がありません。'));
            return $this->redirect(['action' => 'index']);
        }

        $userRooms = $this->activeRoomNames($mUserInfo->m_user_group ?? [], $mUserInfo);
        $canEdit   = $this->Authorization->can($mUserInfo, 'edit');

        $this->set(compact('mUserInfo', 'userRooms', 'canEdit'));
    }

    /**
     * 所属している部屋名の一覧。退出済み(active_flag=1)と削除済み部屋は含めない。
     * 一覧・詳細の表示と「全部屋所属」の判定で共通に使う。編集画面でチェックが付く部屋と同じ基準。
     * 削除済みユーザーは、削除の直前に所属していた部屋（削除で外した所属）を出す。復元で所属中に戻る部屋と同じ集合
     * （部屋異動で外れた昔の部屋は出さない。見分け方は DeletedMembershipRule）。
     *
     * @param iterable<\App\Infrastructure\Persistence\Entity\MUserGroup> $groups
     * @param \App\Infrastructure\Persistence\Entity\MUserInfo $user 所属の持ち主
     * @return list<string>
     */
    private function activeRoomNames(iterable $groups, \App\Infrastructure\Persistence\Entity\MUserInfo $user): array
    {
        $isDeletedUser = (int)$user->i_del_flag === 1;
        $groups        = is_array($groups) ? $groups : iterator_to_array($groups);
        $hasMarks      = false;
        foreach ($groups as $group) {
            $hasMarks = $hasMarks || (int)($group->i_left_by_deletion ?? 0) === 1;
        }

        $names = [];
        foreach ($groups as $group) {
            $isLeft = !RoomMembershipStatus::isActive($group->active_flag);
            if ($isDeletedUser) {
                $show = DeletedMembershipRule::isLeftByDeletion(
                    $group->active_flag === null ? null : (int)$group->active_flag,
                    $group->i_left_by_deletion === null ? null : (int)$group->i_left_by_deletion,
                    $hasMarks,
                    $group->dt_update,
                    $user->dt_update,
                );
            } else {
                $show = !$isLeft;
            }
            if (!$show
                || empty($group->m_room_info)
                || (int)($group->m_room_info->i_del_flg ?? 0) !== 0
            ) {
                continue;
            }
            $names[] = $group->m_room_info->c_room_name;
        }

        return $names;
    }

    public function delete(DeleteUserUseCase $deleteUser, UserQueryInterface $userQuery, $id = null)
    {
        $this->request->allowMethod(['post', 'delete']);
        if (!$userQuery->exists((int)$id)) {
            throw new NotFoundException(__('ユーザーが見つかりません。'));
        }

        try {
            // 削除の認可は操作者の権限だけで決まる（対象の中身は見ない）
            $this->Authorization->authorize(new MUserInfo(), 'delete');
        } catch (ForbiddenException $e) {
            $this->Flash->error(__('あなたは削除権限がありません。'));
            return $this->redirect(['action' => 'index']);
        }

        if ($deleteUser->execute(new DeleteUserInput((int)$id, $this->auditActor()))->deleted) {
            $this->Flash->success(__('ユーザー情報が削除されました。'));
        } else {
            $this->Flash->error(__('ユーザー情報を削除できませんでした。'));
        }

        return $this->redirect(['action' => 'index']);
    }

    public function login()
    {
        $this->Authorization->skipAuthorization();
        $this->request->allowMethod(['get', 'post']);
        $result = $this->Authentication->getResult();

        if ($result && $result->isValid()) {
            $user = $result->getData();

            if (AccountStatus::isDisabled($user->i_del_flag, $user->i_enable)) {
                $this->Authentication->logout();
                $this->Flash->error(__('このアカウントは無効化されています。'));
                return $this->redirect(['action' => 'login']);
            }

            // セッション固定化攻撃対策：ログイン成功時にセッションIDを再生成する
            $this->request->getSession()->renew();

            \App\Service\AuditLogService::record(
                'user',
                'user_login',
                $user->c_user_name ?? $user->c_login_account ?? '不明',
                (int)$user->i_id_user,
                'm_user_info',
                (string)$user->i_id_user,
                null,
                $this->getClientIp(),
                1,
                (string)($user->c_login_account ?? '')
            );

            if (UserRole::isAdmin((int)$user->i_admin)) {
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

        if ($this->request->is('post') && ($result === null || !$result->isValid())) {
            $status = $result ? $result->getStatus() : 'Result is null';
            $this->log('Login failed. status=' . preg_replace('/[\r\n\t]/', ' ', (string)$status), 'debug');
            $this->Flash->error(__('ユーザー名またはパスワードが正しくありません。'));
            $loginAccount = $this->dataString('c_login_account');
            // 操作者は、入力されたログインIDのアカウント（無ければ「該当アカウントなし」）
            $account = $loginAccount === '' ? null : $this->MUserInfo->find()
                ->select(['i_id_user', 'c_user_name'])
                ->where(['c_login_account' => $loginAccount])
                ->first();
            \App\Service\AuditLogService::record(
                'user',
                'user_login_failed',
                $account ? (string)$account->c_user_name : \App\Service\AuditLogService::UNKNOWN_ACCOUNT_NAME,
                $account ? (int)$account->i_id_user : 0,
                'm_user_info',
                null,
                ['login_account' => $loginAccount],
                $this->getClientIp(),
                0,
                $loginAccount,
                'ユーザー名またはパスワードが一致しません'
            );
        }
    }

    public function logout()
    {
        $this->Authorization->skipAuthorization();
        $result = $this->Authentication->getResult();
        if ($result !== null && $result->isValid()) {
            $identity = $this->request->getAttribute('identity');
            \App\Service\AuditLogService::record(
                'user',
                'user_logout',
                $identity?->get('c_user_name') ?? '不明',
                $identity ? (int)$identity->get('i_id_user') : 0,
                'm_user_info',
                $identity ? (string)$identity->get('i_id_user') : null,
                null,
                $this->getClientIp(),
                1,
                (string)($identity?->get('c_login_account') ?? '')
            );
            $this->Authentication->logout();
            $this->Flash->success('正常にログアウトされました。');
        }
        return $this->redirect(['controller' => 'MUserInfo', 'action' => 'login']);
    }

    public function adminChangePassword(ChangePasswordUseCase $changePassword, UserQueryInterface $userQuery)
    {
        $this->request->allowMethod(['get', 'post', 'put']);
        $resource = new MUserInfo();
        try {
            $this->Authorization->authorize($resource, 'adminChangePassword');
        } catch (ForbiddenException $e) {
            $this->Flash->error(__('この機能は管理者のみ利用できます。'));
            return $this->redirect(['action' => 'index']);
        }

        $users = $userQuery->passwordResetTargets();
        $selectedUserId = null;

        if ($this->request->is(['post', 'put'])) {
            $data   = $this->request->getData();
            $userId = $this->userIdParam($data['user_id'] ?? null);
            $result = $changePassword->execute(new ChangePasswordInput(
                $userId,
                (string)($data['new_password'] ?? ''),
                (string)($data['confirm_password'] ?? ''),
                $this->auditActor(),
                true,
            ));
            if ($result->status === ChangePasswordOutput::CHANGED) {
                $this->Flash->success(__('パスワードを変更しました。'));
                return $this->redirect(['action' => 'index']);
            }
            if ($result->status === ChangePasswordOutput::REJECTED) {
                $this->Flash->error((string)$result->message);
                return $this->redirect(['action' => 'adminChangePassword']);
            }
            $selectedUserId = $userId;
            $this->Flash->error(__('パスワードの変更に失敗しました。'));
        } elseif ($this->request->is('get')) {
            // 編集画面の「パスワード変更」リンクから ?user_id= 付きで遷移してきた場合、対象ユーザーを事前選択する。
            // 存在しない・削除済みユーザーIDは無視し、通常の未選択状態にフォールバックする。
            $queryUserId = $this->userIdParam($this->request->getQuery('user_id'));
            if ($queryUserId !== null && isset($users[$queryUserId])) {
                $selectedUserId = $queryUserId;
            }
        }

        $this->set(compact('users', 'selectedUserId'));
    }

    /** 送られた利用者 ID（数字の文字列だけを受け取る。'01' や '1abc' は選ばれていない扱い）。 */
    private function userIdParam(string|int|array|null $value): ?int
    {
        return is_scalar($value) && (string)(int)$value === (string)$value && (int)$value > 0 ? (int)$value : null;
    }

    public function generalPasswordReset(ChangePasswordUseCase $changePassword): ?\Cake\Http\Response
    {
        $this->request->allowMethod(['get', 'post', 'put', 'patch']);
        $identity = $this->request->getAttribute('identity');
        if (!$identity) {
            $this->Flash->error('ログインしてください。');
            return $this->redirect(['controller' => 'MUserInfo', 'action' => 'login']);
        }

        $userId = $identity->getIdentifier() ?? $identity->get('i_id_user');
        $user   = $this->MUserInfo->get($userId);

        try {
            $this->Authorization->authorize($user, 'generalPasswordReset');
        } catch (ForbiddenException $e) {
            $this->Flash->error('この操作は許可されていません。');
            return $this->redirect(['action' => 'index']);
        }

        if ($this->request->is(['post', 'put', 'patch'])) {
            $data   = (array)$this->request->getData();
            $result = $changePassword->execute(new ChangePasswordInput(
                (int)$user->i_id_user,
                (string)($data['new_password'] ?? ''),
                (string)($data['confirm_password'] ?? ''),
                $this->auditActor(),
                false,
            ));
            if ($result->status === ChangePasswordOutput::CHANGED) {
                $this->request->getSession()->renew();
                $this->Flash->success('パスワードを変更しました。');
                return $this->redirect(['controller' => 'TReservationInfo', 'action' => 'index']);
            }
            if ($result->status === ChangePasswordOutput::REJECTED) {
                $this->Flash->error((string)$result->message);
                return $this->redirect($this->request->getRequestTarget());
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
    public function addUserRooms(AssignUserRoomsUseCase $assignRooms, UserQueryInterface $userQuery)
    {
        $this->request->allowMethod(['post']);
        $this->viewBuilder()->setClassName('Json');

        $userId    = $this->request->getData('i_id_user');
        $roomNames = $this->request->getData('room_names');

        if (!is_numeric($userId) || empty($roomNames) || !is_array($roomNames)) {
            $this->Authorization->skipAuthorization();
            $this->set(['ok' => false, 'message' => 'i_id_userとroom_names[]が必要です', '_serialize' => ['ok', 'message']]);
            return;
        }

        $userId = (int)$userId;
        if (!$userQuery->isActive($userId)) {
            $this->Authorization->skipAuthorization();
            $this->set(['ok' => false, 'message' => 'ユーザーが見つかりません', '_serialize' => ['ok', 'message']]);
            return;
        }

        try {
            // 所属部屋の入れ替えの認可は操作者の権限だけで決まる（対象の中身は見ない）
            $this->Authorization->authorize(new MUserInfo(), 'addUserRooms');
        } catch (ForbiddenException $e) {
            $this->set(['ok' => false, 'message' => '権限がありません', '_serialize' => ['ok', 'message']]);
            return;
        }

        $identity = $this->request->getAttribute('identity');
        $actor    = $identity ? (string)$identity->get('c_user_name') : 'API';

        try {
            $result = $assignRooms->execute(new AssignUserRoomsInput($userId, array_values($roomNames), $actor));
            $this->set([
                'ok'      => true,
                'created' => $result->created,
                'errors'  => $result->errors,
                '_serialize' => ['ok', 'created', 'errors'],
            ]);
        } catch (\Throwable $e) {
            $this->set(['ok' => false, 'message' => $e->getMessage(), '_serialize' => ['ok', 'message']]);
        }
    }

    /**
     * 削除済みユーザーを復元する（管理者のみ）
     */
    public function restore(RestoreUserUseCase $restoreUser, UserQueryInterface $userQuery, $id = null)
    {
        $this->request->allowMethod(['post', 'put']);
        $user = $userQuery->find((int)$id);
        if ($user === null) {
            throw new NotFoundException(__('ユーザーが見つかりません。'));
        }

        try {
            // 復元の認可は操作者の権限だけで決まる（対象の中身は見ない）
            $this->Authorization->authorize(new MUserInfo(), 'restore');
        } catch (ForbiddenException $e) {
            $this->Flash->error(__('この機能は管理者のみ利用できます。'));
            return $this->redirect(['action' => 'index']);
        }

        if (!$user->isDeleted()) {
            $this->Flash->error(__('このユーザーは削除されていません。'));
            return $this->redirect(['action' => 'index', '?' => ['show_deleted' => '1']]);
        }

        $identity = $this->request->getAttribute('identity');
        $actor    = new AuditActor(
            (string)($identity->get('c_user_name') ?? 'admin'),
            (int)($identity->get('i_id_user') ?? 0),
            (string)($identity->get('c_login_account') ?? ''),
            $this->getClientIp(),
        );

        try {
            $result = $restoreUser->execute(new RestoreUserInput($user->id, $actor));
            $this->Flash->success(__('ユーザー「{0}」を復元しました。', $user->name));
            if ($result->restoredRoomCount === 0) {
                $this->Flash->warning(__('削除前の所属部屋が分からないため、所属部屋なしで復元しました。必要に応じて編集画面で所属部屋を設定してください。'));
            }
            return $this->redirect(['action' => 'index']);
        } catch (\Exception $e) {
            $this->log('userRestore error: ' . $e->getMessage(), 'error');
            $this->Flash->error(__('ユーザーの復元に失敗しました。もう一度お試しください。'));
            return $this->redirect(['action' => 'index', '?' => ['show_deleted' => '1']]);
        }
    }
}
