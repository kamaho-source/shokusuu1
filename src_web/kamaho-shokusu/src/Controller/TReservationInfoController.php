<?php
declare(strict_types=1);

namespace App\Controller;

use Cake\Http\Exception\BadRequestException;
use Cake\Http\Exception\NotFoundException;
use Cake\Http\Exception\UnauthorizedException;
use Cake\Core\Configure;
use Cake\I18n\FrozenDate;
use Cake\I18n\FrozenTime;
use Cake\Log\Log;
use App\Model\Domain\LastMinuteChangeService;
use App\Service\ReservationCopyService;
use Cake\ORM\Exception\PersistenceFailedException;
use Cake\Routing\Router;

/**
 * TReservationInfo コントローラー
 *
 * @property \App\Model\Table\TReservationInfoTable $TReservationInfo
 * @property \App\Model\Table\MRoomInfoTable $MRoomInfo
 * @property \App\Model\Table\MUserInfoTable $MUserInfo
 * @property \App\Model\Table\MUserGroupTable $MUserGroup
 * @property \App\Model\Table\TIndividualReservationInfoTable $TIndividualReservationInfo
 *
 *
 */
class TReservationInfoController extends AppController
{

    protected $MUserGroup;
    protected $MUserInfo;
    protected $MRoomInfo;
    protected $TIndividualReservationInfo;
    protected $TReservationInfo;
    private LastMinuteChangeService $lastMinuteChangeService;


    /**
     * initialize メソッド
     *
     * コントローラーの初期化処理を行います。
     * 必要なモデルをロードし、コンポーネントを設定します。
     */
    public function initialize(): void
    {
        parent::initialize();

        // ★ すべて代入
        $this->TReservationInfo           = $this->fetchTable('TReservationInfo');
        $this->MRoomInfo                  = $this->fetchTable('MRoomInfo');
        $this->MUserInfo                  = $this->fetchTable('MUserInfo');
        $this->MUserGroup                 = $this->fetchTable('MUserGroup');
        $this->TIndividualReservationInfo = $this->fetchTable('TIndividualReservationInfo');

        $this->lastMinuteChangeService = new LastMinuteChangeService();
        $this->loadComponent('Flash');

        // ★ これがあると全アクションが“勝手に JSON 化”されやすいので外す
        // $this->viewBuilder()->setOption('serialize', true);
        $this->viewBuilder()->setLayout('default');

        if (isset($this->FormProtection)) {
            $this->FormProtection->setConfig('unlockedActions', ['toggle']);
        }
    }


    /**
     * 予約日のバリデーションを行う
     *
     * @param string|null $reservationDate 検証する予約日
     * @return string|bool 予約可能な場合はtrue、不可の場合はエラーメッセージ
     */
    /**
     * 予約日のバリデーションを行う
     *
     * 「きょうから15日目以降」を許可に統一
     */
    private function validateReservationDate($reservationDate)
    {
        if (empty($reservationDate)) {
            return '予約日が指定されていません。';
        }

        try {
            $reservationDateObj = new FrozenDate($reservationDate);
        } catch (\Exception $e) {
            return '無効な日付フォーマットです。';
        }

        $today   = FrozenDate::today();
        $minDate = $today->addDays(15); // ← 統一：きょうから15日目以降

        if ($reservationDateObj < $minDate) {
            return sprintf(
                '通常発注は「きょうから15日目以降」のみ登録できます（%s 以降）。',
                $minDate->i18nFormat('yyyy-MM-dd')
            );
        }

        return true;
    }




    /**
     * インデックスメソッド
     *
     * @return \Cake\Http\Response|null|void ビューをレンダリングする
     */
// … namespace / use 句は既存のまま …

    public function index()
    {
        /* ========== 基本情報 ========== */
        $authUser = $this->Authentication->getIdentity();
        if (!$authUser) {
            throw new UnauthorizedException('ログインが必要です。');
        }
        $userId   = $authUser->get('i_id_user');
        $user     = $this->MUserInfo->get($userId);
        $today = FrozenDate::today();

        //ユーザーの部屋の所属情報を取得（複数部屋の場合も考慮）
        $userGroups = $this->MUserGroup->find()
            ->select(['i_id_room'])
            ->where(['i_id_user' => $userId])
            ->toArray();
        
        // 所属部屋IDリストを作成
        $userRoomIds = [];
        foreach ($userGroups as $group) {
            if ($group->i_id_room) {
                $userRoomIds[] = $group->i_id_room;
            }
        }
        
        // 後方互換性のため、最初の部屋IDを$userRoomIdに設定
        $userRoomId = !empty($userRoomIds) ? $userRoomIds[0] : null;

        /* ========== ここから: 部屋セレクト用の $rooms を必ず用意 ========== */
        $rooms = [];

        if ((int)($user->i_admin ?? 0) === 1) {
            // 管理者：全室をリスト（id => name）
            // ★ 修正ポイント：i_sort が無い環境で 1054 が出ないように、存在確認してから order に指定
            //    - i_sort があれば i_sort → i_id_room
            //    - display_order があれば display_order → i_id_room
            //    - どちらも無ければ c_room_name → i_id_room
            $roomOrder = ['i_id_room' => 'ASC'];
            try {
                $schema = $this->MRoomInfo->getSchema();
                if (method_exists($schema, 'hasColumn')) {
                    if ($schema->hasColumn('i_sort')) {
                        $roomOrder = ['i_sort' => 'ASC', 'i_id_room' => 'ASC'];
                    } elseif ($schema->hasColumn('display_order')) {
                        $roomOrder = ['display_order' => 'ASC', 'i_id_room' => 'ASC'];
                    } elseif ($schema->hasColumn('c_room_name')) {
                        $roomOrder = ['c_room_name' => 'ASC', 'i_id_room' => 'ASC'];
                    }
                }
            } catch (\Throwable $e) {
                // 取得に失敗したら安全側で id 昇順
                $roomOrder = ['i_id_room' => 'ASC'];
            }

            $rooms = $this->MRoomInfo->find('list', [
                'keyField'   => 'i_id_room',
                'valueField' => 'c_room_name',
            ])
                ->orderBy($roomOrder)
                ->toArray();
        } else {
            // 非管理者：所属部屋のみ
            if ($userRoomId !== null) {
                $room = $this->MRoomInfo->find()
                    ->select(['i_id_room', 'c_room_name'])
                    ->where(['i_id_room' => $userRoomId])
                    ->first();
                $rooms = $room ? [$room->i_id_room => $room->c_room_name] : [];
            }
            // 所属が取れなかった場合は空配列のまま（ビュー側で「所属全部屋」の空optionは出さない）
        }
        /* ========== ここまで: $rooms 準備 ========== */

        /* ========== 14 日前境界 ========== */
        $borderDate = \Cake\I18n\FrozenDate::today()->modify('+14 days');

        /* =====================================================
         * ① 全利用者の食数集計（朝昼夜弁当）
         *    管理者：全部屋、管理者以外の職員：所属部屋のみ
         *    15 日より先 → eat_flag、14 日前以内 → i_change_flag
         * ===================================================== */
        $query = $this->TIndividualReservationInfo->find()
            ->select([
                'd_reservation_date',
                'i_reservation_type',
                'eat_flag',
                'i_change_flag'
            ]);

        // 管理者以外の職員は所属部屋のみに制限（複数部屋対応）
        $isAdmin = (int)($user->i_admin ?? 0) === 1;
        if (!$isAdmin && !empty($userRoomIds)) {
            $query->where(['i_id_room IN' => $userRoomIds]);
        }

        $rows = $query->toArray();

        // [yyyy-mm-dd][1|2|3|4] = 人数
        $mealDataArray = [];

        foreach ($rows as $r) {
            $dateStr = $r->d_reservation_date->format('Y-m-d');
            $type    = (int)$r->i_reservation_type;

            // 実効フラグ
            $effective = ($r->d_reservation_date <= $borderDate)
                ? (int)$r->i_change_flag
                : (int)$r->eat_flag;

            if ($effective !== 1) {
                continue;                       // 食べないなら集計しない
            }

            if (!isset($mealDataArray[$dateStr])) {
                $mealDataArray[$dateStr] = [1 => 0, 2 => 0, 3 => 0, 4 => 0];
            }
            $mealDataArray[$dateStr][$type]++;  // 人数加算
        }

        /* =====================================================
         * ② 自分の予約詳細（朝昼夜弁当）
         * ===================================================== */
        $myRows = $this->TIndividualReservationInfo->find()
            ->select([
                'd_reservation_date',
                'i_reservation_type',
                'eat_flag',
                'i_change_flag'
            ])
            ->where(['i_id_user' => $userId])
            ->toArray();

        // キー変換用
        $mealKeys = [1 => 'breakfast', 2 => 'lunch', 3 => 'dinner', 4 => 'bento'];

        // [yyyy-mm-dd] = ['breakfast'=>?, 'lunch'=>?, 'dinner'=>?, 'bento'=>?]
        $myReservationDetails = [];

        foreach ($myRows as $r) {
            $dateStr = $r->d_reservation_date->format('Y-m-d');
            $key     = $mealKeys[$r->i_reservation_type];

            if (!isset($myReservationDetails[$dateStr])) {
                $myReservationDetails[$dateStr] = [
                    'breakfast' => null,
                    'lunch'     => null,
                    'dinner'    => null,
                    'bento'     => null,
                ];
            }

            $effective = ($r->d_reservation_date <= $borderDate)
                ? (int)$r->i_change_flag
                : (int)$r->eat_flag;

            $myReservationDetails[$dateStr][$key] = $effective;
        }

        /* ========== 自分が“食べる”日付一覧（カレンダー緑表示用） ========== */
        $myReservationDates = [];
        foreach ($myReservationDetails as $date => $meals) {
            if (in_array(1, $meals, true)) {
                $myReservationDates[] = $date;
            }
        }
        sort($myReservationDates);
        //職員情報を取得する
        $staff_user = $this->MUserGroup->find()
            ->select(['MUserInfo.c_user_name', 'MUserInfo.i_id_staff', 'MUserInfo.i_admin'])
            ->contain(['MUserInfo'])
            ->where(['MUserGroup.i_id_user' => $userId])
            ->toArray();

        /* ========== ビューへ ========== */
        $this->set(compact(
            'mealDataArray',
            'myReservationDates',
            'myReservationDetails',
            'user',
            'userRoomId',
            'rooms',
            'today',
            'staff_user'
        ));
    }
    /**
     * イベントメソッド - FullCalendarで使用するイベントデータを提供する
     * @return \Cake\Http\Response|null|void JSONレスポンスを返す
     */
    public function events()
    {
        // GET のみ許可（"ajax" は HTTPメソッドではないので allowMethod には入れない）
        $this->request->allowMethod(['get']);
        $isAjax = $this->request->is('ajax');

        // 14日前境界を考慮して、実効フラグ(≤14日: i_change_flag, >14日: eat_flag)でカウント
        $borderDate = FrozenDate::today()->modify('+14 days');

        $rows = $this->TIndividualReservationInfo->find()
            ->select(['d_reservation_date', 'eat_flag', 'i_change_flag'])
            ->toArray();

        $dateCounts = [];
        foreach ($rows as $r) {
            $dateStr = $r->d_reservation_date->format('Y-m-d');
            $effective = ($r->d_reservation_date <= $borderDate)
                ? (int)$r->i_change_flag
                : (int)$r->eat_flag;
            if ($effective === 1) {
                $dateCounts[$dateStr] = ($dateCounts[$dateStr] ?? 0) + 1;
            }
        }

        $events = [];
        foreach ($dateCounts as $date => $count) {
            $events[] = [
                'title'  => '合計食数: ' . $count,
                'start'  => $date,
                'allDay' => true,
            ];
        }

        // イベントデータをビューにセット
        $this->set(compact('events'));

        // JSON を期待する（例: FullCalendar の fetch など）場合のみ serialize 指定
        if ($isAjax || $this->request->accepts('application/json')) {
            $this->viewBuilder()->setOption('serialize', 'events');
        }
    }


