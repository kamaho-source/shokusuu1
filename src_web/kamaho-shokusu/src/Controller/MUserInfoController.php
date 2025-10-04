<?php
declare(strict_types=1);

namespace App\Controller;

use Cake\Event\EventInterface;
use Cake\Http\Exception\BadRequestException;
use Cake\Log\Log;

class MUserInfoController extends AppController
{
    protected $MUserGroup;
    protected $MUserInfo;
    protected $MRoomInfo;

    public function initialize(): void
    {
        parent::initialize();
        $this->loadComponent('Authentication.Authentication');
        $this->viewBuilder()->setLayout('default');
        $this->viewBuilder()->setOption('serialize', true);

        $this->MUserGroup = $this->fetchTable('MUserGroup');
        $this->MUserInfo = $this->fetchTable('MUserInfo');
        $this->MRoomInfo = $this->fetchTable('MRoomInfo');
    }

    public function beforeFilter(EventInterface $event)
    {
        parent::beforeFilter($event);
        // 必要に応じて importForm / importJson を追加してください
        $this->Authentication->addUnauthenticatedActions(['login', 'add']);
    }

    public function importForm()
    {
        $this->viewBuilder()->setLayout('default');
        $this->viewBuilder()->setOption('serialize', true);
        $this->set('title','ユーザー一括登録');
    }

    public function import()
    {
        $this->request->allowMethod(['post']);

        $file = $this->request->getData('file');
        if(!$file || $file->getError() !== UPLOAD_ERR_OK){
            throw new BadRequestException('ファイルの受け取りに失敗しました。');
        }
        $ext = strtolower(pathinfo($file->getClientFilename(), PATHINFO_EXTENSION));
        if (!in_array($ext,['csv','xlsx','xls'],true)) {
            throw new BadRequestException('対応拡張子はxlsx/xls/csvのみです。');
        }
        $tmpFile = tempnam(sys_get_temp_dir(), 'import_');
    }

