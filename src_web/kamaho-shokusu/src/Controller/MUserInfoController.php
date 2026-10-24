<?php
declare(strict_types=1);

namespace App\Controller;

use App\Domain\ValueObject\UserProfileOptions;
use App\Domain\ValueObject\UserRole;
use App\Service\ApiResponseService;
use App\Service\AuditLogService;
use App\Service\RoomService;
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

        // これらのアクションは FormProtection のフォームトークン検証対象外にする。
        // CSRF 保護は CsrfProtectionMiddleware がミドルウェア層で適用済み。
        // login/logout: セッション切れや複数タブでトークンが失効し「不正なアクセス」になるため除外。
        // add: 年齢セレクトが Form ヘルパー外の生 HTML のためトークン不一致になるため除外。
        // importJson 等: JSON ボディを受け取る AJAX エンドポイントのため除外。
        $this->FormProtection->setConfig('unlockedActions', [
            'login',
            'logout',
            'add',
            'importJson',
            'updateAdminStatus',
            'updateUserLevel',
            'updateSystemAdminStatus',
            'updateReportAccess',
            'addUserRooms',
        ]);
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
            $this->Flash->error(__('この機能を利用する権限がありません。'));
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
            $raw = (string)$this->request->getBody();
            if ($raw !== '') {
                try {
                    $payload = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
                } catch (\JsonException $e) {
                    throw new BadRequestException('リクエストボディが不正な JSON です。');
                }
            }
            $payload = is_array($payload) ? $payload : [];
        }
        $records = $payload['records'] ?? null;
        if (!is_array($records)) {
            throw new BadRequestException('records 配列が必要です。');
        }

        $identity   = $this->request->getAttribute('identity');
        $createUser = $identity ? $identity->get('c_user_name') : 'インポート';
        $actorId    = $identity ? (int)$identity->get('i_id_user') : 0;
        $loginId    = (string)($identity?->get('c_login_account') ?? '');

        try {
            $results = $this->userBulkImportService->import(
                $records,
                $createUser,
                $actorId,
                $this->getClientIp(),
                $loginId,
                !UserRole::isAdmin((int)($identity?->get('i_admin') ?? 0)),
                $this->allowedRoomIdsForRegistration($identity)
            );
        } catch (\Throwable $e) {
            $this->log('userBulkImport error: ' . $e->getMessage(), 'error');
            throw new BadRequestException('インポート処理中にエラーが発生しました。');
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
            $userRooms[$u->i_id_user] = $this->activeRoomNames($u->m_user_group ?? [], (int)$u->i_del_flag === 1);
        }

        $roomService      = new RoomService();
        $totalActiveRooms = $roomService->countActiveRooms();
        $userRoomLabels   = [];
        foreach ($userRooms as $uid => $roomNames) {
            $userRoomLabels[$uid] = $roomService->buildAffiliationLabel($roomNames, $totalActiveRooms);
        }

        $canRegisterUsers = $this->Authorization->can($resource, 'add');

        $this->set(compact('mUserInfo', 'userRooms', 'userRoomLabels', 'isAdmin', 'isSystemAdmin', 'currentUserId', 'showDeleted', 'canRegisterUsers'));
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
        // 管理者以外（職員・ブロック長）は児童のみ登録できる
        $identityForRole = $this->request->getAttribute('identity');
        $childOnly       = !UserRole::isAdmin((int)($identityForRole?->get('i_admin') ?? 0));
        $mUserInfo->i_user_level = $childOnly ? 1 : (int)$this->request->getData('role');
        if ($mUserInfo->i_user_level === 0) {
            $mUserInfo->i_id_staff = $this->request->getData('staff_id');
        }
        $mUserInfo->i_user_gender = (int)$this->request->getData('i_user_gender');
        $mUserInfo->i_user_rank   = (int)$this->request->getData('age_group');

        if ($this->request->is('post')) {
            $data = $this->request->getData();

            $allowedRoomIds = $this->allowedRoomIdsForRegistration($identityForRole);
            $postedRoomIds  = array_map(
                static fn($g) => (int)($g['i_id_room'] ?? 0),
                array_filter((array)($data['MUserGroup'] ?? []), 'is_array')
            );

            $age = (int)($data['age'] ?? 0);
            if ($childOnly && (string)($data['role'] ?? '') !== '1') {
                $this->Flash->error(__('管理者以外は児童のみ登録できます。'));
            } elseif ($age < UserProfileOptions::MIN_AGE || $age > UserProfileOptions::MAX_AGE
                || !isset(UserProfileOptions::AGE_GROUPS[(int)($data['age_group'] ?? 0)])
                || !isset(UserProfileOptions::GENDERS[(int)($data['i_user_gender'] ?? 0)])
            ) {
                $this->Flash->error(__('性別・年代・年齢を選択してください。'));
            } elseif ($mUserInfo->i_user_level === UserProfileOptions::STAFF_LEVEL
                && !$this->isValidStaffId(trim((string)($data['staff_id'] ?? '')))
            ) {
                $this->Flash->error(__('職員IDは9桁以内の数字で入力してください。'));
            } elseif ($allowedRoomIds !== null && $allowedRoomIds === []) {
                $this->Flash->error(__('所属している部屋がないため、ユーザーを登録できません。管理者に所属部屋の設定を依頼してください。'));
            } elseif ($allowedRoomIds !== null && array_filter($postedRoomIds) === []) {
                $this->Flash->error(__('登録先の部屋を選択してください。'));
            } elseif ($allowedRoomIds !== null && array_diff(array_filter($postedRoomIds), $allowedRoomIds) !== []) {
                $this->Flash->error(__('自分が所属していない部屋には登録できません。'));
            } elseif ($this->userCreateService->loginAccountExists($data['c_login_account'])) {
                $this->Flash->error(__('このログインIDは既に使用されています。他のIDをお試しください。'));
            } else {
                $user              = $this->request->getAttribute('identity');
                $data['c_create_user'] = $user ? $user->get('c_user_name') : '不明なユーザー';

                try {
                    // 管理者以外も登録できるため、権限・状態系の項目は POST から受け取らない
                    $mUserInfo = $this->MUserInfo->patchEntity($mUserInfo, $data, [
                        'fields' => ['c_login_account', 'c_login_passwd', 'c_user_name', 'c_create_user'],
                    ]);

                    if ($mUserInfo->hasErrors()) {
                        throw new \Exception('バリデーションエラーが発生しました。');
                    }

                    $groupData = $data['MUserGroup'] ?? [];
                    $createdBy = $user ? $user->get('c_user_name') : '不明なユーザー';
                    $actorId   = $user ? (int)$user->get('i_id_user') : 0;
                    $loginId   = (string)($user?->get('c_login_account') ?? '');

                    if ($this->userCreateService->saveWithRooms($mUserInfo, $groupData, $createdBy, $actorId, $this->getClientIp(), $loginId)) {
                        $this->Flash->success(__('ユーザー情報が保存されました。'));
                        return $this->redirect(['action' => 'index']);
                    }
                    $this->Flash->error(__('ユーザー情報の保存に失敗しました。もう一度お試しください。'));
                } catch (\Exception $e) {
                    $this->log('userCreate error: ' . $e->getMessage(), 'error');
                    $this->Flash->error(__('予期しないエラーが発生しました。もう一度お試しください。'));
                }
            }
        }

        $roomsQuery = $this->MRoomInfo->find('list', [
            'keyField'   => 'i_id_room',
            'valueField' => 'c_room_name',
        ])->where(['i_del_flg' => 0]);
        $allowedForView = $this->allowedRoomIdsForRegistration($identityForRole);
        if ($allowedForView !== null) {
            $roomsQuery->where(['i_id_room IN' => $allowedForView ?: [0]]);
        }
        $rooms = $roomsQuery->toArray();

        $ages  = range(UserProfileOptions::MIN_AGE, UserProfileOptions::MAX_AGE);
        $roles = $childOnly ? [1 => UserProfileOptions::USER_LEVELS[1]] : UserProfileOptions::USER_LEVELS;
        $this->set(compact('mUserInfo', 'rooms', 'ages', 'roles'));
    }

    /**
     * ユーザー登録で選べる部屋ID。管理者・システム管理者は制限なし(null)、
     * それ以外（職員・ブロック長）は自分が所属中(active_flag=0)の部屋のみ。
     *
     * @return list<int>|null
     */
    private function allowedRoomIdsForRegistration(?object $identity): ?array
    {
        if ($identity === null || UserRole::isAdmin((int)($identity->get('i_admin') ?? 0))) {
            return null;
        }

        $ids = $this->fetchTable('MUserGroup')->find()
            ->select(['i_id_room'])
            ->where(['i_id_user' => (int)$identity->get('i_id_user'), 'active_flag' => 0])
            ->all()
            ->map(static fn($g) => (int)$g->i_id_room)
            ->toList();

        return array_values(array_unique($ids));
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

        $canChangeRole = $this->Authorization->can($mUserInfo, 'changeRole');
        // 管理者・本人以外（職員・ブロック長が児童を編集する場合）は、所属部屋を変更できない
        $canEditRooms = $this->Authorization->can($mUserInfo, 'changeRole')
            || (int)$this->request->getAttribute('identity')?->get('i_id_user') === (int)$mUserInfo->i_id_user;

        if ($this->request->is(['patch', 'post', 'put'])) {
            $posted = $this->request->getData();
            $user   = $this->request->getAttribute('identity');
            // 権限・状態系の項目を POST から受け取らないよう、編集できる項目だけを取り出す
            // 管理者・本人以外（職員・ブロック長が児童を編集する場合）は、氏名のみ変更できる（ログインIDは認証情報のため）
            $data = [
                'c_login_account' => $canEditRooms
                    ? ($posted['c_login_account'] ?? $mUserInfo->c_login_account)
                    : $mUserInfo->c_login_account,
                'c_user_name'     => $posted['c_user_name'] ?? $mUserInfo->c_user_name,
                'c_update_user'   => $user ? $user->get('c_user_name') : '不明なユーザー',
                'dt_update'       => date('Y-m-d H:i:s'),
            ];

            $roomIds = [];
            if (!$canEditRooms) {
                foreach ($mUserInfo->m_user_group ?? [] as $group) {
                    if ((int)$group->active_flag === 0) {
                        $roomIds[] = (int)$group->i_id_room;
                    }
                }
            } elseif (!empty($posted['rooms'])) {
                foreach ($posted['rooms'] as $roomId => $activeFlag) {
                    if ($activeFlag === '1') {
                        $roomIds[] = (int)$roomId;
                    }
                }
            }

            $updatedBy = $user ? $user->get('c_user_name') : '不明なユーザー';
            $actorId   = $user ? (int)$user->get('i_id_user') : 0;
            $loginId   = (string)($user?->get('c_login_account') ?? '');

            // 年齢・権限・状態は誰も編集画面からは変えられない。
            // 役職・職員IDは、管理者が他のユーザーを編集するときだけ（$canChangeRole）。年代は管理者だけ。
            // 役職(i_user_level)は権限判定に、年代は食数の区分に使うため、本人は変えられない。
            // 性別は管理者・本人。職員は自分の職員IDを変えられる。職員・ブロック長が児童を編集するときは氏名のみ。
            $fields = ['c_login_account', 'c_user_name', 'c_update_user', 'dt_update'];
            $isAdminActor = UserRole::isAdmin((int)($user?->get('i_admin') ?? 0));
            if ($canEditRooms) {
                $fields[] = 'i_user_gender';
                $data += array_intersect_key($posted, ['i_user_gender' => 1, 'i_id_staff' => 1]);
            }
            if ($isAdminActor && $canEditRooms) {
                $fields[] = 'i_user_rank';
                $data += array_intersect_key($posted, ['i_user_rank' => 1]);
            }
            if ($canChangeRole) {
                $fields = array_merge($fields, ['i_user_level', 'i_id_staff']);
                $data += array_intersect_key($posted, ['i_user_level' => 1]);
            }
            [$data, $profileError] = $this->normalizeProfileForEdit($data, $mUserInfo->i_user_level);
            if (!$canChangeRole && $canEditRooms && $profileError === null && (int)$mUserInfo->i_user_level === UserProfileOptions::STAFF_LEVEL) {
                $fields[] = 'i_id_staff';
                [$data, $profileError] = $this->normalizeOwnStaffId($data);
            }

            try {
                if ($profileError === null && $this->userEditService->updateWithRooms($mUserInfo, $data, $roomIds, $updatedBy, $actorId, $this->getClientIp(), $loginId, $fields)) {
                    $this->Flash->success(__('ユーザー情報が更新されました。'));
                    return $this->redirect(['action' => 'index']);
                }
                // 入力の誤りはその理由だけを出す（汎用の「保存に失敗」を重ねない）
                $this->Flash->error($profileError ?? __('ユーザー情報の保存に失敗しました。もう一度お試しください。'));
            } catch (\Exception $e) {
                $this->log('userEdit error: ' . $e->getMessage(), 'error');
                $this->Flash->error(__('予期しないエラーが発生しました。もう一度お試しください。'));
            }
        }

        $rooms = $this->MRoomInfo->find('list', ['keyField' => 'i_id_room', 'valueField' => 'c_room_name'])->where(['i_del_flg' => 0])->toArray();

        $selectedRooms = [];
        if (!empty($mUserInfo->m_user_group)) {
            foreach ($mUserInfo->m_user_group as $group) {
                // active_flag=1 は部屋異動で無効化された旧所属。選択済みに含めると保存で復活する。
                if ((int)$group->active_flag !== 0) {
                    continue;
                }
                $selectedRooms[] = $group->i_id_room;
            }
        }

        $this->set(compact('mUserInfo', 'rooms', 'selectedRooms', 'canChangeRole', 'canEditRooms'));
    }

    /**
     * 編集画面の性別・年代・役職・職員IDを確かめる（管理者が保存するとき）。
     * 「未設定」（空）で保存したときは、その項目を変えない（既存ユーザーには未入力の人がいるため）。
     * 役職が職員以外なら職員IDは持たない（新規登録と同じ扱い）。
     * 職員IDの入力を求めるのは、役職を職員に変えたときだけ（職員IDの無い既存の職員も保存できるように）。
     *
     * @param array<string, mixed> $data
     * @param int|null $currentLevel 保存前の役職
     * @return array{0: array<string, mixed>, 1: string|null} [整えたデータ, エラー文]
     */
    private function normalizeProfileForEdit(array $data, ?int $currentLevel): array
    {
        foreach ([
            'i_user_gender' => UserProfileOptions::GENDERS,
            'i_user_rank'   => UserProfileOptions::AGE_GROUPS,
            'i_user_level'  => UserProfileOptions::USER_LEVELS,
        ] as $field => $options) {
            if (!array_key_exists($field, $data)) {
                continue;
            }
            if ($data[$field] === '') {
                // 「未設定」のまま保存したときは変更しない（年代は空にできないテーブルの検証があるため、消すことはしない）
                unset($data[$field]);
                continue;
            }
            if (!is_scalar($data[$field]) || !ctype_digit((string)$data[$field]) || !isset($options[(int)$data[$field]])) {
                return [$data, __('性別・年代・役職の選択が正しくありません。')];
            }
        }

        if (!array_key_exists('i_user_level', $data)) {
            return [$data, null];
        }
        if ((int)$data['i_user_level'] !== UserProfileOptions::STAFF_LEVEL) {
            $data['i_id_staff'] = null;
            return [$data, null];
        }
        $staffId = trim((string)($data['i_id_staff'] ?? ''));
        if ($staffId === '') {
            if ($currentLevel !== UserProfileOptions::STAFF_LEVEL) {
                return [$data, __('役職が職員のときは職員IDを入力してください。')];
            }
            return [$data, null];
        }
        if (!$this->isValidStaffId($staffId)) {
            return [$data, __('職員IDは9桁以内の数字で入力してください。')];
        }

        return [$data, null];
    }

    /**
     * 本人（職員）が自分の職員IDを保存するときの確認。空なら変えない。
     *
     * @param array<string, mixed> $data
     * @return array{0: array<string, mixed>, 1: string|null} [整えたデータ, エラー文]
     */
    private function normalizeOwnStaffId(array $data): array
    {
        $staffId = trim((string)($data['i_id_staff'] ?? ''));
        if ($staffId === '') {
            unset($data['i_id_staff']);
            return [$data, null];
        }
        if (!$this->isValidStaffId($staffId)) {
            return [$data, __('職員IDは9桁以内の数字で入力してください。')];
        }

        return [$data, null];
    }

    /**
     * 職員IDとして保存できるか。列は数値（int）なので、数字以外は黙って空で保存され、
     * 大きすぎる値は SQL エラーになる。9桁以内の数字に限る（新規登録・編集で共通）。
     */
    private function isValidStaffId(string $staffId): bool
    {
        return ctype_digit($staffId) && strlen($staffId) <= 9;
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
        $actorId   = $identity ? (int)$identity->get('i_id_user') : 0;
        $loginId   = (string)($identity?->get('c_login_account') ?? '');

        if (in_array((int)$isAdmin, [0, 1, 2], true)) {
            $conflict = $this->userPermissionService->findRoleConflict(
                (int)$user->i_admin, (int)$isAdmin, 1, $this->isOverwriteConfirmed($data)
            );
            if ($conflict !== null) {
                return $apiResponse->error($this->response, $conflict, 409);
            }
        }

        if ($this->userPermissionService->updatePermission($user, (int)$isAdmin, $updatedBy, $actorId, $this->getClientIp(), $loginId)) {
            return $apiResponse->success($this->response, [], '管理者権限が正常に更新されました。');
        }
        return $apiResponse->error($this->response, '管理者権限の更新に失敗しました。', 500);
    }

    public function updateSystemAdminStatus()
    {
        $this->request->allowMethod(['post']);
        $apiResponse = new ApiResponseService();

        $data          = $this->request->getData();
        $userId        = $data['i_id_user'] ?? null;
        $isSystemAdmin = $data['i_system_admin'] ?? null;

        if (is_null($userId) || is_null($isSystemAdmin)) {
            return $apiResponse->error($this->response, 'ユーザーIDまたはシステム管理者権限が指定されていません。', 400);
        }

        $user = $this->MUserInfo->find()->where(['i_id_user' => (int)$userId])->first();
        if (!$user) {
            return $apiResponse->error($this->response, '対象ユーザーが見つかりません。', 404);
        }

        try {
            $this->Authorization->authorize($user, 'updateSystemAdminStatus');
        } catch (ForbiddenException $e) {
            return $apiResponse->error($this->response, 'この操作はシステム管理者のみ実行できます。', 403);
        }

        $identity  = $this->request->getAttribute('identity');
        $updatedBy = $identity ? $identity->get('c_user_name') : '不明なユーザー';
        $actorId   = $identity ? (int)$identity->get('i_id_user') : 0;
        $loginId   = (string)($identity?->get('c_login_account') ?? '');

        $value = (int)$isSystemAdmin === 1 ? 3 : 0;
        $conflict = $this->userPermissionService->findRoleConflict(
            (int)$user->i_admin, $value, 3, $this->isOverwriteConfirmed($data)
        );
        if ($conflict !== null) {
            return $apiResponse->error($this->response, $conflict, 409);
        }
        if ($this->userPermissionService->updatePermission($user, $value, $updatedBy, $actorId, $this->getClientIp(), $loginId, true)) {
            return $apiResponse->success($this->response, [], 'システム管理者権限が正常に更新されました。');
        }
        return $apiResponse->error($this->response, 'システム管理者権限の更新に失敗しました。', 500);
    }

    /**
     * 画面で「別の権限から変更しますか？」に同意して送られたリクエストか。
     *
     * @param array<string, mixed> $data
     */
    private function isOverwriteConfirmed(array $data): bool
    {
        return filter_var($data['overwrite'] ?? false, FILTER_VALIDATE_BOOLEAN);
    }

    public function updateReportAccess()
    {
        $this->request->allowMethod(['post']);
        $apiResponse = new ApiResponseService();

        $data           = $this->request->getData();
        $userId         = $data['i_id_user'] ?? null;
        $reportAccess   = $data['i_report_access'] ?? null;

        if (is_null($userId) || is_null($reportAccess)) {
            return $apiResponse->error($this->response, 'ユーザーIDまたはレポートアクセス権が指定されていません。', 400);
        }

        $user = $this->MUserInfo->find()->where(['i_id_user' => (int)$userId])->first();
        if (!$user) {
            return $apiResponse->error($this->response, '対象ユーザーが見つかりません。', 404);
        }

        try {
            $this->Authorization->authorize($user, 'updateReportAccess');
        } catch (ForbiddenException $e) {
            return $apiResponse->error($this->response, 'この操作はシステム管理者のみ実行できます。', 403);
        }

        $identity  = $this->request->getAttribute('identity');
        $updatedBy = $identity ? $identity->get('c_user_name') : '不明なユーザー';
        $actorId   = $identity ? (int)$identity->get('i_id_user') : 0;
        $loginId   = (string)($identity?->get('c_login_account') ?? '');
        $value     = (int)$reportAccess === 1 ? 1 : 0;

        $table            = $this->MUserInfo;
        $user->i_report_access = $value;
        $user->dt_update       = date('Y-m-d H:i:s');
        $user->c_update_user   = $updatedBy;

        if ($table->save($user)) {
            AuditLogService::record(
                'user',
                'user_report_access_change',
                $updatedBy,
                $actorId,
                'm_user_info',
                (string)$user->i_id_user,
                ['target_user_name' => $user->c_user_name, 'i_report_access' => $value],
                $this->getClientIp() ?: null,
                1,
                $loginId
            );
            return $apiResponse->success($this->response, [], 'レポートアクセス権を更新しました。');
        }
        return $apiResponse->error($this->response, 'レポートアクセス権の更新に失敗しました。', 500);
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
        $actorId   = $identity ? (int)$identity->get('i_id_user') : 0;
        $loginId   = (string)($identity?->get('c_login_account') ?? '');

        if (in_array((int)$level, [0, 1, 2], true)) {
            $conflict = $this->userPermissionService->findRoleConflict(
                (int)$user->i_admin, (int)$level, 2, $this->isOverwriteConfirmed($data)
            );
            if ($conflict !== null) {
                return $apiResponse->error($this->response, $conflict, 409);
            }
        }

        if ($this->userPermissionService->updatePermission($user, (int)$level, $updatedBy, $actorId, $this->getClientIp(), $loginId)) {
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

        $userRooms = $this->activeRoomNames($mUserInfo->m_user_group ?? [], (int)$mUserInfo->i_del_flag === 1);
        $canEdit   = $this->Authorization->can($mUserInfo, 'edit');

        $this->set(compact('mUserInfo', 'userRooms', 'canEdit'));
    }

    /**
     * 現在所属している部屋名の一覧。既定では、退出済み(active_flag=1)と削除済み部屋は含めない。
     * 一覧・詳細の表示と「全部屋所属」の判定で共通に使う。編集画面でチェックが付く部屋と同じ基準。
     * 削除済みユーザーは、削除時に所属が全て退出済みになるため、削除前の所属を見られるよう退出済みも含める。
     * 削除前に異動で退出していた部屋と区別できないため、それも含まれる（復元で全所属が戻る挙動と同じ集合）。
     *
     * @param iterable<\App\Model\Entity\MUserGroup> $groups
     * @param bool $includeLeft 退出済み(active_flag=1)の部屋も含めるか
     * @return list<string>
     */
    private function activeRoomNames(iterable $groups, bool $includeLeft = false): array
    {
        $names = [];
        foreach ($groups as $group) {
            $isLeft = (int)($group->active_flag ?? 0) !== 0;
            if (($isLeft && !$includeLeft)
                || empty($group->m_room_info)
                || (int)($group->m_room_info->i_del_flg ?? 0) !== 0
            ) {
                continue;
            }
            $names[] = $group->m_room_info->c_room_name;
        }

        return $names;
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
        $actorId   = $user ? (int)$user->get('i_id_user') : 0;
        $loginId   = (string)($user?->get('c_login_account') ?? '');

        if ($this->userDeletionService->softDelete($mUserInfo, $updatedBy, $actorId, $this->getClientIp(), $loginId)) {
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

        if ($this->request->is('post') && !$result->isValid()) {
            $status = $result ? $result->getStatus() : 'Result is null';
            $this->log('Login failed. status=' . preg_replace('/[\r\n\t]/', ' ', (string)$status), 'debug');
            $this->Flash->error(__('ユーザー名またはパスワードが正しくありません。'));
            $loginAccount = (string)($this->request->getData('c_login_account') ?? '');
            \App\Service\AuditLogService::record(
                'user',
                'user_login_failed',
                $loginAccount ?: '不明',
                0,
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
        if ($result->isValid()) {
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

            if (strlen($newPassword) < 2) {
                $this->Flash->error(__('新しいパスワードは2文字以上にしてください。'));
                return $this->redirect(['action' => 'adminChangePassword']);
            }

            $selectedUser->c_login_passwd = $newPassword;

            $actorIdentity = $this->request->getAttribute('identity');
            if ($this->fetchTable('MUserInfo')->save($selectedUser)) {
                \App\Service\AuditLogService::record(
                    'user',
                    'user_password_change_admin',
                    $actorIdentity?->get('c_user_name') ?? '不明',
                    $actorIdentity ? (int)$actorIdentity->get('i_id_user') : 0,
                    'm_user_info',
                    (string)$selectedUser->i_id_user,
                    ['target_user_name' => $selectedUser->c_user_name],
                    $this->getClientIp(),
                    1,
                    (string)($actorIdentity?->get('c_login_account') ?? '')
                );
                $this->Flash->success(__('パスワードを変更しました。'));
                return $this->redirect(['action' => 'index']);
            }

            $this->Flash->error(__('パスワードの変更に失敗しました。'));
        } elseif ($this->request->is('get')) {
            // 編集画面の「パスワード変更」リンクから ?user_id= 付きで遷移してきた場合、対象ユーザーを事前選択する。
            // 存在しない・削除済みユーザーIDは無視し、通常の未選択状態にフォールバックする。
            $queryUserId = $this->request->getQuery('user_id');
            if ($queryUserId !== null && isset($users[$queryUserId])) {
                $selectedUser = $this->fetchTable('MUserInfo')->get((int)$queryUserId);
            }
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
            if (mb_strlen($newPassword) < 2) {
                $this->Flash->error('新しいパスワードは2文字以上にしてください。');
                return $this->redirect($this->request->getRequestTarget());
            }

            $user->c_login_passwd = $newPassword;

            if ($Users->save($user)) {
                $this->request->getSession()->renew();
                \App\Service\AuditLogService::record(
                    'user',
                    'user_password_change_self',
                    $user->c_user_name ?? '不明',
                    (int)$user->i_id_user,
                    'm_user_info',
                    (string)$user->i_id_user,
                    null,
                    $this->getClientIp(),
                    1,
                    (string)($user->c_login_account ?? '')
                );
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
        $actorId   = (int)($identity->get('i_id_user') ?? 0);
        $loginId   = (string)($identity->get('c_login_account') ?? '');

        try {
            $this->userRestoreService->restore($user, $updatedBy, $actorId, $this->getClientIp(), $loginId);
            $this->Flash->success(__('ユーザー「{0}」を復元しました。', $user->c_user_name));
            return $this->redirect(['action' => 'index']);
        } catch (\Exception $e) {
            $this->log('userRestore error: ' . $e->getMessage(), 'error');
            $this->Flash->error(__('ユーザーの復元に失敗しました。もう一度お試しください。'));
            return $this->redirect(['action' => 'index', '?' => ['show_deleted' => '1']]);
        }
    }
}