    /**
     * ビューメソッド
     *
     * @param string|null $id T Reservation Info id.
     * @return \Cake\Http\Response|null|void ビューをレンダリングする
     * @throws \Cake\Datasource\Exception\RecordNotFoundException 記録が見つからない場合
     * 管理者および所属部屋のみ詳細閲覧と修正可能
     */
    public function view()
    {
        /* ───────────────────────────────────────
         * ① ログインユーザー情報を取得
         * ─────────────────────────────────────── */
        $user = $this->request->getAttribute('identity');
        $userRoomId = null;           // ユーザー所属部屋 ID
        $isAdmin = false;          // 管理者フラグ

        if ($user !== null) {
            /* 1) Identity 直下に i_id_room がある場合 */
            $userRoomId = $user->get('i_id_room');

            /* 2) 関連エンティティ m_user_info 内にある場合 */
            if ($userRoomId === null && $user->get('m_user_info')) {
                $userRoomId = $user->get('m_user_info')->get('i_id_room');
            }

            /* 3) まだ取得できていなければ DB からフォールバック（MUserGroup 経由） */
            if ($userRoomId === null) {
                $userId = $user->get('i_id_user');
                if ($userId) {
                    $row = $this->MUserGroup->find()
                        ->select(['i_id_room'])
                        ->where([
                            'i_id_user' => $userId,
                            'active_flag' => 0
                        ])
                        ->disableHydration()
                        ->first();
                    if ($row && isset($row['i_id_room'])) {
                        $userRoomId = (int)$row['i_id_room'];
                    }
                }
            }

            if ($userRoomId !== null) {
                $userRoomId = (int)$userRoomId;
            }

            $isAdmin = ((int)$user->get('i_admin') === 1);
        }

        /* ───────────────────────────────────────
         * ② クエリパラメータから日付を取得
         * ─────────────────────────────────────── */
        $date = $this->request->getQuery('date');

        // null だけでなく空文字列もチェック
        if ($date === null || $date === '') {
            throw new \InvalidArgumentException('日付が指定されていません。');
        }

        // FrozenDate 生成（内部処理では $targetDate を使用）
        $targetDate = new FrozenDate($date, 'Asia/Tokyo');

        /* ───────────────────────────────────────
         * ★ 対象日が何日前かを算出し、判定カラムを決定
         * ─────────────────────────────────────── */
        $today = FrozenDate::today('Asia/Tokyo');
        $diffDays = $today->diff($targetDate)->days;          // 0=当日, 1=前日 …
        $judgeColumn = ($diffDays >= 15) ? 'eat_flag' : 'i_change_flag';

        /* ───────────────────────────────────────
         * ③ 部屋一覧を取得
         * ─────────────────────────────────────── */
        $rooms = $this->MRoomInfo->find('list', [
            'keyField' => 'i_id_room',
            'valueField' => 'c_room_name'
        ])->toArray();

        /* ───────────────────────────────────────
         * ④ 食事区分ごとの予約集計
         * ─────────────────────────────────────── */
        $mealTypes = [1 => '朝', 2 => '昼', 3 => '夜', 4 => '弁当'];
        $mealDataArray = [];

        foreach ($mealTypes as $mealType => $mealLabel) {

            // 食べる・食べない情報を取得（判定カラムを可変にする）
            $reservations = $this->TIndividualReservationInfo->find()
                ->select([
                    'room_id' => 'TIndividualReservationInfo.i_id_room',
                    'i_id_user' => 'TIndividualReservationInfo.i_id_user',
                    'judge_flag' => "TIndividualReservationInfo.{$judgeColumn}",
                    'taberu_ninzuu' => $this->TIndividualReservationInfo
                        ->find()
                        ->func()
                        ->count('TIndividualReservationInfo.i_id_user')
                ])
                ->where([
                    'd_reservation_date' => $targetDate->format('Y-m-d'),
                    'i_reservation_type' => $mealType
                ])
                ->groupBy([
                    'TIndividualReservationInfo.i_id_room',
                    "TIndividualReservationInfo.{$judgeColumn}",
                    'TIndividualReservationInfo.i_id_user'
                ])
                ->toArray();

            $mealDataArray[$mealLabel] = [];

            /* 部屋ごとに食べる／食べないユーザー ID を振り分けるためのマップ */
            $roomUserEatMap = [];
            $roomUserNotEatMap = [];

            foreach ($reservations as $reservation) {
                $roomId = $reservation->room_id;
                $flagValue = $reservation->judge_flag;   // ← 動的カラム
                $userId = $reservation->i_id_user;

                // 部屋リストに存在しない部屋はスキップ
                if (!isset($rooms[$roomId])) {
                    continue;
                }

                // 部屋データ初期化
                if (!isset($mealDataArray[$mealLabel][$roomId])) {
                    $mealDataArray[$mealLabel][$roomId] = [
                        'room_name' => $rooms[$roomId],
                        'taberu_ninzuu' => 0,
                        'tabenai_ninzuu' => 0,
                        'room_id' => $roomId,
                    ];
                }

                $roomUserEatMap[$roomId] = $roomUserEatMap[$roomId] ?? [];
                $roomUserNotEatMap[$roomId] = $roomUserNotEatMap[$roomId] ?? [];

                // 判定カラムの値で振り分け（1 = 食べる）
                if ($flagValue == 1) {
                    $roomUserEatMap[$roomId][$userId] = true;
                    $mealDataArray[$mealLabel][$roomId]['taberu_ninzuu']++;
                } else {
                    $roomUserNotEatMap[$roomId][$userId] = true;
                }
            }

            /* 各部屋の「食べない人数」を算出 */
            foreach ($mealDataArray[$mealLabel] as &$roomData) {
                $roomId = $roomData['room_id'];

                // 有効ユーザーを取得（active_flag=0, i_del_flag=0, dt_create ≤ date）
                $usersInRoom = $this->MUserGroup->find()
                    ->select([
                        'MUserGroup.i_id_user',
                        'MUserGroup.i_id_room'
                    ])
                    ->enableAutoFields(false)
                    ->matching('MUserInfo', function ($q) use ($targetDate) {
                        return $q->select([
                            'MUserInfo.i_id_user',
                            'MUserInfo.i_del_flag',
                            'MUserInfo.dt_create'
                        ])
                            ->enableAutoFields(false)
                            ->where(['MUserInfo.i_del_flag' => 0])
                            ->andWhere(function ($exp) use ($targetDate) {
                                return $exp->lte('MUserInfo.dt_create', $targetDate->format('Y-m-d'));
                            });
                    })
                    ->where([
                        'MUserGroup.i_id_room' => $roomId,
                        'MUserGroup.active_flag' => 0
                    ])
                    ->all();

                $tabenaiCount = 0;
                foreach ($usersInRoom as $userGroup) {
                    /** @var \Cake\Datasource\EntityInterface|null $userInfo */
                    $userInfo = $userGroup->_matchingData['MUserInfo'] ?? null;
                    if ($userInfo === null) {
                        continue; // 関連が無ければスキップ
                    }

                    $uid = $userInfo->i_id_user;

                    $haveEat = isset($roomUserEatMap[$roomId][$uid]);

                    /* 判定カラム = 1 のレコードが無ければ「食べない」 */
                    if (!$haveEat) {
                        $tabenaiCount++;
                    }
                }

                $roomData['tabenai_ninzuu'] = $tabenaiCount;

                // デバッグログ
                $this->log("部屋 {$roomId} の食べる人数: {$roomData['taberu_ninzuu']}", 'debug');
                $this->log("部屋 {$roomId} の食べない人数: {$roomData['tabenai_ninzuu']}", 'debug');
            }
            unset($roomData); // 参照解放
        }

        /* ───────────────────────────────────────
         * ⑤ ビューにデータをセット
         * ─────────────────────────────────────── */
        $this->set(compact(
            'mealDataArray',
            'date',
            'userRoomId',
            'isAdmin',
            'diffDays',
            'judgeColumn'
        ));
    }

    /**
     * 部屋詳細メソッド
     *
     * @param int $roomId 部屋ID
     * @param string $date 日付
     * @param int $mealType 食事タイプ
     * @return \Cake\Http\Response|null|void ビューをレンダリングする
     * 食べる人と食べない人のリストを表示する→データベースに登録されていない場合は食べない人として表示される
     */
    public function roomDetails($roomId, $date, $mealType)
    {
        // パラメータのログ出力
        $this->log("roomId: $roomId, date: $date, mealType: $mealType", 'debug');

        if (empty($roomId) || empty($date) || empty($mealType)) {
            throw new \InvalidArgumentException('部屋ID、日付、または食事タイプが指定されていません。');
        }

        if (!is_numeric($mealType)) {
            throw new \InvalidArgumentException('食事タイプは整数である必要があります。');
        }

        /* ════════════════════════════════════════════════
         * 15 日前までは eat_flag、14 日前以降は i_change_flag
         * ════════════════════════════════════════════════ */
        try {
            $targetDate   = new \DateTimeImmutable($date);     // 予約対象日
            $changeBorder = (new \DateTimeImmutable('today'))->modify('+14 days'); // 14 日後
        } catch (\Exception $e) {
            throw new \InvalidArgumentException('日付の形式が正しくありません。');
        }

        $useChangeFlag = $targetDate <= $changeBorder; // true → i_change_flag を使用
        $flagField     = $useChangeFlag ? 'i_change_flag' : 'eat_flag';

        // 部屋名を取得
        $room = $this->MRoomInfo->find()
            ->select(['c_room_name'])
            ->where(['i_id_room' => $roomId])
            ->first();

        if (!$room) {
            throw new NotFoundException(__('部屋が見つかりません。'));
        }

        /* =============================================================
         * 基本条件（eat / no-eat 共通）
         * ============================================================= */
        $baseConditions = [
            'TIndividualReservationInfo.i_id_room'          => $roomId,
            'TIndividualReservationInfo.d_reservation_date' => $date,
            'TIndividualReservationInfo.i_reservation_type' => $mealType,
            'MUserInfo.i_del_flag'                          => 0,
            'MUserGroup.active_flag'                        => 0,
        ];

        /* =============================================================
         * 食べる人を取得
         * ============================================================= */
        $eaters = $this->TIndividualReservationInfo->find()
            ->select([
                'TIndividualReservationInfo.i_id_user',
                'TIndividualReservationInfo.i_id_room',   // 他部屋判定用
                'MUserInfo.c_user_name',
            ])
            ->contain(['MUserInfo', 'MUserGroup'])
            ->where($baseConditions + ["TIndividualReservationInfo.$flagField" => 1])
            ->all();

        /* =============================================================
         * 食べない人（登録済み）を取得
         * ============================================================= */
        $nonEaters = $this->TIndividualReservationInfo->find()
            ->select(['TIndividualReservationInfo.i_id_user', 'MUserInfo.c_user_name'])
            ->contain(['MUserInfo', 'MUserGroup'])
            ->where($baseConditions + ["TIndividualReservationInfo.$flagField" => 0])
            ->all();

        /* =============================================================
         * 部屋に所属する全ユーザー取得
         * ============================================================= */
        $allUsers = $this->MUserGroup->find()
            ->select(['MUserInfo.i_id_user', 'MUserInfo.c_user_name', 'MUserInfo.dt_create'])
            ->contain(['MUserInfo'])
            ->where([
                'MUserGroup.i_id_room'   => $roomId,
                'MUserInfo.i_del_flag'   => 0,
                'MUserGroup.active_flag' => 0,
            ])
            ->all();

        /* === 予約未登録ユーザーも「食べない人」に追加するための前処理 === */
        $allUserIds   = [];
        $allUserNames = [];
        foreach ($allUsers as $user) {
            $userInfo = $user->m_user_info ?? null;
            if ($userInfo) {
                $allUserIds[]                       = $userInfo->i_id_user;
                $allUserNames[$userInfo->i_id_user] = $userInfo->c_user_name;
            }
        }

        $eatUserIds = collection($eaters)->extract('i_id_user')->toArray();
        $noEatUserIds = collection($nonEaters)->extract('i_id_user')->toArray();

        $notRegisteredUserIds = array_diff($allUserIds, array_merge($eatUserIds, $noEatUserIds));

        /* =============================================================
         * 食べる人／食べない人の名前配列を整形
         * ============================================================= */
        $eatUsers = [];
        foreach ($eaters as $eater) {
            if ($eater->has('m_user_info')) {
                $eatUsers[] = $eater->m_user_info->c_user_name;
            }
        }

        $noEatUsers = [];
        foreach ($nonEaters as $nonEater) {
            if ($nonEater->has('m_user_info')) {
                $userInfo = $nonEater->m_user_info;
                if (empty($userInfo->dt_create) || $userInfo->dt_create <= $date) {
                    $noEatUsers[] = $userInfo->c_user_name;
                }
            }
        }

        foreach ($notRegisteredUserIds as $userId) {
            if (isset($allUserNames[$userId]) && !in_array($allUserNames[$userId], $noEatUsers, true)) {
                $noEatUsers[] = $allUserNames[$userId];
            }
        }

        /* =============================================================
         * 他の部屋で食べる登録がある利用者の部屋名を取得
         * ============================================================= */
        $otherRoomEaters = [];
        foreach ($eaters as $eater) {
            if ($eater->has('m_user_info') && $eater->i_id_room !== null && (int)$eater->i_id_room !== (int)$roomId) {
                $otherRoomRoom = $this->MRoomInfo->find()
                    ->select(['c_room_name'])
                    ->where(['i_id_room' => $eater->i_id_room])
                    ->first();

                $roomName = $otherRoomRoom ? $otherRoomRoom->c_room_name : '不明な部屋';

                $otherRoomEaters[] = [
                    'user_name' => $eater->m_user_info->c_user_name,
                    'room_name' => $roomName,
                ];
            }
        }

        /* =============================================================
         * ビューへデータ渡し
         * ============================================================= */
        $this->set(compact(
            'room',
            'date',
            'mealType',
            'eatUsers',
            'noEatUsers',
            'otherRoomEaters',
            'useChangeFlag'   // ビュー側で判定を表示したい場合用
        ));
    }


    /**
     * 所属しているユーザーを取得するメソッド
     * @param int $roomId
     * @param string $users
     *
     */

    public function getUsersByRoom($roomId = null)
    {
        $this->request->allowMethod(['get']); // AJAXリクエストのみ許可

        if (!$roomId) {
            // 部屋IDが指定されていない場合はエラーメッセージを返す
            return $this->jsonErrorResponse(__('部屋IDが指定されていません。'));
        }

        // クエリパラメータから日付を取得
        $date = $this->request->getQuery('date');

        // 部屋に属する利用者を取得
        $users = $this->MUserGroup->find()
            ->select(['i_id_user', 'i_id_room'])
            ->where(['i_id_room' => $roomId, 'active_flag' => 0, 'i_del_flag' => 0])
            ->contain(['MUserInfo' => function ($q) {
                return $q->select(['i_id_user', 'c_user_name']);
            }])
            ->toArray();

        // 既存の予約データを取得（もし日付が指定されている場合）
        $existingReservations = [];
        if ($date) {
            $existingReservations = $this->TIndividualReservationInfo->find()
                ->select(['i_id_user', 'i_reservation_type'])
                ->where([
                    'i_id_room' => $roomId,
                    'd_reservation_date' => $date,
                    'eat_flag' => 1 // 食べる人
                ])
                ->toArray();
        }

        // 利用者データに予約状況を付加
        $usersByRoom = [];
        foreach ($users as $user) {
            // ユーザーごとに予約を確認
            $reservations = array_filter($existingReservations, function ($reservation) use ($user) {
                return $reservation->i_id_user == $user->i_id_user;
            });

            $usersByRoom[] = [
                'id' => $user->i_id_user,
                'name' => $user->m_user_info->c_user_name,
                'morning' => in_array(1, array_column($reservations, 'i_reservation_type')),
                'noon' => in_array(2, array_column($reservations, 'i_reservation_type')),
                'night' => in_array(3, array_column($reservations, 'i_reservation_type')),
                'bento' => in_array(4, array_column($reservations, 'i_reservation_type')),
            ];
        }


        // JSON形式で返却
        return $this->response->withType('application/json')
            ->withStringBody(json_encode(['usersByRoom' => $usersByRoom]));

    }

    /**
     * 個人予約情報を取得するメソッド
     * このメソッドは、ログイン中のユーザーの個人予約情報を取得し、指定された日付に基づいて食事タイプごとの予約状況を返します。
     * @return \Cake\Http\Response JSON形式で予約情報を返す
     *
     */
    public function getPersonalReservation()
    {
        $this->autoRender = false;
        $this->viewBuilder()->disableAutoLayout();

        // GET リクエストのみ許可
        $this->request->allowMethod(['get']);

        // クエリパラメータから日付を取得
        $date = $this->request->getQuery('date');
        if (empty($date)) {
            return $this->response->withStatus(400)
                ->withType('application/json')
                ->withStringBody(json_encode([
                    'status'  => 'error',
                    'message' => '日付が指定されていません。'
                ], JSON_UNESCAPED_UNICODE));
        }

        // ログイン中のユーザーを取得
        $user = $this->Authentication->getIdentity();
        if (!$user) {
            return $this->response->withStatus(403)
                ->withType('application/json')
                ->withStringBody(json_encode([
                    'status'  => 'error',
                    'message' => 'ログイン情報がありません。'
                ], JSON_UNESCAPED_UNICODE));
        }
        $userId   = $user->get('i_id_user');
        $userName = $user->get('c_user_name');

        // 指定された日付・ユーザーにおける予約情報（eat_flag = 1）のみを取得
        $reservations = $this->TIndividualReservationInfo->find()
            ->select(['i_reservation_type'])
            ->where([
                'i_id_user'            => $userId,
                'd_reservation_date'   => $date,
                'eat_flag'             => 1
            ])
            ->toArray();

        // 食事タイプ 1:朝、2:昼、3:夜、4:弁当 の予約状態を初期化（false: 未予約）
        $mealTypes = [1, 2, 3, 4];
        $result = [];
        foreach ($mealTypes as $mealType) {
            $result[(string)$mealType] = false;
        }
        // 予約情報が存在する場合は true に更新
        foreach ($reservations as $reservation) {
            $type = $reservation->i_reservation_type;
            $result[(string)$type] = true;
        }

        // ユーザーが所属していて登録可能な部屋情報を取得
        $authorizedRooms = $this->getAuthorizedRooms($userId);

        // ユーザー情報、予約情報、所属部屋情報をまとめて返却
        $output = [
            'user' => [
                'i_id_user'    => $userId,
                'c_user_name'  => $userName
            ],
            'reservation'      => $result,
            'authorized_rooms' => $authorizedRooms
        ];

        return $this->response->withType('application/json')
            ->withStringBody(json_encode([
                'status' => 'success',
                'data'   => $output
            ], JSON_UNESCAPED_UNICODE));
    }