    /**
     * クライアント（ブラウザ）でパース済みの JSON を受け取り登録します。
     * 期待payload:
     * {
     *   "records": [
     *     {"login_id":"u001","name":"山田 太郎","role":"職員","password":"(任意)","_row":2},
     *     ...
     *   ]
     * }
     */
    public function importJson()
    {
        $this->request->allowMethod(['post']);
        $this->viewBuilder()->setClassName('Json');

        // JSON 取得
        $payload = $this->request->getData();
        if (empty($payload)) {
            $payload = json_decode((string)$this->request->getBody(), true) ?? [];
        }
        $records = $payload['records'] ?? null;
        if (!is_array($records)) {
            throw new BadRequestException('records 配列が必要です。');
        }

        // i_disp_no の採番準備
        $maxDispNoRow = $this->MUserInfo->find()
            ->select(['max_no' => $this->MUserInfo->find()->func()->max('i_disp_no')])
            ->first();
        $nextDispNo = ($maxDispNoRow && $maxDispNoRow->max_no !== null)
            ? ((int)$maxDispNoRow->max_no + 1)
            : 1;

        $results = [
            'processed' => 0,
            'created'   => 0,
            'skipped'   => 0,
            'failed'    => 0,
            'errors'    => [] // [rowNo => [messages...]]
        ];

        $conn = $this->MUserInfo->getConnection();
        $conn->begin();

        try {
            foreach ($records as $rec) {
                $rowNo    = (int)($rec['_row'] ?? 0);
                $loginId  = trim((string)($rec['login_id'] ?? ''));
                $name     = trim((string)($rec['name'] ?? ''));
                $roleRaw  = (string)($rec['role'] ?? '');
                $passRaw  = (string)($rec['password'] ?? '');
                $staffId  = trim((string)($rec['staff_id'] ?? ''));
                $ageRaw         = (string)($rec['age'] ?? '');
                $genderInput    = (string)($rec['i_user_gender'] ?? ($rec['gender'] ?? ''));
                $ageGroupInput  = (string)($rec['age_group'] ?? '');
                $roomName1 = trim((string)($rec['room_name1'] ?? ''));
                $roomName2 = trim((string)($rec['room_name2'] ?? ''));

                // 必須チェック（role 必須）
                if ($loginId === '' || $name === '' || $roleRaw === '') {
                    $results['failed']++;
                    $results['processed']++;
                    $results['errors'][$rowNo][] = '必須項目（login_id, name, role）のいずれかが空です。';
                    continue;
                }

                // 既存重複（c_login_account）
                if ($this->MUserInfo->exists(['c_login_account' => $loginId])) {
                    $results['skipped']++;
                    $results['processed']++;
                    $results['errors'][$rowNo][] = 'c_login_account が既に存在します。';
                    continue;
                }

                // 役割 正規化（0:職員, 1:児童, 3:その他）: role のみ使用
                $level = $this->normalizeRole($roleRaw);
                if ($level === null) {
                    $results['failed']++;
                    $results['processed']++;
                    $results['errors'][$rowNo][] = 'role の値が不正です（職員/児童/その他 または 0/1/3 を指定してください）。';
                    continue;
                }

                // 職員(0) の場合は staff_id 必須
                if ($level === 0 && $staffId === '') {
                    $results['failed']++;
                    $results['processed']++;
                    $results['errors'][$rowNo][] = 'role=職員 のため staff_id が必須です。';
                    continue;
                }

                // パスワード（未指定時は自動生成）→ ハッシュは beforeSave で実施
                if ($passRaw === '') {
                    $passRaw = bin2hex(random_bytes(6)); // 12桁
                }

                // 年齢 正規化（任意）
                $ageVal = null;
                if ($ageRaw !== '') {
                    $ageInt = (int)$ageRaw;
                    if ($ageInt > 0) {
                        $ageVal = $ageInt;
                    }
                }

                // 性別 正規化（男性=1, 女性=2 ／ 任意）
                $genderVal = null;
                if ($genderInput !== '') {
                    $g = mb_strtolower(trim((string)$genderInput), 'UTF-8');
                    if (is_numeric($g)) {
                        $gi = (int)$g;
                        if (in_array($gi, [1,2], true)) $genderVal = $gi;
                    } else {
                        if ($g === '1' || $g === '男' || $g === '男性' || $g === 'male' || $g === 'm') $genderVal = 1;
                        if ($g === '2' || $g === '女' || $g === '女性' || $g === 'female' || $g === 'f') $genderVal = 2;
                    }
                }

                // 年代グループ 正規化（1..7 ／ 任意）
                $ageGroupCode = null;
                if ($ageGroupInput !== '') {
                    $ageGroupCode = $this->normalizeAgeGroup($ageGroupInput);
                }

                // Entity 作成
                $newData = [
                    'c_login_account' => $loginId,
                    'c_user_name'     => $name,
                    'i_user_level'    => $level,
                    'c_login_passwd'  => $passRaw, // beforeSaveでハッシュ化
                    'i_del_flag'      => 0,
                    'i_enable'        => 0,
                    'i_disp_no'       => $nextDispNo++,
                    'dt_create'       => date('Y-m-d H:i:s'),
                    'c_create_user'   => $this->request->getAttribute('identity')->get('c_user_name') ?? 'インポート',
                ];
                if ($ageVal !== null) {
                    $newData['i_user_age'] = $ageVal;
                }
                if ($genderVal !== null) {
                    $newData['i_user_gender'] = $genderVal;
                }
                if ($ageGroupCode !== null) {
                    $newData['i_user_rank'] = $ageGroupCode;
                }
                if ($level === 0) {
                    $newData['i_id_staff'] = $staffId;
                }

                $entity = $this->MUserInfo->newEntity($newData);

                if ($entity->getErrors()) {
                    $results['failed']++;
                    foreach ($entity->getErrors() as $field => $msgs) {
                        foreach ($msgs as $msg) {
                            $results['errors'][$rowNo][] = "{$field}: {$msg}";
                        }
                    }
                    $results['processed']++;
                    continue;
                }

                if ($this->MUserInfo->save($entity)) {
                    $results['created']++;
                    // 部屋名から部屋IDを検索し、MUserGroupに所属情報を登録（最大2件）
                    $userId = $entity->i_id_user;
                    $roomNames = [];
                    if ($roomName1 !== '') $roomNames[] = $roomName1;
                    if ($roomName2 !== '') $roomNames[] = $roomName2;
                    foreach ($roomNames as $roomName) {
                        $room = $this->MRoomInfo->find()->where(['c_room_name' => $roomName])->first();
                        if ($room && $userId) {
                            $userGroup = $this->MUserGroup->newEntity([
                                'i_id_user' => $userId,
                                'i_id_room' => $room->i_id_room,
                                'active_flag' => 0,
                                'dt_create' => date('Y-m-d H:i:s'),
                                'c_create_user' => $this->request->getAttribute('identity')->get('c_user_name') ?? 'インポート',
                            ]);
                            $this->MUserGroup->save($userGroup);
                        } else if ($roomName !== '') {
                            $results['errors'][$rowNo][] = "部屋名 '{$roomName}' が見つかりません";
                        }
                    }
                } else {
                    $results['failed']++;
                    $results['errors'][$rowNo][] = '保存に失敗しました。';
                }

                $results['processed']++;
            }

            $conn->commit();
        } catch (\Throwable $e) {
            $conn->rollback();
            throw new BadRequestException('インポート処理でエラー: ' . $e->getMessage());
        }

        $this->set(['ok' => true, 'summary' => $results, '_serialize' => ['ok', 'summary']]);
    }


