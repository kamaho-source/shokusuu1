<?php
declare(strict_types=1);

namespace App\Controller;

use App\Application\Query\RoomQueryInterface;
use App\Application\Query\UserQueryInterface;
use App\Infrastructure\Persistence\Entity\MUserInfo;
use App\Application\Port\AuditActor;
use App\Application\UseCase\AssignUserRooms\AssignUserRoomsInput;
use App\Application\UseCase\AssignUserRooms\AssignUserRoomsUseCase;
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
use App\Domain\Service\RoomAffiliationLabel;
use App\Domain\ValueObject\UserProfileOptions;
use App\Domain\ValueObject\UserRole;
use Authorization\Exception\ForbiddenException;
use Cake\Http\Exception\NotFoundException;
use Cake\Http\Response;

/**
 * 利用者の一覧・詳細・登録・編集・削除・復元・所属部屋。
 * ログイン・ログアウトは AuthController、パスワードは UserPasswordController、権限の切替は UserRoleController、
 * 一括登録は UserImportController（#989 で分けた。URL は /MUserInfo/... のまま）。
 *
 * @property \Authorization\Controller\Component\AuthorizationComponent $Authorization
 */
class MUserInfoController extends AppController
{

    protected $MUserInfo;

    public function initialize(): void
    {
        parent::initialize();
        $this->viewBuilder()->setLayout('default');
        $this->viewBuilder()->setOption('serialize', true);

        $this->MUserInfo = $this->fetchTable('MUserInfo');


        // これらのアクションは FormProtection のフォームトークン検証対象外にする。
        // CSRF 保護は CsrfProtectionMiddleware がミドルウェア層で適用済み。
        // add: 年齢セレクトが Form ヘルパー外の生 HTML のためトークン不一致になるため除外。
        // addUserRooms: JSON を返す AJAX エンドポイントのため除外。
        $this->FormProtection->setConfig('unlockedActions', [
            'add',
            'addUserRooms',
        ]);
    }

    public function index(RoomQueryInterface $roomQuery, UserQueryInterface $userQuery)
    {
        $resource = new MUserInfo();
        try {
            $this->Authorization->authorize($resource, 'index');
        } catch (ForbiddenException $e) {
            $this->Flash->error(__('あなたは閲覧権限がありません。'));
            return $this->redirect(['controller' => 'Auth', 'action' => 'login']);
        }

        $user          = $this->request->getAttribute('identity');
        $isAdmin       = UserRole::isAdmin((int)$user->i_admin);
        $isSystemAdmin = UserRole::isSystemAdmin((int)$user->i_admin);
        $currentUserId = $user->i_id_user;
        $showDeleted   = ($isAdmin || $isSystemAdmin) && $this->request->getQuery('show_deleted') === '1';

        $query = $this->MUserInfo->find()
            ->where(['i_del_flag' => $showDeleted ? 1 : 0]);

        if (!$isAdmin && !$isSystemAdmin) {
            $query->where(['i_id_user' => $currentUserId]);
        }

        $mUserInfo = $this->paginate($query, ['limit' => 200, 'maxLimit' => 200]);

        $userIds = [];
        foreach ($mUserInfo as $u) {
            $userIds[] = (int)$u->i_id_user;
        }
        $userRooms = $userQuery->activeRoomNames($userIds);

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
        try {
            $this->Authorization->authorize(new MUserInfo(), 'add');
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
        $mUserInfo = $this->MUserInfo->get((int)$id);

        try {
            $this->Authorization->authorize($mUserInfo, 'edit');
        } catch (ForbiddenException $e) {
            $this->Flash->error(__('あなたは編集権限がありません。'));
            return $this->redirect(['action' => 'index']);
        }

        $canChangeRole = $this->Authorization->can($mUserInfo, 'changeRole');
        $canEditRooms  = $this->Authorization->can($mUserInfo, 'editRooms');
        // 削除済みの人は所属部屋を変えられない（所属中になると「削除済みなのに所属中」になる）。復元すると削除前の所属に戻る
        $roomsLocked   = $userQuery->find((int)$mUserInfo->i_id_user)?->isDeleted() ?? false;
        // 部屋異動で無効化された旧所属（active_flag=1）は含めない。選択済みに含めると保存で復活する
        $selectedRooms = $userQuery->currentRoomIds((int)$mUserInfo->i_id_user);

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

    public function view(UserQueryInterface $userQuery, $id = null)
    {
        $mUserInfo = $this->MUserInfo->get((int)$id);
        try {
            $this->Authorization->authorize($mUserInfo, 'view');
        } catch (ForbiddenException $e) {
            $this->Flash->error(__('あなたは閲覧権限がありません。'));
            return $this->redirect(['action' => 'index']);
        }

        $userRooms = $userQuery->activeRoomNames([(int)$mUserInfo->i_id_user])[(int)$mUserInfo->i_id_user] ?? [];
        $canEdit   = $this->Authorization->can($mUserInfo, 'edit');

        $this->set(compact('mUserInfo', 'userRooms', 'canEdit'));
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

        if (!$deleteUser->execute(new DeleteUserInput((int)$id, $this->auditActor()))->deleted) {
            $this->Flash->error(__('ユーザー情報を削除できませんでした。'));

            return $this->redirect(['action' => 'index']);
        }
        $this->Flash->success(__('ユーザー情報が削除されました。'));

        return $this->redirect(['action' => 'index']);
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

        // (int) の型変換だと 1.5 が 1 になり、別の利用者を指してしまうため、整数の形のときだけ受け付ける
        $userId = is_string($userId) || is_int($userId) ? filter_var($userId, FILTER_VALIDATE_INT) : false;
        if ($userId === false || empty($roomNames) || !is_array($roomNames)) {
            $this->Authorization->skipAuthorization();
            $this->set(['ok' => false, 'message' => 'i_id_userとroom_names[]が必要です', '_serialize' => ['ok', 'message']]);
            return;
        }

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