    /**
     * ユーザーが予約可能な部屋を取得するメソッド
     * @param int $userId ユーザーID
     * @return array 予約可能な部屋のリスト
     * このメソッドは、指定されたユーザーIDに基づいて、ユーザーが所属する部屋の情報を取得します。
     */

    private function getAuthorizedRooms($userId)
    {
        return $this->MRoomInfo->find('list', [
            'keyField' => 'i_id_room',
            'valueField' => 'c_room_name'
        ])
            ->matching('MUserGroup', function ($q) use ($userId) {
                return $q->where(['MUserGroup.i_id_user' => $userId]);
            })
            ->toArray();
    }

    /**
     * 重複予約のチェックを行うメソッド
     * @return \Cake\Http\Response JSON形式で重複予約の有無を返す
     * このメソッドは、指定された日付、部屋ID、および予約タイプに基づいて、既存の予約と重複するかどうかを確認します。
     */
    public function checkDuplicateReservation()
    {
        $this->request->allowMethod(['post']);

        $data = $this->request->getData();

        // 必須フィールドの検証
        if (empty($data['d_reservation_date']) || empty($data['i_id_room']) || empty($data['reservation_type'])) {
            return $this->response->withType('application/json')
                ->withStringBody(json_encode([
                    'isDuplicate' => false,
                    'message' => '必要なデータが不足しています。',
                ]));
        }

        $existingReservation = $this->TIndividualReservationInfo->find()
            ->where([
                'd_reservation_date' => $data['d_reservation_date'],
                'i_id_room' => $data['i_id_room'],
                'i_reservation_type' => $data['reservation_type'],
            ])
            ->first();


        if ($existingReservation) {
            if (isset($this->Url)) {
                $editUrl = $this->Url->build([
                    'controller' => 'TReservationInfo',
                    'action' => 'edit',
                    'roomId' => $data['i_id_room'],
                    'date' => $data['d_reservation_date'],
                    'mealType' => $data['reservation_type'],
                ]);
            }


            return $this->response->withType('application/json')
                ->withStringBody(json_encode([
                    'isDuplicate' => true,
                    'editUrl' => $editUrl,
                ]));
        }


        return $this->response->withType('application/json')
            ->withStringBody(json_encode(['isDuplicate' => false]));
    }


    /**
     * 予約の追加メソッド(日付ごとの個人予約またはグループ予約を追加)
     *
     * @return \Cake\Http\Response|null|void ビューをレンダリングする
     * ユーザーが新しい予約を追加するためのメソッドです。
     * ユーザーの権限に基づいて、個人予約またはグループ予約を処理します。
     */

    public function add()
    {
        $user    = $this->request->getAttribute('identity');
        $isModal = ($this->request->getQuery('modal') === '1');

        if ($isModal) {
            $this->viewBuilder()->disableAutoLayout();
            $this->response = $this->response
                ->withHeader('X-Frame-Options', 'SAMEORIGIN')
                ->withHeader('Content-Security-Policy', "frame-ancestors 'self'");
        }

        $date = $this->request->getQuery('date') ?? date('Y-m-d');

        if (!$user) {
            $this->set('errorMessage', __('ログインが必要です。'));
            $this->set('date', $date);
            $this->set('rooms', []);
            $this->set('roomList', []);
            $this->set('userLevel', null);
            $this->set('tReservationInfo', $this->TReservationInfo->newEmptyEntity());
            return;
        }

        $userLevel = $user->i_user_level;
        $userId    = $user->get('i_id_user');
        $rooms     = $this->getAuthorizedRooms($userId);

        if ($this->request->is('get')) {
            $validation = $this->validateReservationDate($date);

            $roomList = $this->MRoomInfo->find('list', [
                'keyField'   => 'i_id_room',
                'valueField' => 'c_room_name'
            ])->toArray();

            $tReservationInfo = $this->TReservationInfo->newEmptyEntity();

            $this->set(compact('rooms', 'tReservationInfo', 'date', 'roomList', 'userLevel'));

            if ($validation !== true) {
                $this->set('errorMessage', __($validation));
            } elseif (!$roomList) {
                $this->set('errorMessage', __('部屋が見つかりません。'));
            }

            return;
        }

        try {
            $today   = new \Cake\I18n\FrozenDate();
            $minDate = $today->addDays(15);
            $target  = new \Cake\I18n\FrozenDate($date);
        } catch (\Throwable $e) {
            if ($this->request->is('ajax')) {
                return $this->jsonErrorResponse(__('日付の解析に失敗しました。'), 422);
            }
            $this->Flash->error(__('日付の解析に失敗しました。'));
            // ★ 配列ルートで指定（ベース重複しない）
            return $this->redirect(['action' => 'index']);
        }

        if ($target < $minDate) {
            $msg = __('通常発注は「きょうから15日目以降」のみ登録できます（{0} 以降）。', $minDate->i18nFormat('yyyy-MM-dd'));
            if ($this->request->is('ajax')) {
                return $this->jsonErrorResponse($msg, 422);
            }
            $this->Flash->error($msg);
            // ★ 配列ルートで指定（ベース重複しない）
            return $this->redirect(['action' => 'index', '?' => ['date' => $date]]);
        }

        $roomList = $this->MRoomInfo->find('list', [
            'keyField'   => 'i_id_room',
            'valueField' => 'c_room_name'
        ])->toArray();

        if (!$roomList) {
            if ($this->request->is('ajax')) {
                return $this->jsonErrorResponse(__('部屋が見つかりません。'), 404);
            }
            $this->Flash->error(__('部屋が見つかりません。'));
            // ★ 配列ルートで指定
            return $this->redirect(['action' => 'index', '?' => ['date' => $date]]);
        }

        $tReservationInfo = $this->TReservationInfo->newEmptyEntity();

        if ($this->request->is('post')) {
            $data = $this->request->getData();

            if (empty($data['d_reservation_date'])) {
                $data['d_reservation_date'] = $date;
            }

            $lunchOn = !empty($data['lunch']);
            $bentoOn = !empty($data['bento']);
            if ($lunchOn && $bentoOn) {
                if ($this->request->is('ajax')) {
                    return $this->jsonErrorResponse(__('昼食と弁当は同時に予約できません。どちらか一方を選択してください。'), 409);
                }
                $this->Flash->error(__('昼食と弁当は同時に予約できません。どちらか一方を選択してください。'));
                // ★ 配列ルートで指定
                return $this->redirect(['action' => 'index', '?' => ['date' => $data['d_reservation_date']]]);
            }

            $reservationType = $data['reservation_type'] ?? '1';
            $resultResponse = ((string)$reservationType === '1')
                ? $this->processIndividualReservation($data['d_reservation_date'], $data, $rooms)
                : $this->processGroupReservation($data['d_reservation_date'], $data, $rooms);

            // ★ ここからが重要：非AJAXでは「常にサーバ側で配列ルート→redirect()」に集約
            if ($this->request->is('ajax')) {
                return $resultResponse instanceof \Cake\Http\Response ? $resultResponse : $this->response;
            }

            // 成功時の既定遷移先（配列ルートのみを使う）
            $defaultRedirect = ['action' => 'index', '?' => ['date' => $data['d_reservation_date']]];

            if ($resultResponse instanceof \Cake\Http\Response) {
                $ctype = $resultResponse->getType() ?? '';
                if (stripos($ctype, 'application/json') !== false) {
                    // JSON を返してくる実装でも、非AJAXでは必ずここで食べてサーバリダイレクトに変換する
                    $body = (string)$resultResponse->getBody();
                    $json = null;
                    try {
                        $json = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
                    } catch (\Throwable $e) {}

                    if (is_array($json)) {
                        if (($json['status'] ?? '') === 'success') {
                            $this->Flash->success($json['message'] ?? __('登録しました。'));
                            // ★ JSON の redirect があっても “絶対URLや重複パス文字列” は使わず、
                            //    ここで必ず配列ルートに置き換える（重複ベース対策の決め手）
                            if (!empty($json['redirect']) && is_array($json['redirect'])) {
                                return $this->redirect($json['redirect']);
                            }
                            return $this->redirect($defaultRedirect);
                        }
                        $this->Flash->error($json['message'] ?? __('登録に失敗しました。'));
                        return $this->redirect($defaultRedirect);
                    }
                }

                // 既に 3xx の Location ヘッダが付いたレスポンスならそのまま返す
                if ($resultResponse->getStatusCode() >= 300 && $resultResponse->getStatusCode() < 400) {
                    return $resultResponse;
                }
            }

            $this->Flash->success(__('登録しました。'));
            return $this->redirect($defaultRedirect);
        }

        $this->set(compact('rooms', 'tReservationInfo', 'date', 'roomList', 'userLevel'));
    }

    /**
     *
     */
    /**
     * 予約コピー（週／月）
     * POST /t-reservation-info/copy.json
     *
     * 期待パラメータ(JSON or form):
     * - mode: "week" | "month"   … コピー単位
     * - source: "YYYY-MM-DD"     … 基準日（week=その週の任意日, month=その月内の任意日）
     * - target: "YYYY-MM-DD"     … 貼り付け先（week=その週の任意日, month=その月内の任意日）
     * - room_id: int|null        … 部屋で絞込（null で全体）
     * - overwrite: bool          … true=既存を上書き, false=既存はスキップ
     *
     * 返却(JSON):
     * { status: "success", mode, affected, message }
     * 失敗時:
     * { status: "error", message }
     */
    public function copy()
    {
        $this->request->allowMethod(['post']);
        $this->viewBuilder()->disableAutoLayout();
        $this->response = $this->response->withType('application/json');

        $data = (array)$this->request->getData();
        if (empty($data)) {
            try {
                $raw = (string)$this->request->getBody();
                if ($raw !== '') {
                    $json = json_decode($raw, true);
                    if (json_last_error() === JSON_ERROR_NONE && is_array($json)) {
                        $data = $json;
                    }
                }
            } catch (\Throwable $e) {
            }
        }

        $mode = strtolower((string)($data['mode'] ?? ''));
        $sourceStr = (string)($data['source'] ?? $data['source_start'] ?? '');
        $targetStr = (string)($data['target'] ?? $data['target_start'] ?? '');
        $roomIdRaw = $data['room_id'] ?? null;
        
        Log::debug('[copy] Received data: mode=' . $mode . ', sourceStr=' . $sourceStr . ', targetStr=' . $targetStr . ', roomId=' . $roomIdRaw);
        
        // room_id を適切な型に変換（空文字列や'0'はnullとして扱う）
        $roomId = null;
        if ($roomIdRaw !== null && $roomIdRaw !== '' && $roomIdRaw !== '0') {
            $roomId = (int)$roomIdRaw;
        }
        
        $onlyChildren = filter_var($data['only_children'] ?? false, FILTER_VALIDATE_BOOLEAN);

        if (!in_array($mode, ['week', 'month'], true)) {
            $body = json_encode(['status' => 'error', 'message' => 'mode は "week" か "month" を指定してください。'], JSON_UNESCAPED_UNICODE);
            return $this->response->withStatus(422)->withStringBody($body);
        }
        if ($sourceStr === '' || $targetStr === '') {
            $body = json_encode(['status' => 'error', 'message' => 'source / target（または source_start / target_start）を YYYY-MM-DD 形式で指定してください。'], JSON_UNESCAPED_UNICODE);
            return $this->response->withStatus(422)->withStringBody($body);
        }

        try {
            $src = new \Cake\I18n\FrozenDate($sourceStr);
            $dst = new \Cake\I18n\FrozenDate($targetStr);
        } catch (\Throwable $e) {
            $body = json_encode(['status' => 'error', 'message' => 'source / target は YYYY-MM-DD 形式で指定してください。'], JSON_UNESCAPED_UNICODE);
            return $this->response->withStatus(422)->withStringBody($body);
        }

        $toMonday = function (\Cake\I18n\FrozenDate $d): \Cake\I18n\FrozenDate {
            $n = (int)$d->format('N'); // 1..7
            return $n > 1 ? $d->subDays($n - 1) : $d;
        };
        if ($mode === 'week') {
            $src = $toMonday($src);
            $dst = $toMonday($dst);
            Log::debug('[copy] After toMonday adjustment: src=' . $src->format('Y-m-d') . ', dst=' . $dst->format('Y-m-d'));
        } else {
            $src = new \Cake\I18n\FrozenDate($src->format('Y-m-01'));
            $dst = new \Cake\I18n\FrozenDate($dst->format('Y-m-01'));
            Log::debug('[copy] After month adjustment: src=' . $src->format('Y-m-d') . ', dst=' . $dst->format('Y-m-d'));
        }

        try {
            $user = $this->request->getAttribute('identity') ?: null;
            $service = new \App\Service\ReservationCopyService();

            // 上書きは常に無効化（サーバ強制）
            $overwrite = false;

            // 引数の順序: $src, $dst, $roomId, $overwrite, $actor, $onlyChildren
            $result = ($mode === 'week')
                ? $service->copyWeek($src, $dst, $roomId, $overwrite, $user, $onlyChildren)
                : $service->copyMonth($src, $dst, $roomId, $overwrite, $user, $onlyChildren);

            $total = $result['total'] ?? 0;
            $copied = $result['copied'] ?? 0;
            $skipped = $result['skipped'] ?? 0;
            $invalidDate = $result['invalid_date'] ?? 0;

            $msg = ($mode === 'week')
                ? sprintf('週コピーが完了しました。', $copied)
                : sprintf('月コピーが完了しました。', $copied);

            $responseData = [
                'status' => 'success',
                'mode' => $mode,
                'total' => $total,
                'copied' => $copied,
                'skipped' => $skipped,
                'invalid_date' => $invalidDate,
                'affected' => $copied, // 後方互換性のため
                'message' => $msg,
            ];
            
            Log::debug('[copy] Response data: ' . json_encode($responseData));
            
            $body = json_encode($responseData, JSON_UNESCAPED_UNICODE);

            return $this->response->withStringBody($body);
        } catch (\Throwable $e) {
            \Cake\Log\Log::error(sprintf(
                'Reservation copy failed: %s in %s:%d',
                $e->getMessage(), $e->getFile(), $e->getLine()
            ));
            $body = json_encode([
                'status' => 'error',
                'message' => 'コピー処理中にエラーが発生しました。',
                'detail' => \Cake\Core\Configure::read('debug') ? $e->getMessage() : null,
            ], JSON_UNESCAPED_UNICODE);

            return $this->response->withStatus(500)->withStringBody($body);
        }
    }