    /**
     * 役割名 → i_user_level（0:職員, 1:児童, 3:その他）へ正規化
     * 許容:
     *  - 数値: 0/1/3（全角数字も可）
     *  - 日本語: 「職員/スタッフ/教職員」→0、「児童/子ども/子供/生徒/利用者/ユーザー」→1、「その他/外部/ゲスト/臨時」→3（部分一致OK）
     *  - 英語: staff→0, child/user→1, other→3
     */
    private function normalizeRole(string $raw): ?int
    {
        // 前処理：前後空白除去・全角→半角（英数/スペース）、小文字化
        $v = trim($raw);
        if ($v === '') {
            return null;
        }
        if (function_exists('mb_convert_kana')) {
            // n:数字, a:英字, s:スペース を半角へ
            $v = mb_convert_kana($v, 'nas', 'UTF-8');
        }
        $vLower = mb_strtolower($v, 'UTF-8');

        // 1) 数値（"０"など全角数字にも対応）
        if (is_numeric($vLower)) {
            $n = (int)$vLower;
            return in_array($n, [0, 1, 3], true) ? $n : null;
        }

        // 2) 完全一致（英語/日本語の代表表記）
        $exactMap = [
            // 0: 職員
            'staff' => 0, '職員' => 0, 'スタッフ' => 0, '教職員' => 0,
            // 1: 児童/利用者
            'child' => 1, 'user' => 1, '利用者' => 1, '児童' => 1, 'こども' => 1, '子ども' => 1, '子供' => 1, '生徒' => 1, 'ユーザー' => 1,
            // 3: その他
            'other' => 3, 'その他' => 3, '外部' => 3, 'ゲスト' => 3, '臨時' => 3,'ボランティア'=>3
        ];
        if (array_key_exists($vLower, $exactMap)) {
            return $exactMap[$vLower];
        }

        // 3) 部分一致（表記ゆれ・複合語も拾う）
        $containsAny = function (string $haystack, array $needles): bool {
            foreach ($needles as $needle) {
                if ($needle === '') continue;
                if (mb_strpos($haystack, $needle, 0, 'UTF-8') !== false) {
                    return true;
                }
            }
            return false;
        };

        // 職員系 → 0
        if ($containsAny($vLower, ['職員', 'スタッフ', '教職員'])) {
            return 0;
        }
        // 児童/利用者系 → 1
        if ($containsAny($vLower, ['児童', '子ども', '子供', 'こども', '生徒', '利用者', 'ユーザー'])) {
            return 1;
        }
        // その他/外部系 → 3
        if ($containsAny($vLower, ['その他', '外部', 'ゲスト', '臨時'])) {
            return 3;
        }

        // 英語の部分一致フォールバック
        if ($containsAny($vLower, ['staff'])) return 0;
        if ($containsAny($vLower, ['child', 'user'])) return 1;
        if ($containsAny($vLower, ['other'])) return 3;

        return null;
    }

    /**
     * 年代（age_group）表記 → コード（1..7）へ正規化
     * 1:3~5才, 2:低学年, 3:中学年, 4:高学年, 5:中学生, 6:高校生, 7:大人
     */
    private function normalizeAgeGroup(string $raw): ?int
    {
        $v = trim($raw);
        if ($v === '') return null;

        // 数値なら1..7のみ許可
        if (is_numeric($v)) {
            $n = (int)$v;
            return ($n >= 1 && $n <= 7) ? $n : null;
        }

        if (function_exists('mb_convert_kana')) {
            $v = mb_convert_kana($v, 'as', 'UTF-8');
        }
        $v = str_replace(['歳','才','　'], ['','',''], $v);
        $vLower = mb_strtolower($v, 'UTF-8');

        // マップ（部分一致許容）
        $pairs = [
            ['3~5', 1], ['3-5', 1], ['3〜5', 1], ['3～5', 1],
            ['低学年', 2],
            ['中学年', 3],
            ['高学年', 4],
            ['中学生', 5],
            ['高校生', 6],
            ['大人',   7], ['成人', 7],
        ];
        foreach ($pairs as [$key, $code]) {
            if (mb_strpos($vLower, $key, 0, 'UTF-8') !== false) {
                return $code;
            }
        }
        return null;
    }

    public function index()
    {
        // ログインユーザー情報取得
        $user = $this->request->getAttribute('identity');
        $isAdmin = $user->i_admin === 1;
        $currentUserId = $user->i_id_user;

        // 管理者は全件、一般ユーザーは自分だけを取得
        $query = $this->MUserInfo->find()
            ->where(['i_del_flag' => 0])
            ->contain(['MUserGroup' => ['MRoomInfo']]);

        if (!$isAdmin) {
            $query->where(['i_id_user' => $currentUserId]);
        }

        $mUserInfo = $this->paginate($query,['limit' => 200,'maxLimit' => 200]);

        // 所属部屋データの整理
        $userRooms = [];
        foreach ($mUserInfo as $user) {
            if (!empty($user->m_user_group)) {
                foreach ($user->m_user_group as $group) {
                    if (!empty($group->m_room_info)) {
                        $userRooms[$user->i_id_user][] = $group->m_room_info->c_room_name;
                    }
                }
            } else {
                $userRooms[$user->i_id_user] = [];
            }
        }

        // ビューに必要なデータを渡す
        $this->set(compact('mUserInfo', 'userRooms', 'isAdmin', 'currentUserId'));
    }

    public function getUserRooms($userId)
    {
        if ($userId === null) {
            return ['未所属'];
        }

        $this->loadModel('MUserGroup');
        $this->loadModel('MRoomInfo');

        $userRooms = $this->MUserGroup->find()
            ->where([
                'MUserGroup.i_id_user' => $userId,
                'MUserGroup.active_flag' => 0
            ])
            ->contain(['MRoomInfo'])
            ->all();

        if ($userRooms->isEmpty()) {
            return ['未所属'];
        }

        $rooms = [];
        foreach ($userRooms as $userRoom) {
            if (!empty($userRoom->m_room_info)) {
                $rooms[] = $userRoom->m_room_info->c_room_name;
            }
        }

        return $rooms;
    }

    private function castGroupData(array $groupData): array {
        return array_map(function ($group) {
            return [
                'i_id_room' => isset($group['i_id_room']) ? (int)$group['i_id_room'] : 0,
                'c_create_user' => $group['c_create_user'],
                'dt_create' => $group['dt_create']
            ];
        }, $groupData);
    }