    /**
     * 予約コピーのプレビュー（件数のみ取得）
     */
    public function copyPreview()
    {
        $this->request->allowMethod(['post', 'get']);
        $this->viewBuilder()->disableAutoLayout();
        $this->response = $this->response->withType('application/json');

        $data = (array)$this->request->getData();
        if (empty($data) && $this->request->is('get')) {
            $data = (array)$this->request->getQueryParams();
        }
        if (empty($data)) {
            try {
                $raw = (string)$this->request->getBody();
                if ($raw !== '') {
                    $json = json_decode($raw, true);
                    if (json_last_error() === JSON_ERROR_NONE && is_array($json)) {
                        $data = $json;
                    }
                }
            } catch (\Throwable $e) {
            }
        }

        $mode = strtolower((string)($data['mode'] ?? ''));
        $sourceStr = (string)($data['source'] ?? $data['source_start'] ?? '');
        $targetStr = (string)($data['target'] ?? $data['target_start'] ?? '');
        $roomIdRaw = $data['room_id'] ?? null;
        
        $roomId = null;
        if ($roomIdRaw !== null && $roomIdRaw !== '' && $roomIdRaw !== '0') {
            $roomId = (int)$roomIdRaw;
        }
        
        $onlyChildren = filter_var($data['only_children'] ?? false, FILTER_VALIDATE_BOOLEAN);

        if (!in_array($mode, ['week', 'month'], true)) {
            $body = json_encode(['status' => 'error', 'message' => 'mode は "week" か "month" を指定してください。'], JSON_UNESCAPED_UNICODE);
            return $this->response->withStatus(422)->withStringBody($body);
        }
        if ($sourceStr === '' || $targetStr === '') {
            $body = json_encode(['status' => 'error', 'message' => 'source / target を YYYY-MM-DD 形式で指定してください。'], JSON_UNESCAPED_UNICODE);
            return $this->response->withStatus(422)->withStringBody($body);
        }

        try {
            $src = new \Cake\I18n\FrozenDate($sourceStr);
            $dst = new \Cake\I18n\FrozenDate($targetStr);
        } catch (\Throwable $e) {
            $body = json_encode(['status' => 'error', 'message' => 'source / target は YYYY-MM-DD 形式で指定してください。'], JSON_UNESCAPED_UNICODE);
            return $this->response->withStatus(422)->withStringBody($body);
        }

        $toMonday = function (\Cake\I18n\FrozenDate $d): \Cake\I18n\FrozenDate {
            $n = (int)$d->format('N');
            return $n > 1 ? $d->subDays($n - 1) : $d;
        };
        if ($mode === 'week') {
            $src = $toMonday($src);
            $dst = $toMonday($dst);
        } else {
            $src = new \Cake\I18n\FrozenDate($src->format('Y-m-01'));
            $dst = new \Cake\I18n\FrozenDate($dst->format('Y-m-01'));
        }

        try {
            $service = new \App\Service\ReservationCopyService();

            $preview = ($mode === 'week')
                ? $service->previewWeek($src, $dst, $roomId, $onlyChildren)
                : $service->previewMonth($src, $dst, $roomId, $onlyChildren);

            $body = json_encode([
                'status' => 'success',
                'preview' => $preview,
            ], JSON_UNESCAPED_UNICODE);

            return $this->response->withStringBody($body);
        } catch (\Throwable $e) {
            \Cake\Log\Log::error(sprintf(
                'Reservation copy preview failed: %s in %s:%d',
                $e->getMessage(), $e->getFile(), $e->getLine()
            ));
            $body = json_encode([
                'status' => 'error',
                'message' => 'プレビュー取得中にエラーが発生しました。',
                'detail' => \Cake\Core\Configure::read('debug') ? $e->getMessage() : null,
            ], JSON_UNESCAPED_UNICODE);

            return $this->response->withStatus(500)->withStringBody($body);
        }
    }

    /**
     * 個人予約登録／更新（モーダル対応）
     *
     * ① JSON の検証
     * ② 日付の検証
     * ③ 各食事区分につき 1 部屋しか登録できないようチェック
     * ④ 既存予約がある場合は更新、無ければ新規作成
     * ⑤ 最終状態（朝/昼/夕/弁当のON/OFF）を details として返す  ← ★ 追加
     *
     * @param string                      $reservationDate 予約日 (Y-m-d)
     * @param string|array<string,mixed>  $jsonData        送信された JSON
     * @param array<int,string>           $rooms           ログインユーザーが操作可能な部屋一覧
     * @return \Cake\Http\Response
     */
    private function processIndividualReservation($reservationDate, $jsonData, $rooms)
    {
        /* ── ① JSON の検証 ── */
        if (empty($jsonData) || (!is_string($jsonData) && !is_array($jsonData))) {
            Log::error('入力データが無効です。空文字列または期待しない形式です。');
            return $this->jsonErrorResponse(__('入力データが無効です。'), 400);
        }

        $data = is_string($jsonData) ? json_decode($jsonData, true) : $jsonData;
        if (is_null($data) || json_last_error() !== JSON_ERROR_NONE) {
            Log::error('JSONデコードエラー: ' . json_last_error_msg());
            return $this->jsonErrorResponse(__('JSONデータの形式が不正です: ') . json_last_error_msg(), 400);
        }

        /* ── ② 日付の検証 ── */
        $dateValidation = $this->validateReservationDate($reservationDate);
        if ($dateValidation !== true) {
            Log::error('日付検証エラー: ' . $dateValidation);
            return $this->jsonErrorResponse(__($dateValidation), 422);
        }

        /* ── データ構造の検証 ── */
        if (!isset($data['meals']) || !is_array($data['meals'])) {
            Log::error('データ構造が不正: "meals" キーが存在しない、または配列ではありません。データ: ' . json_encode($data));
            return $this->jsonErrorResponse(__('データ構造が不正です。'), 422);
        }

        /* ── 初期化 ── */
        $reservationsToSave  = [];
        $operationPerformed  = false;
        $selectedRoomPerMeal = [];
        $duplicates          = [];
        $userId              = $this->request->getAttribute('identity')->get('i_id_user');
        $userName            = $this->request->getAttribute('identity')->get('c_user_name');

        /* ── ③ ～ ④ 更新／新規作成 ── */
        foreach ($data['meals'] as $mealType => $selectedRooms) {
            foreach ($selectedRooms as $roomId => $value) {
                if ((int)$value !== 1) {
                    continue;
                }

                // 同一食事区分の複数選択ガード
                if (isset($selectedRoomPerMeal[$mealType]) && $selectedRoomPerMeal[$mealType] !== $roomId) {
                    Log::error("同一食事区分で複数部屋が選択されました。MealType={$mealType}");
                    return $this->jsonErrorResponse(__('同じ食事区分に対して複数の部屋を選択することはできません。'), 409);
                }
                $selectedRoomPerMeal[$mealType] = $roomId;

                // 権限チェック
                if (!array_key_exists($roomId, $rooms)) {
                    Log::error('権限のない部屋が指定されました。Room ID: ' . $roomId);
                    return $this->jsonErrorResponse(__('選択された部屋は権限がありません。'), 403);
                }

                // 既存予約の確認
                $existingReservation = $this->TIndividualReservationInfo->find()
                    ->where([
                        'i_id_user'          => $userId,
                        'd_reservation_date' => $reservationDate,
                        'i_reservation_type' => $mealType,
                    ])
                    ->first();

                if ($existingReservation) {
                    // eat_flag = 0 のときだけ更新可
                    if ((int)$existingReservation->eat_flag === 0) {
                        $updateFields = [
                            'i_id_room'     => $roomId,
                            'eat_flag'      => 1,
                            'i_change_flag' => 1,
                            'c_update_user' => $userName,
                            'dt_update'     => FrozenTime::now(),
                        ];
                        $this->TIndividualReservationInfo->updateAll(
                            $updateFields,
                            [
                                'i_id_user'          => $existingReservation->i_id_user,
                                'd_reservation_date' => $existingReservation->d_reservation_date,
                                'i_reservation_type' => $existingReservation->i_reservation_type,
                            ]
                        );
                        $operationPerformed = true;
                        continue;
                    }

                    // 予約済み（eat_flag = 1）はスキップ
                    $duplicates[] = [
                        'reservation_date' => $reservationDate,
                        'meal_type'        => $mealType,
                        'room_id'          => $roomId,
                    ];
                    continue;
                }

                // 新規作成
                $newReservation = $this->TIndividualReservationInfo->patchEntity(
                    $this->TIndividualReservationInfo->newEmptyEntity(),
                    [
                        'i_id_user'          => $userId,
                        'd_reservation_date' => $reservationDate,
                        'i_id_room'          => $roomId,
                        'i_reservation_type' => $mealType,
                        'eat_flag'           => 1,
                        'i_change_flag'      => 1,
                        'c_create_user'      => $userName,
                        'dt_create'          => FrozenTime::now(),
                    ]
                );
                $reservationsToSave[] = $newReservation;
            }
        }

        /* ── ④ 保存処理 ── */
        if (!empty($reservationsToSave)) {
            if (!$this->TIndividualReservationInfo->saveMany($reservationsToSave)) {
                $errorMessages = [];
                foreach ($reservationsToSave as $entity) {
                    foreach ($entity->getErrors() as $fieldErrors) {
                        foreach ($fieldErrors as $message) {
                            $errorMessages[] = $message;
                        }
                    }
                }
                if (empty($errorMessages)) {
                    $errorMessages[] = '原因不明のエラーが発生しました。';
                }
                return $this->jsonErrorResponse(
                    __('予約の登録中にエラーが発生しました。詳細: {0}', implode('、', $errorMessages)), 500
                );
            }
            $operationPerformed = true;
        }

        /* ── ⑤ 最終状態（details）を同梱して返す ── */
        $finalStates = [
            'breakfast' => false,
            'lunch'     => false,
            'dinner'    => false,
            'bento'     => false,
        ];
        $type2key = [1 => 'breakfast', 2 => 'lunch', 3 => 'dinner', 4 => 'bento'];

        // 対象ユーザーの当日予約を再取得して ON/OFF を確定させる
        $rows = $this->TIndividualReservationInfo->find()
            ->select(['i_reservation_type', 'eat_flag'])
            ->where([
                'i_id_user'          => $userId,
                'd_reservation_date' => $reservationDate,
            ])
            ->all();

        foreach ($rows as $r) {
            $k = $type2key[(int)$r->i_reservation_type] ?? null;
            if ($k) {
                $finalStates[$k] = ((int)$r->eat_flag === 1);
            }
        }

        // 重複スキップがあった場合も details を返す（親 UI は差分更新 or 再取得に使える）
        if (!empty($duplicates)) {
            return $this->jsonSuccessResponse(
                __('一部の予約は既に存在するため、スキップされました。'),
                ['skipped' => $duplicates, 'details' => $finalStates, 'date' => $reservationDate],
                $this->request->getAttribute('webroot') . 'TReservationInfo/'
            );
        }

        if ($operationPerformed) {
            return $this->jsonSuccessResponse(
                __('個人予約が正常に登録されました。'),
                ['details' => $finalStates, 'date' => $reservationDate],
                $this->request->getAttribute('webroot') . 'TReservationInfo/'
            );
        }

        return $this->jsonErrorResponse(__('システムエラーが発生しました。'), 500);
    }


    /**
     * グループ予約の処理 - 複数ユーザーの予約データを一括で処理する（モーダル対応）
     *
     * @param string       $reservationDate 予約日
     * @param array|string $jsonData        予約データ（JSON文字列または連想配列）
     * @param array        $rooms           予約可能な部屋の連想配列
     * @return \Cake\Http\Response JSONレスポンス
     */
    private function processGroupReservation($reservationDate, $jsonData, $rooms)
    {
        // JSON デコードと入力検証
        if (empty($jsonData) || (!is_string($jsonData) && !is_array($jsonData))) {
            Log::error('入力データが無効です。空文字列または想定しない形式です。');
            return $this->jsonErrorResponse(__('入力データが無効です。'), 400);
        }

        $data = is_string($jsonData) ? json_decode($jsonData, true) : $jsonData;
        if (is_null($data) || json_last_error() !== JSON_ERROR_NONE) {
            Log::error('JSON デコードエラー: ' . json_last_error_msg());
            return $this->jsonErrorResponse(__('JSON データの形式が不正です: ') . json_last_error_msg(), 400);
        }

        // 日付検証
        $dateValidation = $this->validateReservationDate($reservationDate);
        if ($dateValidation !== true) {
            Log::error('日付検証エラー: ' . $dateValidation);
            return $this->jsonErrorResponse(__($dateValidation), 422);
        }

        if (!isset($data['users']) || !is_array($data['users'])) {
            Log::error('データ構造が不正: "users" キーが存在しない、または配列ではありません。データ: ' . json_encode($data));
            return $this->jsonErrorResponse(__('データ構造が不正です。'), 422);
        }

        // Identity の存在チェック
        $identity = $this->request->getAttribute('identity');
        if (!$identity) {
            Log::error('Identity が設定されていません。');
            return $this->jsonErrorResponse(__('認証情報が不足しています。'), 401);
        }
        $creatorName = $identity->get('c_user_name') ?? '不明なユーザー';

        $reservationsToSave = [];
        $duplicates = [];  // 重複しているユーザー情報を格納

        // 食事種別ラベル
        $mealTypeNames = [
            '1' => __('朝食'),
            '2' => __('昼食'),
            '3' => __('夕食'),
            '4' => __('弁当'),
        ];

        foreach ($data['users'] as $targetUserId => $meals) {
            foreach ($meals as $mealType => $selected) {
                if (!$selected) {
                    continue;
                }

                $roomId = $data['i_id_room'] ?? null;
                if (!isset($rooms[$roomId])) {
                    // 権限外の部屋はスキップ
                    continue;
                }

                // 重複チェック
                $existingReservation = $this->TIndividualReservationInfo->find()
                    ->contain(['MUserInfo', 'MRoomInfo'])
                    ->where([
                        'TIndividualReservationInfo.i_id_user'          => $targetUserId,
                        'TIndividualReservationInfo.d_reservation_date' => $reservationDate,
                        'TIndividualReservationInfo.i_reservation_type' => $mealType,
                    ])
                    ->first();

                if ($existingReservation) {
                    // eat_flag = 0 (キャンセル済み) の場合のみ更新可能
                    if ((int)$existingReservation->eat_flag === 0) {
                        $this->TIndividualReservationInfo->patchEntity($existingReservation, [
                            'i_id_room'     => $roomId,
                            'eat_flag'      => 1,
                            'i_change_flag' => 1,
                            'c_update_user' => $creatorName,
                            'dt_update'     => FrozenTime::now(),
                        ]);
                        if ($this->TIndividualReservationInfo->save($existingReservation)) {
                            continue;
                        } else {
                            Log::error('既存予約の更新に失敗しました。UserId: ' . $targetUserId . ', MealType: ' . $mealType);
                        }
                    }
                    
                    // eat_flag = 1 (予約済み) の場合は重複としてスキップ
                    $reservedUserName = $existingReservation->MUserInfo->c_user_name ?? null;
                    if (!$reservedUserName) {
                        $userInfo = $this->MUserInfo->find()->where(['i_id_user' => $targetUserId])->first();
                        $reservedUserName = $userInfo ? $userInfo->c_user_name : '不明なユーザー名';
                    }

                    $reservedRoomName = $existingReservation->MRoomInfo->c_room_name ?? null;
                    if (!$reservedRoomName) {
                        $roomInfo = $this->MRoomInfo->find()->where(['i_id_room' => $roomId])->first();
                        $reservedRoomName = $roomInfo ? $roomInfo->c_room_name : '不明な部屋名';
                    }

                    $duplicates[] = [
                        'user_name' => $reservedUserName,
                        'meal_type' => $mealTypeNames[$mealType] ?? $mealType,
                        'room_name' => $reservedRoomName
                    ];
                    continue;
                }

                // 新規予約エンティティを作成
                $newReservation = $this->TIndividualReservationInfo->patchEntity(
                    $this->TIndividualReservationInfo->newEmptyEntity(),
                    [
                        'i_id_user'          => $targetUserId,
                        'd_reservation_date' => $reservationDate,
                        'i_id_room'          => $roomId,
                        'i_reservation_type' => $mealType,
                        'eat_flag'           => 1,
                        'i_change_flag'      => 1,
                        'c_create_user'      => $creatorName,
                        'dt_create'          => FrozenTime::now(),
                    ]
                );
                $reservationsToSave[] = $newReservation;
            }
        }

        // 予約登録処理
        if (!empty($reservationsToSave)) {
            if (!$this->TIndividualReservationInfo->saveMany($reservationsToSave)) {
                $errorMessages = [];
                foreach ($reservationsToSave as $entity) {
                    foreach ($entity->getErrors() as $fieldErrors) {
                        foreach ($fieldErrors as $message) {
                            $errorMessages[] = $message;
                        }
                    }
                }
                if (empty($errorMessages)) {
                    $errorMessages[] = '原因不明のエラーが発生しました。';
                }

                return $this->jsonErrorResponse(
                    __('予約の登録中にエラーが発生しました。詳細: {0}', implode('、', $errorMessages)), 500
                );
            }
        }

        // 重複がある場合は警告付きで成功レスポンスを返す（モーダル側は close → カレンダー再読込）
        if (!empty($duplicates)) {
            return $this->jsonSuccessResponse(
                __('一部の予約はすでに存在していたためスキップされました。'),
                ['skipped' => $duplicates, 'date' => $reservationDate],
                $this->request->getAttribute('webroot') . 'TReservationInfo/'
            );
        }

        return $this->jsonSuccessResponse(
            __('予約が正常に登録されました。'),
            ['date' => $reservationDate],
            $this->request->getAttribute('webroot') . 'TReservationInfo/'
        );
    }



    /**
     * 重複情報配列を所定のフォーマット（「ユーザー名: ~, 食事タイプ: ~, 部屋名: ~」）に整形して返す関数
     *
     * @param array $duplicates
     * @return string
     */
    private function formatDuplicateMessage(array $duplicates)
    {
        $messages = [];
        foreach ($duplicates as $dup) {
            $messages[] = sprintf(
                'ユーザー名: %s, 食事タイプ: %s, 部屋名: %s',
                $dup['user_name'],
                $dup['meal_type'],
                $dup['room_name']
            );
        }
        return implode("\n", $messages);
    }




    /**
     * エラーレスポンスをJSON形式で返す
     *
     * @param string $message エラーメッセージ
     * @param array $data 追加データ
     * @return \Cake\Http\Response JSONレスポンス
     */


    /**
     * 成功レスポンスをJSON形式で返す
     *
     * @param string $message 成功メッセージ
     * @param array $data 追加データ
     * @param string|null $redirect リダイレクト先URL
     * @return \Cake\Http\Response JSONレスポンス
     */
    protected function jsonErrorResponse(string $message, int $status = 400, array $data = [])
    {
        $payload = ['ok'=>false,'status'=>'error','message'=>$message,'data'=>$data];
        return $this->response
            ->withType('application/json')
            ->withStatus($status)
            ->withStringBody(json_encode($payload, JSON_UNESCAPED_UNICODE));
    }

    protected function jsonSuccessResponse(string $message, array $data = [], string $redirect = null, int $status = 200)
    {
        $payload = ['ok'=>true,'status'=>'success','message'=>$message,'data'=>$data];
        if ($redirect) $payload['redirect'] = $redirect;

        return $this->response
            ->withType('application/json')
            ->withStatus($status)
            ->withStringBody(json_encode($payload, JSON_UNESCAPED_UNICODE));
    }


    public function getUsersByRoomForBulk($roomId)
    {
        // 部屋IDに基づいてその部屋に所属するユーザーを取得
        $users = $this->MUserGroup->find()
            ->contain(['MUserInfo'])
            ->where([
                'MUserGroup.i_id_room' => $roomId,
                'MUserInfo.i_del_flag' => 0,
                'MUserGroup.active_flag' => 0 // ※有効フラグが「0」で良いか要確認
            ])
            ->all();

        // 必要なデータを整形
        $userData = [];
        foreach ($users as $user) {
            $userData[] = [
                'id' => $user->i_id_user,
                'name' => $user->m_user_info->c_user_name
            ];
        }

        // JSON形式で返す
        return $this->response
            ->withType('json')
            ->withStringBody(json_encode(['users' => $userData]));
    }


    /**
     * 一括登録に必要なフォームを作成するメソッド
     *
     * 日付をクエリパラメータから取得し、週の月曜日から金曜日までの日付を生成します。
     * 自分が所属している部屋しか表示できないように設計されている。
     *
     * @return \Cake\Http\Response|void|null リダイレクトまたはビューのレンダリング
     * @throws \DateMalformedStringException 無効な日付形式の場合
     */
    public function bulkAddForm()
    {
        $selectedDate = $this->request->getQuery('date');

        if (!$selectedDate) {
            $this->Flash->error(__('日付が指定されていません。'));
            return $this->redirect(['action' => 'index']);
        }

        try {
            $startDate = new \DateTime($selectedDate);
            $startDate->modify('monday this week');
        } catch (\Exception $e) {
            $this->Flash->error(__('無効な日付が指定されました。'));
            return $this->redirect(['action' => 'index']);
        }

        $dates = [];
        for ($i = 0; $i < 5; $i++) {
            $dates[] = (clone $startDate)->format('Y-m-d');
            $startDate->modify('+1 day');
        }

        // ログインユーザーID取得
        $userId = $this->request->getAttribute('identity')->get('i_id_user');

        // 自分が所属している部屋のみ取得
        $rooms = $this->MRoomInfo->find('list', [
            'keyField' => 'i_id_room',
            'valueField' => 'c_room_name'
        ])
            ->matching('MUserGroup', function ($q) use ($userId) {
                return $q->where(['MUserGroup.i_id_user' => $userId]);
            })
            ->toArray();

        // ユーザー一覧取得
        $users = $this->MUserInfo->find('list', [
            'keyField' => 'i_id_user',
            'valueField' => 'c_user_name'
        ])->toArray();

        $this->set(compact('dates', 'rooms', 'users', 'selectedDate'));
    }

    /**
     * @return \Cake\Http\Response
     * 一括登録のフォームから送信されたデータを処理するメソッド
     * 予約タイプ（個人予約 or 集団予約）を選択し、各日付と食事タイプに対して登録を行います。
     * 個人予約の場合は、ユーザーごとに日付と食事タイプを選択し、登録済みの予約がないか確認します。→登録済みの場合は登録をスキップします
     * 集団予約の場合は、日付ごとにユーザーと食事タイプを選択し、登録済みの予約がないか確認します。→登録済みの場合は登録をスキップします
     *
     */
    public function bulkAddSubmit()
    {
        $data = $this->request->getData();

        try {
            $reservationType = $data['reservation_type'] ?? null;
            if (!$reservationType) {
                return $this->response->withType('json')->withStringBody(json_encode([
                    'status' => 'error',
                    'message' => '予約タイプが選択されていません。',
                ]));
            }

            $reservations = [];
            $mealTimeMap = [
                1 => 'morning',
                2 => 'noon',
                3 => 'night',
                4 => 'bento'
            ];
            $mealTimeRevMap = array_flip($mealTimeMap);
            $mealTimeDisplayNames = [
                'morning' => '朝',
                'noon' => '昼',
                'night' => '夜',
                'bento' => '弁当',
            ];
            $skippedMessages = [];

            $userName = $this->request->getAttribute('identity')->get('c_user_name');
            $userId = $this->request->getAttribute('identity')->get('i_id_user');

            if ($reservationType === 'personal') {
                // 個人予約
                // dates[<日付>] = 1, meals[<mealType>][<roomId>] = 1
                $dates = isset($data['dates']) && is_array($data['dates']) ? $data['dates'] : [];
                $meals = isset($data['meals']) && is_array($data['meals']) ? $data['meals'] : [];
                foreach ($dates as $date => $checkedDate) {
                    if (!$checkedDate) continue;
                    foreach ($meals as $mealType => $roomList) {
                        $mealType = (string)$mealType;
                        foreach ($roomList as $roomId => $checkedMeal) {
                            if (!$checkedMeal) continue; // チェック無
                            // 部屋を問わず登録済みかチェック
                            $existing = $this->TIndividualReservationInfo->find()
                                ->where([
                                    'd_reservation_date' => $date,
                                    'i_reservation_type' => $mealType,
                                    'i_id_user' => $userId,
                                    'eat_flag' => 1
                                ])
                                ->first();
                            if ($existing) {
                                // 部屋名取得
                                $roomName = '';
                                if ($roomId) {
                                    $roomInfo = $this->MRoomInfo->find()->select(['c_room_name'])->where(['i_id_room' => $roomId])->first();
                                    $roomName = $roomInfo ? $roomInfo->c_room_name : '';
                                }
                                $skippedMessages[] = sprintf(
                                    '日付 %s（%s）の"%s"は %s の予約が既に存在していたためスキップしました。',
                                    $date,
                                    $roomName,
                                    $mealTimeDisplayNames[$mealTimeMap[$mealType]],
                                    $userName
                                );
                                continue;
                            }
                            $reservation = $this->TIndividualReservationInfo->newEmptyEntity();
                            $reservation->d_reservation_date = $date;
                            $reservation->i_id_room = $roomId;
                            $reservation->i_reservation_type = $mealType;
                            $reservation->i_id_user = $userId;
                            $reservation->eat_flag = 1;
                            $reservation->c_create_user = $userName;
                            $reservation->dt_create = date('Y-m-d H:i:s');
                            $reservations[] = $reservation;
                        }
                    }
                }
            } elseif ($reservationType === 'group') {
                // 集団予約
                // dates[<日付>] = 1, users[<userId>][<mealTime>] = 1
                $dates = isset($data['dates']) && is_array($data['dates']) ? $data['dates'] : [];
                $users = isset($data['users']) && is_array($data['users']) ? $data['users'] : [];
                $roomId = $data['i_id_room'] ?? null;
                foreach ($dates as $date => $checkedDate) {
                    if (!$checkedDate) continue;
                    foreach ($users as $userId => $mealData) {
                        foreach ($mealTimeDisplayNames as $mealTime => $disp) {
                            if (isset($mealData[$mealTime]) && intval($mealData[$mealTime]) === 1) {
                                // 部屋を問わず登録済みかチェック
                                $existing = $this->TIndividualReservationInfo->find()
                                    ->where([
                                        'd_reservation_date' => $date,
                                        'i_reservation_type' => $mealTimeRevMap[$mealTime],
                                        'i_id_user' => $userId,
                                        'eat_flag' => 1
                                    ])
                                    ->first();
                                if ($existing) {
                                    // ユーザー名取得
                                    $userInfo = $this->MUserInfo->find()
                                        ->select(['c_user_name'])
                                        ->where(['i_id_user' => $userId])
                                        ->first();
                                    $userNameDisp = $userInfo ? $userInfo->c_user_name : '不明なユーザー';
                                    $skippedMessages[] = sprintf(
                                        '日付 %s "%s"は %s の予約が既に存在していたためスキップしました。',
                                        $date,
                                        $disp,
                                        $userNameDisp
                                    );
                                    continue;
                                }
                                $reservation = $this->TIndividualReservationInfo->newEmptyEntity();
                                $reservation->d_reservation_date = $date;
                                $reservation->i_id_room = $roomId;
                                $reservation->i_reservation_type = $mealTimeRevMap[$mealTime];
                                $reservation->i_id_user = $userId;
                                $reservation->eat_flag = 1;
                                $reservation->c_create_user = $userName;
                                $reservation->dt_create = date('Y-m-d H:i:s');
                                $reservations[] = $reservation;
                            }
                        }
                    }
                }
            } else {
                return $this->response->withType('json')->withStringBody(json_encode([
                    'status' => 'error',
                    'message' => '無効な予約タイプが選択されました。',
                ]));
            }

            if (!empty($reservations)) {
                $this->TIndividualReservationInfo->saveMany($reservations);
            }

            return $this->response->withType('json')->withStringBody(json_encode([
                'status' => 'success',
                'message' => !empty($skippedMessages)
                    ? "一部の予約は既に存在していたためスキップされました。\n" . implode("\n", $skippedMessages)
                    : "すべての予約が正常に登録されました。",
                'redirect_url' => './',
            ]));
        } catch (\Exception $e) {
            $this->log('Error occurred: ' . $e->getMessage(), 'error');
            return $this->response->withType('json')->withStringBody(json_encode([
                'status' => 'error',
                'message' => 'エラーが発生しました: ' . $e->getMessage(),
            ]));
        }
    }