    public function add()
    {
        date_default_timezone_set('Asia/Tokyo');

        // i_disp_noフィールドの最大値取得
        $maxDispNoQuery = $this->MUserInfo->find()
            ->select(['max_disp_no' => $this->MUserInfo->find()->func()->max('i_disp_no')])
            ->first();
        $maxDispNo = $maxDispNoQuery ? $maxDispNoQuery->max_disp_no + 1 : 1;

        $mUserInfo = $this->MUserInfo->newEmptyEntity();
        $mUserInfo->i_del_flag = 0;
        $mUserInfo->dt_create = date('Y-m-d H:i:s');
        $mUserInfo->i_enable = 1;
        $mUserInfo->i_disp_no = $maxDispNo;
        $mUserInfo->i_user_age = (int)$this->request->getData('age');
        $mUserInfo->i_user_level = (int)$this->request->getData('role');
        if ($mUserInfo->i_user_level === 0) {
            $mUserInfo->i_id_staff = $this->request->getData('staff_id');
        }
        $mUserInfo->i_user_gender = (int)$this->request->getData('i_user_gender');
        $mUserInfo->i_user_rank = (int)$this->request->getData('age_group');

        if ($this->request->is('post')) {
            $data = $this->request->getData();

            // デフォルトユーザー名の設定
            if (empty($data['c_user_name'])) {
                $data['c_user_name'] = 'デフォルトユーザー名';
            }

            // ログインIDの重複チェック
            $existingUser = $this->MUserInfo->find()
                ->where(['c_login_account' => $data['c_login_account']])
                ->first();

            if ($existingUser) {
                $this->Flash->error(__('このログインIDは既に使用されています。他のIDをお試しください。'));
            } else {
                // 作成ユーザーの設定
                $user = $this->request->getAttribute('identity');
                $data['c_create_user'] = $user ? $user->get('c_user_name') : '不明なユーザー';

                try {
                    // patchEntityでMUserInfoにデータをパッチ
                    $mUserInfo = $this->MUserInfo->patchEntity($mUserInfo, $data);

                    // バリデーションエラーの確認
                    if ($mUserInfo->hasErrors()) {
                        throw new \Exception('バリデーションエラーが発生しました。');
                    }

                    // ユーザー情報の保存
                    if ($this->MUserInfo->save($mUserInfo)) {
                        $this->Flash->success(__('ユーザー情報が保存されました。'));
                        $i_id_user = $mUserInfo->i_id_user;

                        // MUserGroupデータを手動で作成・保存
                        $userGroups = [];
                        foreach ($data['MUserGroup'] as $groupData) {
                            if (!empty($groupData['i_id_room'])) {
                                $userGroups[] = $this->MUserGroup->newEntity([
                                    'i_id_user' => (int)$i_id_user,
                                    'i_id_room' => (int)$groupData['i_id_room'],
                                    'active_flag' => 0,
                                    'dt_create' => date('Y-m-d H:i:s'),
                                    'c_create_user' => $user ? $user->get('c_user_name') : '不明なユーザー'
                                ]);
                            }
                        }

                        // userGroupsが空でない場合に保存を実行
                        if (!empty($userGroups)) {
                            if ($this->MUserGroup->saveMany($userGroups)) {
                                $this->Flash->success(__('部屋の所属情報が保存されました。'));
                            } else {
                                $this->Flash->error(__('部屋の所属情報の保存に失敗しました。'));
                            }
                        }

                        return $this->redirect(['action' => 'index']);
                    } else {
                        $this->Flash->error(__('ユーザー情報の保存に失敗しました。もう一度お試しください。'));
                    }
                } catch (\Exception $e) {
                    $this->Flash->error(__('予期しないエラーが発生しました。もう一度お試しください。'));
                }
            }
        }

        $rooms = $this->MRoomInfo->find('list', [
            'keyField' => 'i_id_room',
            'valueField' => 'c_room_name'
        ])->toArray();

        $ages = range(1, 80);
        $roles = [0 => '職員', 1 => '児童', 3 => 'その他'];
        $this->set(compact('mUserInfo', 'rooms', 'ages', 'roles'));
    }