    /**
     * change_edit メソッド（直前変更用）
     *
     * 予約済みレコードがある場合はそのレコードの i_change_flag を更新。
     * レコードが無い場合は eat_flag=0 / i_change_flag=1 で新規作成する。
     *
     * POST / PATCH / PUT のみ許可。
     */
    /**
     * 直前編集（当日～前日 13:00 以降など）専用エンドポイント
     *
     * fetch で JSON を送る場合と通常フォーム POST の両方を許可します。
     * 　{
     *     "d_reservation_date": "2025-08-10",
     *     "i_reservation_type": 2,   // 1=朝,2=昼,3=夜,4=弁当
     *     "i_change_flag"     : 0    // 0=変更無し,1=変更有り
     *  }
     *
     * ※ URL 例: POST /t-reservation-info/change-edit
     */

    /**
     * 直前編集（モーダル用API/画面）
     *
     * Route:
     *   /t-individual-reservation-info/change-edit/:roomId/:date/:mealType(.json)
     * Methods:
     *   GET  -> 初期データ(JSON/HTML)
     *   POST/PUT -> 一括更新(JSON/HTML)
     *
     * @param int|null $roomId
     * @param string|null $date (YYYY-MM-DD)
     * @param int|null $mealType (1=朝,2=昼,3=夜,4=弁当)
     */
    public function changeEdit($roomId = null, $date = null, $mealType = null)
    {
        try {
            // ---- パラメータ補完（route/query 両対応）----
            $roomId   = $roomId   ?? $this->request->getParam('roomId')   ?? $this->request->getQuery('roomId');
            $date     = $date     ?? $this->request->getParam('date')     ?? $this->request->getQuery('date');
            // ALL モード固定（モーダルで 4 食種まとめ）

            // 応答種別
            $wantsJson =
                $this->request->is('ajax') ||
                $this->request->getQuery('ajax') === '1' || // ★ クエリで強制的に JSON を要求
                $this->request->accepts('application/json') ||
                $this->request->getParam('_ext') === 'json';

            // "モーダルの殻"GET（/TReservationInfo/changeEdit?modal=1 ...）を許容
            $isModalShell = $this->request->is('get') && ((string)$this->request->getQuery('modal') === '1');

            // ログインユーザー
            $loginUser = $this->request->getAttribute('identity');
            $loginUid  = $loginUser?->get('i_id_user');

            // ---- 選択可能な部屋を「ログインユーザーの所属部屋のみに制限」----
            // 管理者であっても"全部屋"は出しません。
            $allowedRooms = $this->MUserGroup->find()
                ->select(['MUserGroup.i_id_room', 'MRoomInfo.c_room_name'])
                ->contain(['MRoomInfo'])
                ->where([
                    'MUserGroup.i_id_user'   => $loginUid,
                    'MUserGroup.active_flag' => 0,
                ])
                ->enableHydration(false)
                ->all()
                ->combine('i_id_room', fn($r) => $r['m_room_info']['c_room_name'] ?? '不明な部屋')
                ->toArray();

            // 所属が1件も無いが roomId が指定されている場合は、その roomId が実在すれば単一選択肢として許可
            if (empty($allowedRooms) && $roomId) {
                $roomExists = $this->MRoomInfo->exists(['i_id_room' => $roomId]);
                if ($roomExists) {
                    $one = $this->MRoomInfo->find()
                        ->select(['i_id_room','c_room_name'])
                        ->where(['i_id_room' => $roomId])
                        ->first();
                    if ($one) {
                        $allowedRooms = [(int)$one->i_id_room => (string)$one->c_room_name];
                    }
                }
            }

            // ---- 殻 GET（modal=1）: roomId/date 未指定でもレンダリング ----
            if ($isModalShell) {
                $rooms = $allowedRooms; // ★所属部屋のみ
                $room  = null;
                if ($roomId && isset($rooms[(int)$roomId])) {
                    $room = $this->MRoomInfo->find()
                        ->select(['i_id_room', 'c_room_name'])
                        ->where(['i_id_room' => $roomId])
                        ->first();
                }
                $users = new \Cake\Collection\Collection([]);
                $userReservations = [];
                $this->set(compact('room','rooms','date','users','userReservations'));
                return $this->render('change_edit');
            }

            // ---- バリデーション（通常/JSON）----
            if (!$roomId || !$date) {
                throw new \InvalidArgumentException('部屋IDまたは日付が指定されていません。');
            }

            // roomId が"所属外"なら拒否（管理者も同様の制限）
            if (!isset($allowedRooms[(int)$roomId])) {
                if ($wantsJson) {
                    return $this->response->withStatus(403)->withType('application/json')
                        ->withStringBody(json_encode(['status'=>'error','message'=>'この部屋を操作する権限がありません。']));
                }
                $this->Flash->error(__('この部屋を操作する権限がありません。'));
                return $this->redirect(['action' => 'index']);
            }

            // 18:00 制限（当日のみ）
            $now = FrozenTime::now('Asia/Tokyo');
            if ($date === $now->format('Y-m-d') && (int)$now->format('H') >= 18) {
                $msg = '18:00 を過ぎたため直前編集はできません。';
                if ($wantsJson) {
                    return $this->response->withType('application/json')
                        ->withStringBody(json_encode(['status'=>'error','message'=>$msg]));
                }
                $this->Flash->error(__($msg));
                return $this->redirect(['action' => 'index']);
            }

            // 対象部屋取得（存在チェック）
            $room = $this->MRoomInfo->find()
                ->select(['i_id_room','c_room_name'])
                ->where(['i_id_room'=>$roomId])
                ->first();
            if (!$room) {
                throw new NotFoundException(__('部屋が見つかりません。'));
            }

            // この部屋に所属する利用者（氏名）だけを取得
            $baseUserIds = $this->MUserGroup->find()
                ->select(['i_id_user'])
                ->contain(['MUserInfo'])
                ->where([
                    'MUserGroup.i_id_room'   => $roomId,
                    'MUserGroup.active_flag' => 0,
                    'MUserInfo.i_del_flag'   => 0,
                ])
                ->enableHydration(false)->all()->extract('i_id_user')->toList();
            if (empty($baseUserIds)) { $baseUserIds = [-1]; }

            // ★i_user_level を含めて取得
            $userEntities = $this->MUserInfo->find()
                ->select(['i_id_user', 'c_user_name', 'i_user_level'])
                ->where(['i_id_user IN' => $baseUserIds, 'i_del_flag' => 0])
                ->all();

            $users = [];
            foreach ($userEntities as $userEntity) {
                $users[] = [
                    'id'           => (int)$userEntity->i_id_user,
                    'name'         => (string)$userEntity->c_user_name,
                    'i_user_level' => (int)$userEntity->i_user_level,
                ];
            }
            usort($users, fn($a,$b) => strcmp($a['name'], $b['name']));
            $userIdList = array_map(fn($u) => $u['id'], $users);

            // 対象日の既存予約（ALL: 1..4）
            $reservations = $this->TIndividualReservationInfo->find()
                ->contain(['MRoomInfo'])
                ->where([
                    'd_reservation_date'    => $date,
                    'i_reservation_type IN' => [1,2,3,4],
                    'i_id_user IN'          => $userIdList ?: [-1],
                ])->all();

            $userReservations = []; // [uid][type] => flags
            foreach ($reservations as $r) {
                $userReservations[(int)$r->i_id_user][(int)$r->i_reservation_type] = [
                    'room_id'       => (int)$r->i_id_room,
                    'eat_flag'      => (int)$r->eat_flag,
                    'room_name'     => (string)($r->m_room_info->c_room_name ?? '不明な部屋'),
                    'i_change_flag' => (int)$r->i_change_flag,
                ];
            }

            // ---- GET: JSON/HTML ----
            if ($this->request->is('get')) {
                if ($wantsJson) {
                    $isAdmin  = ($loginUser && ($loginUser->get('i_admin') === 1 || (int)$loginUser->get('i_user_level') === 0));
                    $loginUid = $loginUser?->get('i_id_user');

                    // ★isStaff, allowEdit, i_user_level を付与
                    $usersForJson = [];
                    foreach ($users as $u) {
                        $allowEdit = $isAdmin || ($loginUid && (int)$loginUid === (int)$u['id']);
                        $usersForJson[] = [
                            'id'           => $u['id'],
                            'name'         => $u['name'],
                            'i_user_level' => $u['i_user_level'],
                            'userLevel'    => $u['i_user_level'], // ★ JS 互換用
                            'isStaff'      => ($u['i_user_level'] === 0), // ★ boolean で isStaff を追加
                            'allowEdit'    => $allowEdit,
                        ];
                    }

                    return $this->response->withType('application/json')
                        ->withStringBody(json_encode([
                            'status' => 'success',
                            'data'   => [
                                'contextRoom'      => ['id' => (int)$room->i_id_room, 'name' => (string)$room->c_room_name],
                                'date'             => $date,
                                'users'            => $usersForJson,
                                'userReservations' => $userReservations,
                            ]
                        ], JSON_UNESCAPED_UNICODE));
                }

                $rooms = $allowedRooms;
                $this->set(compact('room','rooms','date','users','userReservations'));
                return $this->render('change_edit');
            }

            // ---- POST/PUT: 保存（4 食種まとめ・URL の roomId 固定）----
            if ($this->request->is(['post','put'])) {
                $data = $this->request->getData();
                if (empty($data)) {
                    $parsed = $this->request->input('json_decode', true);
                    if (is_array($parsed)) $data = $parsed;
                }
                $usersData = (isset($data['users']) && is_array($data['users'])) ? $data['users'] : [];

                $connection = $this->TIndividualReservationInfo->getConnection();
                $connection->begin();

                $updated=[]; $created=[]; $skipped=[];

                try {
                    foreach ($usersData as $userIdRaw=>$meals) {
                        $userId = (int)$userIdRaw;
                        if (!in_array($userId, $userIdList, true)) {
                            $skipped[] = "利用者ID {$userId} はこの部屋の所属ではないためスキップされました。";
                            continue;
                        }

                        // 対象ユーザーの情報を取得（i_user_levelチェック用）
                        $targetUser = $this->MUserInfo->find()
                            ->select(['i_user_level'])
                            ->where(['i_id_user' => $userId])
                            ->first();
                        $targetUserLevel = $targetUser ? (int)$targetUser->i_user_level : null;

                        foreach ($meals as $mealTypeRaw=>$flags) {
                            $mealType = (int)$mealTypeRaw;
                            if (!in_array($mealType, [1,2,3,4], true)) continue;

                            $changeFlag = isset($flags['i_change_flag']) ? (int)$flags['i_change_flag'] : null;
                            if ($changeFlag === null) continue; // 変更なし

                            $existing = $this->TIndividualReservationInfo->find()
                                ->where([
                                    'i_id_user' => $userId,
                                    'd_reservation_date' => $date,
                                    'i_reservation_type' => $mealType,
                                ])->first();

                            if ($existing) {
                                // 職員(i_user_level=0)は直前編集でのキャンセル(i_change_flag=0)不可
                                if ($targetUserLevel === 0 && $changeFlag === 0) {
                                    $skipped[] = "利用者ID {$userId} は職員のため、直前編集でのキャンセルはできません。";
                                    continue;
                                }

                                if ((int)$existing->i_change_flag !== $changeFlag) {
                                    $this->TIndividualReservationInfo->patchEntity($existing, [
                                        'i_change_flag' => $changeFlag,
                                        'c_update_user' => $loginUser?->get('c_user_name') ?? 'system',
                                        'dt_update'     => FrozenTime::now('Asia/Tokyo'),
                                    ]);
                                    if ($this->TIndividualReservationInfo->save($existing)) {
                                        $updated[] = "{$userId}:{$mealType}";
                                    } else {
                                        throw new \Exception("利用者ID {$userId} の予約更新に失敗しました。");
                                    }
                                }
                            } else {
                                // 新規作成（追加）は職員・子供ともに可能
                                $new = $this->TIndividualReservationInfo->newEntity([
                                    'i_id_user'          => $userId,
                                    'd_reservation_date' => $date,
                                    'i_reservation_type' => $mealType,
                                    'i_id_room'          => $roomId,
                                    'eat_flag'           => 0, // 直前変更は通常予約フラグ OFF
                                    'i_change_flag'      => $changeFlag,
                                    'c_create_user'      => $loginUser?->get('c_user_name') ?? 'system',
                                    'dt_create'          => FrozenTime::now('Asia/Tokyo'),
                                ]);
                                if ($this->TIndividualReservationInfo->save($new)) {
                                    $created[] = "{$userId}:{$mealType}";
                                } else {
                                    throw new \Exception("利用者ID {$userId} の新規予約作成に失敗しました。");
                                }
                            }
                        }
                    }

                    $connection->commit();

                    $payload = [
                        'status'  => 'success',
                        'message' => '直前予約を更新しました。',
                        'data'    => ['updated' => $updated, 'created' => $created, 'skipped' => $skipped]
                    ];
                    if ($wantsJson) {
                        return $this->response->withType('application/json')->withStringBody(json_encode($payload));
                    }
                    $this->Flash->success(__($payload['message']));
                    return $this->redirect(['action' => 'index']);

                } catch (\Throwable $e) {
                    $connection->rollback();
                    $this->log('直前編集エラー: '.$e->getMessage(), 'error');
                    if ($wantsJson) {
                        return $this->response->withStatus(500)->withType('application/json')
                            ->withStringBody(json_encode(['status'=>'error','message'=>'直前予約の更新中にエラーが発生しました。']));
                    }
                    $this->Flash->error(__('直前予約の更新中にエラーが発生しました。'));
                }
            }

            // HTML フォールバック
            $rooms = $allowedRooms;
            if (!isset($users)) $users = [];
            if (!isset($userReservations)) $userReservations = [];
            $this->set(compact('room','rooms','date','users','userReservations'));
            return $this->render('change_edit');

        } catch (\Throwable $e) {
            // ここで「AJAX っぽいかどうか」を再判定して、JSON を返す
            $wantsJson =
                $this->request->is('ajax') ||
                $this->request->getQuery('ajax') === '1' ||
                $this->request->accepts('application/json') ||
                $this->request->getParam('_ext') === 'json';

            $this->log('changeEdit error: ' . $e->getMessage(), 'error');

            if ($wantsJson) {
                // ざっくりエラー種別でステータスを分ける
                if ($e instanceof \InvalidArgumentException) {
                    $status = 400;
                } elseif ($e instanceof NotFoundException) {
                    $status = 404;
                } else {
                    $status = 500;
                }

                return $this->response
                    ->withStatus($status)
                    ->withType('application/json')
                    ->withStringBody(json_encode([
                        'status'  => 'error',
                        'message' => $e->getMessage() ?: '直前予約の取得中にエラーが発生しました。',
                    ], JSON_UNESCAPED_UNICODE));
            }

            // 通常アクセスなら今まで通り Cake に投げる
            throw $e;
        }
    }


    public function getMealCounts($date)
    {
        $mealCounts = $this->TIndividualReservationInfo->find()
            ->select([
                'meal_type' => 'i_reservation_type',
                'count' => $this->TIndividualReservationInfo->find()->func()->count('*')
            ])
            ->where([
                'd_reservation_date' => $date,
                'eat_flag' => 1,// 集計対象は eat_flag = 1 のみ
                'i_change_flag' => 1 // 直前変更の予約のみ(deafault: 1)
            ])
            ->groupBy('i_reservation_type')
            ->toArray();

        return $mealCounts;
    }


    public function getUsersByRoomForEdit($roomId)
    {
        $date = $this->request->getQuery('date');
        $this->request->allowMethod(['get', 'ajax']);
        $this->autoRender = false;

        // 部屋に所属するユーザーを取得
        $usersByRoom = $this->MUserGroup->find()
            ->select(['i_id_user', 'i_id_room', 'MUserInfo.c_user_name'])
            ->where(['i_id_room' => $roomId])
            ->contain(['MUserInfo'])
            ->toArray();

        // ユーザーID一覧
        $userIds = collection($usersByRoom)->extract('i_id_user')->toList();

        // 指定された日付・部屋・ユーザーに対する全予約を一括取得
        $reservations = $this->TIndividualReservationInfo->find()
            ->where([
                'i_id_room' => $roomId,
                'd_reservation_date' => $date,
                'i_id_user IN' => $userIds,
            ])
            ->all()
            ->groupBy('i_id_user')
            ->toArray();

        $completeUserInfo = [];

        foreach ($usersByRoom as $user) {
            $userId = $user->i_id_user;
            $userReservations = $reservations[$userId] ?? [];

            // 食事種別ごとの予約の有無をチェック
            $mealMap = [
                1 => 'morning',
                2 => 'noon',
                3 => 'night',
                4 => 'bento',
            ];
            $mealStatus = array_fill_keys(array_values($mealMap), false);

            foreach ($userReservations as $r) {
                $key = $mealMap[$r->i_reservation_type] ?? null;
                if ($key) {
                    $mealStatus[$key] = true;
                }
            }

            $completeUserInfo[] = [
                'id' => $userId,
                'name' => $user->m_user_info->c_user_name ?? '不明なユーザー',
                'meals' => $mealStatus,
            ];
        }

        return $this->response->withType('application/json')
            ->withStringBody(json_encode(['usersByRoom' => $completeUserInfo]));
    }

    private function saveIndividualReservation($userId, $reservationDate, $roomId, $mealTime, $username): array
    {
        $reservation = $this->TIndividualReservationInfo->newEntity([
            'i_id_user' => $userId,
            'd_reservation_date' => $reservationDate,
            'i_id_room' => $roomId,
            'i_reservation_type' => $mealTime,
            'eat_flag' => 1,
            'i_change_flag' => 1, // 直前変更フラグ
            'c_create_user' => $username,
            'dt_create' => FrozenTime::now()
        ]);

        if (!$this->TIndividualReservationInfo->save($reservation)) {
            $errors = $reservation->getErrors();
            Log::error('Reservation save failed: ' . json_encode($errors, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            return ['success' => false, 'errors' => $errors];
        }

        return ['success' => true];
    }

    /**
     * JSON形式で予約情報をエクスポートするメソッド
     * Json形式で指定された月の予約情報をエクスポートします。
     * @return \Cake\Http\Response JSONレスポンス
     */
    /**
     * 月次予約データを JSON で返却
     * - overall : 全室合算（集計用）
     * - rooms   : 部屋別
     *
     * フロント側の例:
     *  data.overall            // 全体シート
     *  data.rooms['101号室']   // 各部屋シート
     */
    public function exportJson()
    {
        try {
            /* =============================================================
             * 1. パラメータ取得
             *      - from : 期間開始日 (YYYY-MM-DD)
             *      - to   : 期間終了日 (YYYY-MM-DD)
             * ============================================================= */
            $from = $this->request->getQuery('from'); // 例: 2025-07-01
            $to   = $this->request->getQuery('to');   // 例: 2025-07-15

            if (!$from || !$to) {
                throw new \InvalidArgumentException(
                    '開始日・終了日を指定してください (例: from=2025-07-01&to=2025-07-15)'
                );
            }

            try {
                $startDate = new \DateTimeImmutable($from);
                $endDate   = new \DateTimeImmutable($to);
            } catch (\Exception $e) {
                throw new \InvalidArgumentException('日付の形式が正しくありません (YYYY-MM-DD)');
            }

            if ($startDate > $endDate) {
                throw new \InvalidArgumentException('開始日は終了日以前の日付を指定してください');
            }

            /* =============================================================
             * 2. DB から予約データ取得
             * ============================================================= */
            $reservations = $this->TIndividualReservationInfo->find()
                ->select([
                    'room_id'      => 'TIndividualReservationInfo.i_id_room',
                    'room_name'    => 'MRoomInfo.c_room_name',
                    'd_reservation_date',
                    'meal_type'    => 'TIndividualReservationInfo.i_reservation_type',
                    'total_eaters' => $this->TIndividualReservationInfo->find()->func()->count('*'),
                ])
                ->join([
                    'table'      => 'm_room_info',
                    'alias'      => 'MRoomInfo',
                    'type'       => 'INNER',
                    'conditions' => 'MRoomInfo.i_id_room = TIndividualReservationInfo.i_id_room',
                ])
                ->where([
                    'TIndividualReservationInfo.eat_flag'              => 1,
                    'TIndividualReservationInfo.d_reservation_date >=' => $startDate->format('Y-m-d'),
                    'TIndividualReservationInfo.d_reservation_date <=' => $endDate->format('Y-m-d'),
                ])
                ->groupBy([
                    'TIndividualReservationInfo.i_id_room',
                    'MRoomInfo.c_room_name',
                    'TIndividualReservationInfo.d_reservation_date',
                    'TIndividualReservationInfo.i_reservation_type',
                ])
                ->enableHydration(false)
                ->toArray();

            /* =============================================================
             * 3. データ整形（以下ロジックはそのまま）
             * ============================================================= */
            $result      = ['overall' => [], 'rooms' => []];
            $overallTmp  = [];
            $mealTypeMap = [1 => '朝', 2 => '昼', 3 => '夜', 4 => '弁当'];

            foreach ($reservations as $reservation) {
                $roomName    = $reservation['room_name'];
                $date        = $reservation['d_reservation_date']->format('Y-m-d');
                $mealLabel   = $mealTypeMap[$reservation['meal_type']];
                $totalEaters = (int)$reservation['total_eaters'];

                /* ---------- rooms ---------- */
                if (!isset($result['rooms'][$roomName][$date])) {
                    $result['rooms'][$roomName][$date] = ['朝' => 0, '昼' => 0, '夜' => 0, '弁当' => 0];
                }
                $result['rooms'][$roomName][$date][$mealLabel] += $totalEaters;

                /* ---------- overall ---------- */
                if (!isset($overallTmp[$roomName][$date])) {
                    $overallTmp[$roomName][$date] = ['朝' => 0, '昼' => 0, '夜' => 0, '弁当' => 0];
                }
                $overallTmp[$roomName][$date][$mealLabel] += $totalEaters;
            }

            foreach ($overallTmp as $roomName => $dates) {
                foreach ($dates as $date => $counts) {
                    $result['overall'][] = array_merge(
                        ['部屋名' => $roomName, '日付' => $date],
                        $counts
                    );
                }
            }

            /* =============================================================
             * 4. JSON で返却
             * ============================================================= */
            return $this->response
                ->withType('application/json')
                ->withStringBody(json_encode($result, JSON_UNESCAPED_UNICODE));
        } catch (\Exception $e) {
            Log::write('error', $e->getMessage());
            return $this->response
                ->withStatus(500)
                ->withType('application/json')
                ->withStringBody(json_encode(['error' => $e->getMessage()], JSON_UNESCAPED_UNICODE));
        }
    }

    /**
     * JSON形式でランク別の予約情報をエクスポートするメソッド
     * 指定された月のランク別予約情報をJSON形式でエクスポートします。
     *
     * @return \Cake\Http\Response JSONレスポンス
     */

    /**
     * ランク別・性別別・日付別の食数集計を JSON で返却
     *
     * 受け取るクエリ:
     *   - from : 開始日 (YYYY-MM-DD)
     *   - to   : 終了日 (YYYY-MM-DD)
     *   - month: 月     (YYYY-MM) ※from/to が無い場合のみ有効
     *
     * 例)
     *   /export-jsonrank?from=2025-06-01&to=2025-06-10
     *   /export-jsonrank?month=2025-06
     */
    public function exportJsonrank()
    {
        $this->autoRender = false;

        /* ---------- クエリ取得 & バリデーション ---------- */
        $from  = $this->request->getQuery('from');
        $to    = $this->request->getQuery('to');
        $month = $this->request->getQuery('month');

        $isDate  = static fn($d) => (bool)preg_match('/^\d{4}-\d{2}-\d{2}$/', $d);
        $isMonth = static fn($m) => (bool)preg_match('/^\d{4}-\d{2}$/', $m);

        // 期間 or 月 の判定
        if ($from !== null || $to !== null) {
            // どちらか片方しか無い、あるいは不正ならエラー
            if (!$from || !$to || !$isDate($from) || !$isDate($to) || strtotime($from) > strtotime($to)) {
                return $this->respondJson(['message' => '無効な期間が指定されました。']);
            }
            $startDate = $from;
            // 終了日を含めるため、翌日未満で検索
            $endDate   = date('Y-m-d', strtotime($to . ' +1 day'));
            $emptyMsg  = '指定された期間にデータが見つかりませんでした。';
        } else {
            // month 指定のみを許可
            if (!$month || !$isMonth($month)) {
                return $this->respondJson(['message' => '無効な月が指定されました。']);
            }
            $startDate = $month . '-01';
            $endDate   = date('Y-m-d', strtotime($month . ' +1 month'));
            $emptyMsg  = '指定された月にデータが見つかりませんでした。';
        }

        /* ---------- ランク名マッピング ---------- */
        $rankNames = [
            1 => '3~5歳',
            2 => '低学年',
            3 => '中学年',
            4 => '高学年',
            5 => '中学生',
            6 => '高校生',
            7 => '大人',
        ];

        /* ---------- データ取得 ---------- */
        $reservations = $this->TIndividualReservationInfo->find()
            ->select([
                'user_rank'        => 'MUserInfo.i_user_rank',
                'gender'           => 'MUserInfo.i_user_gender',
                'reservation_date' => 'TIndividualReservationInfo.d_reservation_date',
                'meal_type'        => 'TIndividualReservationInfo.i_reservation_type',
                'total_eaters'     => $this->TIndividualReservationInfo->find()->func()->count('*')
            ])
            ->join([
                [
                    'table'      => 'm_user_info',
                    'alias'      => 'MUserInfo',
                    'type'       => 'INNER',
                    'conditions' => 'MUserInfo.i_id_user = TIndividualReservationInfo.i_id_user',
                ]
            ])
            ->where([
                'TIndividualReservationInfo.i_change_flag'              => 1,
                'TIndividualReservationInfo.d_reservation_date >=' => $startDate,
                'TIndividualReservationInfo.d_reservation_date <'  => $endDate,
            ])
            ->groupBy([
                'MUserInfo.i_user_rank',
                'MUserInfo.i_user_gender',
                'TIndividualReservationInfo.d_reservation_date',
                'TIndividualReservationInfo.i_reservation_type',
            ])
            ->enableHydration(false)
            ->toArray();

        /* ---------- 結果整形 ---------- */
        $output = [];
        foreach ($reservations as $reservation) {
            $rankId  = $reservation['user_rank'];
            $gender  = $reservation['gender'] === 1 ? '男子'
                : ($reservation['gender'] === 2 ? '女子' : '不明');
            $rankName = $rankNames[$rankId] ?? '不明';

            $dateKey = $reservation['reservation_date']->format('Y-m-d');
            $key     = $rankId . '_' . $gender . '_' . $dateKey;

            if (!isset($output[$key])) {
                $output[$key] = [
                    'rank_name'      => $rankName,
                    'gender'         => $gender,
                    'reservation_date'=> $dateKey,
                    'breakfast'      => 0,
                    'lunch'          => 0,
                    'dinner'         => 0,
                    'bento'          => 0,
                    'total_eaters'   => 0,
                ];
            }

            $count = $reservation['total_eaters'] ?? 0;
            switch ($reservation['meal_type']) {
                case 1:  $output[$key]['breakfast'] += $count; break;
                case 2:  $output[$key]['lunch']     += $count; break;
                case 3:  $output[$key]['dinner']    += $count; break;
                case 4:  $output[$key]['bento']     += $count; break;
            }
            $output[$key]['total_eaters'] += $count;
        }

        $finalOutput = array_values($output);

        if (empty($finalOutput)) {
            $finalOutput = ['message' => $emptyMsg];
        }

        return $this->respondJson($finalOutput);
    }

    /**
     * 予約トグルAPI（個人が自分の1日1食区分をON/OFF）
     * 既存の Table 側に toggleMeal(...) が実装済みである前提です。
     * 14日前ルールやeat/changeの扱いは Table 側の実装に委譲します。
     */
    public function toggle(int $roomId)
    {
        $this->request->allowMethod(['post']);
        $this->response = $this->response->withType('application/json');

        // 認証ユーザー
        $loginUser = $this->request->getAttribute('identity');
        $loginUserId   = (int)($loginUser?->get('i_id_user') ?? $loginUser?->get('id') ?? 0);
        $loginUserName = (string)($loginUser?->get('c_login_account') ?? $loginUser?->get('c_user_name') ?? $loginUserId);
        if ($loginUserId <= 0) {
            return $this->response->withStatus(401)->withStringBody(json_encode([
                'ok'=>false,'message'=>'Unauthorized'
            ], JSON_UNESCAPED_UNICODE));
        }

        // ペイロード（form→JSON）
        $payload = (array)$this->request->getData();
        if (empty($payload)) {
            $payload = (array)($this->request->input('json_decode', true) ?? []);
        }
        if (empty($payload)) {
            return $this->response->withStatus(400)->withStringBody(json_encode([
                'ok'=>false,'message'=>'Empty request body.'
            ], JSON_UNESCAPED_UNICODE));
        }

        // ★ 修正: ペイロードから userId を取得。なければログインユーザーIDを使用
        $targetUserId = isset($payload['userId']) ? (int)$payload['userId'] : $loginUserId;
        $actorName    = $loginUserName; // 操作者は常にログインユーザー

        $dateStr = (string)($payload['date'] ?? '');
        $meal    = isset($payload['meal'])  ? (int)$payload['meal']  : null; // 1..4
        $value   = isset($payload['value']) ? (int)$payload['value'] : null; // 0/1
        
        // 対象ユーザーの情報を取得（i_user_levelチェック用）
        $targetUser = $this->MUserInfo->find()
            ->select(['i_user_level'])
            ->where(['i_id_user' => $targetUserId])
            ->first();
        $targetUserLevel = $targetUser ? (int)$targetUser->i_user_level : null;

        // 入力検証（422）
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateStr)) {
            return $this->response->withStatus(422)->withStringBody(json_encode([
                'ok'=>false,'message'=>'Invalid date format (Y-m-d).'
            ], JSON_UNESCAPED_UNICODE));
        }
        try { new \Cake\I18n\FrozenDate($dateStr); } catch (\Throwable $e) {
            return $this->response->withStatus(422)->withStringBody(json_encode([
                'ok'=>false,'message'=>'Invalid date value.'
            ], JSON_UNESCAPED_UNICODE));
        }
        if (!in_array($meal, [1,2,3,4], true)) {
            return $this->response->withStatus(422)->withStringBody(json_encode([
                'ok'=>false,'message'=>'Invalid meal type (1..4).'
            ], JSON_UNESCAPED_UNICODE));
        }
        if (!in_array($value, [0,1], true)) {
            return $this->response->withStatus(422)->withStringBody(json_encode([
                'ok'=>false,'message'=>'Invalid value (0 or 1).'
            ], JSON_UNESCAPED_UNICODE));
        }

        // ===== 直前(当日〜14日先)かをサーバ側で判定 =====
        $targetDate   = new \Cake\I18n\FrozenDate($dateStr);
        $today        = \Cake\I18n\FrozenDate::today();
        $lastDeadline = $today->addDays(14); // きょう + 14日
        $isLastMinute = ($targetDate >= $today && $targetDate <= $lastDeadline);

        /** @var \App\Model\Table\TIndividualReservationInfoTable $table */
        $table = $this->fetchTable('TIndividualReservationInfo');

        // ===== 既存行の有無（★id列を参照しない／実カラム名に合わせる）=====
        $exists = $table->exists([
            'i_id_user'          => $targetUserId, // ★ 修正: 対象ユーザーIDで検索
            'i_id_room'          => $roomId,
            'd_reservation_date' => $dateStr,
            'i_reservation_type' => $meal,
        ]);
        
        // ===== 職員の直前編集キャンセル制限 =====
        // 職員(i_user_level=0)は直前期間中のキャンセル(OFF)不可、子供(i_user_level=1)は可
        if ($isLastMinute && $targetUserLevel === 0 && $value === 0 && $exists) {
            return $this->response->withStatus(403)->withStringBody(json_encode([
                'ok'=>false,'message'=>'職員は直前編集でのキャンセルはできません。'
            ], JSON_UNESCAPED_UNICODE));
        }

        // ===== 保存フラグを決定 =====
        if ($exists) {
            // 既存行がある場合
            if ($value === 1) {
                // ONにする場合：通常予約は両方1、直前編集での予約はeat_flag維持でi_change_flag=1
                if ($isLastMinute) {
                    // 直前編集：既存のeat_flagを確認
                    $existingEntity = $table->find()
                        ->select(['eat_flag'])
                        ->where([
                            'i_id_user'          => $targetUserId,
                            'i_id_room'          => $roomId,
                            'd_reservation_date' => $dateStr,
                            'i_reservation_type' => $meal,
                        ])
                        ->first();
                    $eatFlag = $existingEntity ? (int)$existingEntity->eat_flag : 0;
                    $changeFlag = 1;
                } else {
                    // 通常予約：両方1
                    $eatFlag = 1;
                    $changeFlag = 1;
                }
            } else {
                // OFFにする場合：直前編集ではi_change_flag=0、通常予約では両方0
                if ($isLastMinute) {
                    $existingEntity = $table->find()
                        ->select(['eat_flag'])
                        ->where([
                            'i_id_user'          => $targetUserId,
                            'i_id_room'          => $roomId,
                            'd_reservation_date' => $dateStr,
                            'i_reservation_type' => $meal,
                        ])
                        ->first();
                    $eatFlag = $existingEntity ? (int)$existingEntity->eat_flag : 0;
                    $changeFlag = 0;
                } else {
                    $eatFlag = 0;
                    $changeFlag = 0;
                }
            }
        } else {
            // 新規行の場合
            if ($value === 1) {
                // ONにする場合：通常予約は両方1、直前編集での予約はeat_flag=0/i_change_flag=1
                $eatFlag    = $isLastMinute ? 0 : 1;
                $changeFlag = 1;
            } else {
                // OFFにする場合：両方0
                $eatFlag    = 0;
                $changeFlag = 0;
            }
        }

        try {
            $result = $table->toggleMeal(
                userId: $targetUserId, // ★ 修正: 対象ユーザーIDを渡す
                roomId: $roomId,
                date:   $dateStr,
                meal:   $meal,
                on:     $value === 1,
                actor:  $actorName, // ★ 修正: 操作者名を渡す
                // ここで確定させたフラグを必ず渡す（nullを渡さない）
                eatFlag: $eatFlag,
                changeFlag: $changeFlag,
            );

            return $this->response->withStringBody(json_encode([
                'ok'      => true,
                'value'   => (bool)$result['value'],
                'details' => $result['details'],
            ], JSON_UNESCAPED_UNICODE));

        } catch (\Cake\ORM\Exception\PersistenceFailedException $e) {
            $errors = $e->getEntity()?->getErrors() ?? [];
            $flat   = json_encode($errors, JSON_UNESCAPED_UNICODE);

            $isConflict = (is_string($flat) && preg_match('/(昼|弁|bento|lunch|unique.*bento|unique.*lunch)/ui', $flat));
            $status = $isConflict ? 409 : 422;

            return $this->response->withStatus($status)->withStringBody(json_encode([
                'ok'      => false,
                'message' => $isConflict ? '昼食と弁当は同時に予約できません。' : 'Validation failed.',
                'errors'  => \Cake\Core\Configure::read('debug') ? $errors : null,
            ], JSON_UNESCAPED_UNICODE));
        } catch (\InvalidArgumentException $e) {
            return $this->response->withStatus(422)->withStringBody(json_encode([
                'ok'=>false,'message'=>$e->getMessage()
            ], JSON_UNESCAPED_UNICODE));
        } catch (\Throwable $e) {
            return $this->response->withStatus(500)->withStringBody(json_encode([
                'ok'=>false,
                'message'=>'Internal Server Error',
                'debug'=> \Cake\Core\Configure::read('debug') ? ($e->getMessage()) : null,
            ], JSON_UNESCAPED_UNICODE));
        }
    }