    public function edit($id = null)
    {
        date_default_timezone_set('Asia/Tokyo');

        $mUserInfo = $this->MUserInfo->get($id, [
            'contain' => ['MUserGroup']
        ]);

        if ($this->request->is(['patch', 'post', 'put'])) {
            $data = $this->request->getData();
            // フィールド名の修正とnullチェック (c_user_nameがnullの場合のデフォルト値を設定)
            $data['c_user_name'] = $data['c_user_name'] ?? 'デフォルトユーザー名';
            $user = $this->request->getAttribute('identity');
            $data['c_update_user'] = $user ? $user->get('c_user_name') : '不明なユーザー';
            $data['dt_update'] = date('Y-m-d H:i:s');

            // MUserGroupデータのセットアップ
            $newUserGroups = [];
            if (!empty($data['rooms'])) {
                foreach ($data['rooms'] as $roomId => $activeFlag) {
                    if ($activeFlag === '1') {
                        $newUserGroups[] = $this->MUserInfo->MUserGroup->newEntity([
                            'i_id_user' => $id,
                            'i_id_room' => (int)$roomId,
                            'active_flag' => 0, // 元のコードのロジックに従って 0 を設定
                            'dt_create' => date('Y-m-d H:i:s'),
                            'dt_update' => date('Y-m-d H:i:s'),
                            'c_update_user' => $user ? $user->get('c_user_name') : '不明なユーザー',
                        ]);
                    }
                }
            }

            // トランザクション開始
            $conn = $this->MUserInfo->getConnection();
            $conn->begin();

            try {
                // 現在のMUserGroup関係を削除
                $this->MUserInfo->MUserGroup->deleteAll(['i_id_user' => $id]);

                // パッチを適用して関連付けを設定
                $mUserInfo = $this->MUserInfo->patchEntity($mUserInfo, $data, ['associated' => ['MUserGroup']]);
                $mUserInfo->m_user_group = $newUserGroups;

                // 保存処理
                if ($this->MUserInfo->save($mUserInfo, ['associated' => ['MUserGroup']])) {
                    $conn->commit();
                    $this->Flash->success(__('ユーザー情報が更新されました。'));
                    $mUserInfo->dt_update = date('Y-m-d H:i:s');
                    return $this->redirect(['action' => 'index']);
                } else {
                    // デバッグメッセージ2: 保存失敗
                    $this->Flash->error(__('ユーザー情報の保存に失敗しました。もう一度お試しください。'));
                    $conn->rollback();
                }
            } catch (\Exception $e) {
                // ロールバックを行い、エラー処理
                $conn->rollback();
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
        // POSTメソッドのみを許可
        $this->request->allowMethod(['post']);

        // リクエストデータを取得
        $data = $this->request->getData(); // JSONデータから取得
        $userId = $data['i_id_user'] ?? null;
        $isAdmin = $data['i_admin'] ?? null;

        // 必須データがない場合、BadRequestを返す
        if (is_null($userId) || is_null($isAdmin)) {
            return $this->response->withType('application/json')
                ->withStatus(400)
                ->withStringBody(json_encode(['success' => false, 'message' => 'ユーザーIDまたは管理者権限が指定されていません。']));
        }

        // ユーザーデータを取得し更新処理を実行
        $this->fetchTable('MUserInfo');
        $user = $this->MUserInfo->get($userId); // 対象ユーザーを取得

        if (!$user) {
            return $this->response->withType('application/json')
                ->withStringBody(json_encode(['success' => false, 'message' => '対象ユーザーが見つかりません。']));
        }

        // 権限を更新
        $user->i_admin = (int)$isAdmin;
        $user->dt_update = date('Y-m-d H:i:s');
        $user->c_update_user = $this->request->getAttribute('identity')->get('c_user_name') ?? '不明なユーザー';
        if ($this->MUserInfo->save($user)) {
            return $this->response->withType('application/json')
                ->withStringBody(json_encode(['success' => true, 'message' => '管理者権限が正常に更新されました。']));
        } else {
            return $this->response->withType('application/json')
                ->withStringBody(json_encode(['success' => false, 'message' => '管理者権限の更新に失敗しました。']));
        }
    }

    public function view($id = null)
    {
        $user = $this->request->getAttribute('identity');
        $isAdmin = $user->i_admin === 1;
        $currentUserId = $user->i_id_user;

        // 管理者か、自分自身でない場合は拒否
        if (!$isAdmin && (int)$id !== (int)$currentUserId) {
            $this->Flash->error(__('あなたは閲覧権限がありません。'));
            return $this->redirect(['action' => 'index']);
        }

        $mUserInfo = $this->MUserInfo->get($id, [
            'contain' => ['MUserGroup' => ['MRoomInfo']]
        ]);

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
        date_default_timezone_set('Asia/Tokyo');
        $this->request->allowMethod(['post', 'delete']);
        $mUserInfo = $this->MUserInfo->get($id);
        $mUserInfo->i_del_flag = 1;
        $mUserInfo->i_enable = 1; // i_enableを1(無効)に設定
        $mUserInfo->i_enable = 1; // i_enableを1(無効)に設定
        $mUserInfo->dt_update = date('Y-m-d H:i:s');
        $user = $this->request->getAttribute('identity');
        $mUserInfo->c_update_user = $user ? $user->get('c_user_name') : '不明なユーザー';
        //MUserGroupに登録されている部屋所属情報のactive_flagを0から1に変更
        $userGroups = $this->MUserGroup->find()
            ->where(['i_id_user' => $id, 'active_flag' => 0])
            ->all();
        foreach ($userGroups as $userGroup) {
            $userGroup->active_flag = 1;
            $userGroup->dt_update = date('Y-m-d H:i:s');
            $userGroup->c_update_user = $user ? $user->get('c_user_name') : '不明なユーザー';
            $this->MUserGroup->save($userGroup);
        }

        if ($this->MUserInfo->save($mUserInfo)) {
            $this->Flash->success(__('ユーザー情報が削除されました。'));
        } else {
            $this->Flash->error(__('ユーザー情報を削除できませんでした。'));
        }

        return $this->redirect(['action' => 'index']);
    }

    public function addRoomToUser($userId)
    {
        $user = $this->request->getAttribute('identity');
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
        if ($this->MUserInfo->deleteUserRoom($userId, $roomId)) {
            $this->Flash->success(__('部屋の関連が削除されました。'));
        } else {
            $this->Flash->error(__('部屋の関連を削除できませんでした。'));
        }

        return $this->redirect(['action' => 'view', $userId]);
    }

    public function login()
    {
        $this->request->allowMethod(['get', 'post']);
        $result = $this->Authentication->getResult();

        if ($result && $result->isValid()) {
            // ログインユーザーが削除済みかチェック
            $user = $result->getData(); // ログイン成功後のユーザーデータを取得

            if ($user->i_del_flag === 1 || $user->i_enable === 1) {
                // ユーザーが削除済みの場合はログインを拒否
                $this->Authentication->logout(); // ログイン状態を解除
                $this->Flash->error(__('このアカウントは無効化されています。'));

                return $this->redirect(['action' => 'login']); // ログイン画面にリダイレクト
            }

            // 削除されていない場合は通常のリダイレクト処理
            $redirect = $this->request->getQuery('redirect', [
                'controller' => 'TReservationInfo',
                'action' => 'index',
            ]);
            return $this->redirect($redirect);
        }

        if ($this->request->is('post') && !$result->isValid()) {
            // デバッグのため値を取得
            $status = $result ? $result->getStatus() : 'Result is null';
            $data = $result ? (array)$result->getData() : ['Result is null'];

            // デバッグログに文字列変換して出力
            $this->log(print_r($status, true), 'debug');
            $this->log(print_r($data, true), 'debug');
            $this->log(print_r($this->request->getData(), true), 'debug');

            $this->Flash->error(__('ユーザー名またはパスワードが正しくありません。'));
        }
    }

    public function logout()
    {
        $result = $this->Authentication->getResult();
        if ($result->isValid()) {
            $this->Authentication->logout();
            $this->Flash->success('正常にログアウトされました。');
        }
        return $this->redirect(['controller' => 'MUserInfo', 'action' => 'login']);
    }

    public function adminChangePassword()
    {
        // すべてのユーザーを取得（リスト表示用）
        $users = $this->fetchTable('MUserInfo')->find('list', [
            'keyField' => 'i_id_user',
            'valueField' => 'c_user_name'
        ])->where(['i_del_flag' => 0])->toArray();

        $selectedUser = null;

        if ($this->request->is(['post', 'put'])) {
            $data = $this->request->getData();
            $this->log('受信データ: ' . json_encode($data, JSON_UNESCAPED_UNICODE), 'debug');

            $userId = $data['user_id'] ?? null;
            $newPassword = $data['new_password'] ?? '';
            $confirmPassword = $data['confirm_password'] ?? '';

            if (!$userId || !isset($users[$userId])) {
                $this->Flash->error(__('ユーザーを選択してください。'));
                return $this->redirect(['action' => 'adminChangePassword']);
            }

            $selectedUser = $this->fetchTable('MUserInfo')->get($userId);

            // パスワードバリデーション
            if ($newPassword !== $confirmPassword) {
                $this->Flash->error(__('新しいパスワードが一致しません。'));
                return $this->redirect(['action' => 'adminChangePassword']);
            }

            if (strlen($newPassword) < 6) {
                $this->Flash->error(__('新しいパスワードは6文字以上にしてください。'));
                return $this->redirect(['action' => 'adminChangePassword']);
            }

            // **現在のパスワードをログに記録**
            $this->log('現在のデータベースのパスワード: ' . $selectedUser->c_login_passwd, 'debug');

            // **入力された平文パスワードをログに記録**
            $this->log('入力された平文のパスワード: ' . $newPassword, 'debug');

            // モデルでbeforeSaveハッシュ化する前提の場合は平文をセット
            // もし beforeSave が無い場合は以下の1行を使用してください:
            // $selectedUser->c_login_passwd = (new DefaultPasswordHasher())->hash($newPassword);
            $selectedUser->c_login_passwd = $newPassword;

            if ($this->fetchTable('MUserInfo')->save($selectedUser)) {
                $this->log('パスワード変更完了: ユーザーID ' . $selectedUser->i_id_user, 'debug');

                // **保存後のデータベースのパスワードをログに記録**
                $savedUser = $this->fetchTable('MUserInfo')->get($userId);
                $this->log('保存後のデータベースのパスワード: ' . $savedUser->c_login_passwd, 'debug');

                $this->Flash->success(__('パスワードを変更しました。'));
                return $this->redirect(['action' => 'index']);
            }

            $this->Flash->error(__('パスワードの変更に失敗しました。'));
        }

        $this->set(compact('users', 'selectedUser'));
    }


    public function generalPasswordReset(): ?\Cake\Http\Response
    {
        $identity = $this->request->getAttribute('identity');
        if (!$identity) {
            $this->Flash->error('ログインしてください。');
            return $this->redirect(['controller' => 'MUserInfo', 'action' => 'login']);
        }

        $userId = $identity->getIdentifier() ?? $identity->get('i_id_user');

        $Users = $this->fetchTable('MUserInfo');
        $user  = $Users->get($userId);

        if ($this->request->is(['post', 'put', 'patch'])) {
            $data = (array)$this->request->getData();

            $newPassword     = (string)($data['new_password'] ?? '');
            $confirmPassword = (string)($data['confirm_password'] ?? '');

            // 入力チェック（4文字以上 & 一致のみ）
            if ($newPassword !== $confirmPassword) {
                $this->Flash->error('新しいパスワードが一致しません。');
                return $this->redirect($this->request->getRequestTarget());
            }
            if (mb_strlen($newPassword) < 4) {
                $this->Flash->error('新しいパスワードは4文字以上にしてください。');
                return $this->redirect($this->request->getRequestTarget());
            }

            // ★ beforeSave でハッシュ化される前提：平文を代入
            $user->c_login_passwd = $newPassword;

            if ($Users->save($user)) {
                $this->request->getSession()->renew(); // セッション再生成
                $this->Flash->success('パスワードを変更しました。');
                return $this->redirect(['controller'=>'TReservationInfo','action' => 'index']);
            }

            $this->Flash->error('パスワードの変更に失敗しました。');
        }

        $this->set(compact('user'));
        return null;
    }

    /**
     * ユーザーの所属部屋登録API
     * POST: i_id_user, room_names[]
     * 既存所属はactive_flag=1に更新し、新規所属をactive_flag=0で登録
     * 最大2部屋まで
     */
    public function addUserRooms()
    {
        $this->request->allowMethod(['post']);
        $this->viewBuilder()->setClassName('Json');
        $userId = $this->request->getData('i_id_user');
        $roomNames = $this->request->getData('room_names');
        if (!is_numeric($userId) || empty($roomNames) || !is_array($roomNames)) {
            $this->set(['ok' => false, 'message' => 'i_id_userとroom_names[]が必要です', '_serialize' => ['ok','message']]);
            return;
        }
        $userId = (int)$userId;
        $roomNames = array_slice($roomNames, 0, 2); // 最大2部屋
        $user = $this->MUserInfo->find()->where(['i_id_user' => $userId, 'i_del_flag' => 0])->first();
        if (!$user) {
            $this->set(['ok' => false, 'message' => 'ユーザーが見つかりません', '_serialize' => ['ok','message']]);
            return;
        }
        $conn = $this->MUserInfo->getConnection();
        $conn->begin();
        $errors = [];
        try {
            // 既存所属（active_flag=0）をactive_flag=1に更新
            $oldGroups = $this->MUserGroup->find()->where(['i_id_user' => $userId, 'active_flag' => 0])->all();
            foreach ($oldGroups as $group) {
                $group->active_flag = 1;
                $group->dt_update = date('Y-m-d H:i:s');
                $group->c_update_user = $this->request->getAttribute('identity')->get('c_user_name') ?? 'API';
                $this->MUserGroup->save($group);
            }
            // 新規所属登録
            $created = 0;
            foreach ($roomNames as $roomName) {
                $room = $this->MRoomInfo->find()->where(['c_room_name' => $roomName])->first();
                if ($room) {
                    $newGroup = $this->MUserGroup->newEntity([
                        'i_id_user' => $userId,
                        'i_id_room' => $room->i_id_room,
                        'active_flag' => 0,
                        'dt_create' => date('Y-m-d H:i:s'),
                        'c_create_user' => $this->request->getAttribute('identity')->get('c_user_name') ?? 'API',
                    ]);
                    if ($this->MUserGroup->save($newGroup)) {
                        $created++;
                    } else {
                        $errors[] = "部屋 '{$roomName}' の登録に失敗";
                    }
                } else {
                    $errors[] = "部屋名 '{$roomName}' が見つかりません";
                }
            }
            $conn->commit();
            $this->set(['ok' => true, 'created' => $created, 'errors' => $errors, '_serialize' => ['ok','created','errors']]);
        } catch (\Throwable $e) {
            $conn->rollback();
            $this->set(['ok' => false, 'message' => $e->getMessage(), '_serialize' => ['ok','message']]);
        }
    }
}