    /**
     * 全部屋食数取得API（管理者用）
     * 管理者が全部屋の食数をカレンダーに表示するために使用
     * 
     * @return \Cake\Http\Response JSONレスポンス
     */
    public function getAllRoomsMealCounts()
    {
        $this->request->allowMethod(['get']);
        $this->autoRender = false;

        $user = $this->request->getAttribute('identity');
        if (!$user) {
            return $this->response
                ->withType('application/json')
                ->withStatus(401)
                ->withStringBody(json_encode(['error' => 'ログインが必要です。'], JSON_UNESCAPED_UNICODE));
        }

        $userLevel = (int)$user->get('i_user_level');
        $isAdmin = (int)$user->get('i_admin') === 1;

        // 管理者のみ許可
        if ($userLevel !== 0 || !$isAdmin) {
            return $this->response
                ->withType('application/json')
                ->withStatus(403)
                ->withStringBody(json_encode(['error' => '権限がありません。管理者のみアクセス可能です。'], JSON_UNESCAPED_UNICODE));
        }

        // 日付範囲を取得
        $fromDate = $this->request->getQuery('from');
        $toDate = $this->request->getQuery('to');
        
        if (!$fromDate || !$toDate) {
            // デフォルトで現在の月の前後1ヶ月のデータを取得
            $fromDate = date('Y-m-01', strtotime('-1 month'));
            $toDate = date('Y-m-t', strtotime('+1 month'));
        }

        try {
            // 全部屋の食数集計を取得
            $mealCounts = $this->TIndividualReservationInfo->find()
                ->select([
                    'date' => 'd_reservation_date',
                    'meal_type' => 'i_reservation_type',
                    'count' => $this->TIndividualReservationInfo->find()->func()->count('*')
                ])
                ->where([
                    'd_reservation_date >=' => $fromDate,
                    'd_reservation_date <=' => $toDate,
                    'eat_flag' => 1 // 有効な予約のみ
                ])
                ->groupBy(['d_reservation_date', 'i_reservation_type'])
                ->orderBy(['d_reservation_date' => 'ASC', 'i_reservation_type' => 'ASC'])
                ->toArray();

            // 日付別にデータを整理
            $result = [];
            foreach ($mealCounts as $row) {
                $date = $row->date->format('Y-m-d');
                $mealType = (int)$row->meal_type;
                $count = (int)$row->count;

                if (!isset($result[$date])) {
                    $result[$date] = [
                        'date' => $date,
                        'morning' => 0,    // 朝食
                        'lunch' => 0,      // 昼食
                        'dinner' => 0,     // 夕食
                        'bento' => 0,      // 弁当
                        'total' => 0
                    ];
                }

                switch ($mealType) {
                    case 1:
                        $result[$date]['morning'] = $count;
                        break;
                    case 2:
                        $result[$date]['lunch'] = $count;
                        break;
                    case 3:
                        $result[$date]['dinner'] = $count;
                        break;
                    case 4:
                        $result[$date]['bento'] = $count;
                        break;
                }

                $result[$date]['total'] += $count;
            }

            return $this->response
                ->withType('application/json')
                ->withStringBody(json_encode(array_values($result), JSON_UNESCAPED_UNICODE));

        } catch (\Exception $e) {
            return $this->response
                ->withType('application/json')
                ->withStatus(500)
                ->withStringBody(json_encode(['error' => 'データ取得に失敗しました。'], JSON_UNESCAPED_UNICODE));
        }
    }

    /**
     * 部屋別食数取得API（職員用）
     * 職員が自分の所属部屋の食数をカレンダーに表示するために使用
     * 
     * @param string $roomId 部屋ID
     * @return \Cake\Http\Response JSONレスポンス
     */
    public function getRoomMealCounts($roomId = null)
    {
        $this->request->allowMethod(['get']);
        $this->autoRender = false;

        $user = $this->request->getAttribute('identity');
        if (!$user) {
            return $this->response
                ->withType('application/json')
                ->withStatus(401)
                ->withStringBody(json_encode(['error' => 'ログインが必要です。'], JSON_UNESCAPED_UNICODE));
        }

        $userLevel = (int)$user->get('i_user_level');
        $userId = $user->get('i_id_user');
        $isAdmin = (int)$user->get('i_admin') === 1;

        // 管理者以外の職員のみ許可
        if ($userLevel !== 0 || $isAdmin) {
            return $this->response
                ->withType('application/json')
                ->withStatus(403)
                ->withStringBody(json_encode(['error' => '権限がありません。管理者以外の職員のみアクセス可能です。'], JSON_UNESCAPED_UNICODE));
        }

        // ユーザーの所属部屋を全て取得（複数部屋対応）
        $userGroups = $this->MUserGroup->find()
            ->select(['i_id_room'])
            ->where(['i_id_user' => $userId])
            ->toArray();
        
        $userRoomIds = [];
        foreach ($userGroups as $group) {
            if ($group->i_id_room) {
                $userRoomIds[] = $group->i_id_room;
            }
        }

        // 部屋IDが指定されている場合は、所属部屋に含まれているかチェック
        if ($roomId) {
            if (!in_array($roomId, $userRoomIds)) {
                return $this->response
                    ->withType('application/json')
                    ->withStatus(403)
                    ->withStringBody(json_encode(['error' => '指定された部屋への権限がありません。'], JSON_UNESCAPED_UNICODE));
            }
            $targetRoomIds = [$roomId];
        } else {
            // 部屋IDが指定されていない場合は、所属する全部屋
            $targetRoomIds = $userRoomIds;
        }

        if (empty($targetRoomIds)) {
            return $this->response
                ->withType('application/json')
                ->withStatus(400)
                ->withStringBody(json_encode(['error' => '所属部屋が見つかりません。'], JSON_UNESCAPED_UNICODE));
        }

        // 日付範囲を取得
        $fromDate = $this->request->getQuery('from');
        $toDate = $this->request->getQuery('to');
        
        if (!$fromDate || !$toDate) {
            // デフォルトで現在の月の前後1ヶ月のデータを取得
            $fromDate = date('Y-m-01', strtotime('-1 month'));
            $toDate = date('Y-m-t', strtotime('+1 month'));
        }

        try {
            // 部屋IDリストに基づく食数集計を取得
            $query = $this->TIndividualReservationInfo->find()
                ->select([
                    'date' => 'd_reservation_date',
                    'meal_type' => 'i_reservation_type',
                    'count' => $this->TIndividualReservationInfo->find()->func()->count('*')
                ])
                ->where([
                    'i_id_room IN' => $targetRoomIds,
                    'd_reservation_date >=' => $fromDate,
                    'd_reservation_date <=' => $toDate,
                    'eat_flag' => 1 // 有効な予約のみ
                ]);

            $mealCounts = $query
                ->groupBy(['d_reservation_date', 'i_reservation_type'])
                ->orderBy(['d_reservation_date' => 'ASC', 'i_reservation_type' => 'ASC'])
                ->toArray();

            // 日付別にデータを整理
            $result = [];
            foreach ($mealCounts as $row) {
                $date = $row->date->format('Y-m-d');
                $mealType = (int)$row->meal_type;
                $count = (int)$row->count;

                if (!isset($result[$date])) {
                    $result[$date] = [
                        'date' => $date,
                        'morning' => 0,    // 朝食
                        'lunch' => 0,      // 昼食
                        'dinner' => 0,     // 夕食
                        'bento' => 0,      // 弁当
                        'total' => 0
                    ];
                }

                switch ($mealType) {
                    case 1:
                        $result[$date]['morning'] = $count;
                        break;
                    case 2:
                        $result[$date]['lunch'] = $count;
                        break;
                    case 3:
                        $result[$date]['dinner'] = $count;
                        break;
                    case 4:
                        $result[$date]['bento'] = $count;
                        break;
                }

                $result[$date]['total'] += $count;
            }

            return $this->response
                ->withType('application/json')
                ->withStringBody(json_encode(array_values($result), JSON_UNESCAPED_UNICODE));

        } catch (\Exception $e) {
            return $this->response
                ->withType('application/json')
                ->withStatus(500)
                ->withStringBody(json_encode(['error' => 'データ取得に失敗しました。'], JSON_UNESCAPED_UNICODE));
        }
    }
    /* =============================================================== */
    /*  以下はこのコントローラ内に配置する簡易レスポンスヘルパーメソッド  */
    /* =============================================================== */

    /**
     * 指定配列を JSON で返却
     *
     * @param array $body レスポンスボディ
     * @return \Cake\Http\Response
     */
    private function respondJson(array $body)
    {
        $this->response = $this->response
            ->withType('application/json')
            ->withStringBody(json_encode($body, JSON_UNESCAPED_UNICODE));

        return $this->response;
    }
}
