<?php
declare(strict_types=1);

namespace App\Controller;

use Cake\Http\Exception\NotFoundException;
use Cake\I18n\FrozenDate;
use Cake\I18n\FrozenTime;
use Cake\Log\Log;

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

    /**
     * initialize メソッド
     *
     * コントローラーの初期化処理を行います。
     * 必要なモデルをロードし、コンポーネントを設定します。
     */
    public function initialize(): void
    {
        parent::initialize();
        $this->fetchTable('TReservationInfo');
        $this->MRoomInfo = $this->fetchTable('MRoomInfo');
        $this->MUserInfo =  $this->fetchTable('MUserInfo');
        $this->MUserGroup =  $this->fetchTable('MUserGroup');
        $this->TIndividualReservationInfo = $this->fetchTable('TIndividualReservationInfo');
        $this->loadComponent('Flash');
        $this->viewBuilder()->setOption('serialize', true);
        $this->viewBuilder()->setLayout('default');
    }

    /**
     * 予約日のバリデーションを行う
     *
     * @param string|null $reservationDate 検証する予約日
     * @return string|bool 予約可能な場合はtrue、不可の場合はエラーメッセージ
     */
    private function validateReservationDate($reservationDate)
    {
        // 予約日が null の場合、エラーを返す
        if (empty($reservationDate)) {
            return '予約日が指定されていません。';
        }

        try {
            // 予約日を DateTime オブジェクトに変換
            $reservationDateObj = new FrozenDate($reservationDate);
        } catch (\Exception $e) {
            return '無効な日付フォーマットです。';
        }

        // 今日の日付を取得
        $currentDate = FrozenTime::now();
        // 当日から1ヶ月後の日付を計算（※modify('+1 month')は元のオブジェクトを変更するので注意）
        $oneMonthLater = (new FrozenTime($currentDate))->modify('+14 days')->format('Y-m-d');
        $oneMonthLaterDate = new FrozenDate($oneMonthLater);

        // 予約日は「当日から１ヶ月後」以降でなければならない
        if ($reservationDateObj < $oneMonthLaterDate) {
            return '当日から2週間後までは予約の登録ができません。';
        }

        return true; // 予約可能
    }




    /**
     * インデックスメソッド
     *
     * @return \Cake\Http\Response|null|void ビューをレンダリングする
     */
    public function index()
    {
        //i_adminの情報取得のため。消してはダメ。
        $authUser = $this->Authentication->getIdentity();
        $userId = $authUser->get('i_id_user');
        $user = $this->MUserInfo->get($userId);

        // TReservationInfoテーブルから予約データを取得
        $reservations = $this->TIndividualReservationInfo->find()
            ->select([
                'd_reservation_date',
                'i_reservation_type',
                'eat_flag',
                'total_eaters'=> $this->TIndividualReservationInfo->find()->func()->count("*")
            ])
            ->where(['eat_flag' => 1])
            ->groupBy(['d_reservation_date', 'i_reservation_type'])
            ->toArray();

        // 日付ごとの食事タイプ（朝、昼、夜）の総数を保持するための連想配列を初期化
        $mealDataArray = [];

        foreach ($reservations as $reservation) {
            $date = $reservation->d_reservation_date->format('Y-m-d');
            $mealType = $reservation->i_reservation_type; // 食事タイプ: 1 (朝), 2 (昼), 3 (夜)
            $totalEaters = $reservation->total_eaters;

            // 指定された日付のエントリが存在しない場合、初期化
            if (!isset($mealDataArray[$date])) {
                $mealDataArray[$date] = [1 => 0, 2 => 0, 3 => 0, 4 => 0]; // 朝、昼、夜のカウントを0で初期化
            }

            // 特定の日付と食事タイプに対して食事の人数を加算
            $mealDataArray[$date][$mealType] += (int)$reservation->total_eaters;
        }

        // ─────────────────────────────────────────────
        // 追加処理：自分が予約している日付一覧を取得
        // ─────────────────────────────────────────────
        $myReservationDates = $this->TIndividualReservationInfo
            ->find()
            ->select(['d_reservation_date'])
            ->where([
                'i_id_user' => $userId,
                'eat_flag'  => 1,
            ])
            ->distinct()
            ->orderBy(['d_reservation_date' => 'ASC'])
            ->all()                                   // ResultSet へ変換
            ->extract('d_reservation_date')           // Collection メソッド
            ->map(fn($d) => $d->format('Y-m-d'))      // FrozenDate → 文字列
            ->toArray();

        // 計算した食事データをビューにセット
        $this->set(compact('mealDataArray', 'user', 'myReservationDates'));
    }
    /**
     * イベントメソッド - FullCalendarで使用するイベントデータを提供する
     * @return \Cake\Http\Response|null|void JSONレスポンスを返す
     */
    public function events()
    {
        // GETおよびAJAXメソッドのみを許可
        $this->request->allowMethod(['get', 'ajax']);

        // 全ての予約データを取得
        $reservations = $this->TIndividualReservationInfo->find('all');

        // 予約データをFullCalendarで使用する形式に変換
        $events = [];
        foreach ($reservations as $reservation) {
            $events[] = [
                'title' => '合計食数: ' . $reservation->sum(''),
                'start' => $reservation->d_reservation_date->format('Y-m-d'),
                'allDay' => true
            ];
        }

        // イベントデータをビューにセット
        $this->set(compact('events'));
        $this->viewBuilder()->setOption('serialize', 'events');
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
        $user        = $this->request->getAttribute('identity');
        $userRoomId  = null;           // ユーザー所属部屋 ID
        $isAdmin     = false;          // 管理者フラグ

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
                            'i_id_user'   => $userId,
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

        // 日付が指定されていない場合は例外をスロー
        if ($date === null) {
            throw new \InvalidArgumentException('日付が指定されていません。');
        }

        /* ───────────────────────────────────────
         * ③ 部屋一覧を取得
         * ─────────────────────────────────────── */
        $rooms = $this->MRoomInfo->find('list', [
            'keyField'   => 'i_id_room',
            'valueField' => 'c_room_name'
        ])->toArray();

        /* ───────────────────────────────────────
         * ④ 食事区分ごとの予約集計
         * ─────────────────────────────────────── */
        $mealTypes      = [1 => '朝', 2 => '昼', 3 => '夜', 4 => '弁当'];
        $mealDataArray  = [];

        foreach ($mealTypes as $mealType => $mealLabel) {

            // 食べる・食べない情報を取得
            $reservations = $this->TIndividualReservationInfo->find()
                ->select([
                    'room_id'        => 'TIndividualReservationInfo.i_id_room',
                    'i_id_user'      => 'TIndividualReservationInfo.i_id_user',
                    'eat_flag',
                    'taberu_ninzuu'  => $this->TIndividualReservationInfo
                        ->find()
                        ->func()
                        ->count('TIndividualReservationInfo.i_id_user')
                ])
                ->where([
                    'd_reservation_date' => $date,
                    'i_reservation_type' => $mealType
                ])
                ->groupBy([
                    'TIndividualReservationInfo.i_id_room',
                    'TIndividualReservationInfo.eat_flag',
                    'TIndividualReservationInfo.i_id_user'
                ])
                ->toArray();

            $mealDataArray[$mealLabel] = [];

            /* 部屋ごとに食べる／食べないユーザー ID を振り分けるためのマップ */
            $roomUserEatMap     = [];
            $roomUserNotEatMap  = [];

            foreach ($reservations as $reservation) {
                $roomId  = $reservation->room_id;
                $eatFlag = $reservation->eat_flag;
                $userId  = $reservation->i_id_user;

                // 部屋リストに存在しない部屋はスキップ
                if (!isset($rooms[$roomId])) {
                    continue;
                }

                // 部屋データ初期化
                if (!isset($mealDataArray[$mealLabel][$roomId])) {
                    $mealDataArray[$mealLabel][$roomId] = [
                        'room_name'      => $rooms[$roomId],
                        'taberu_ninzuu'  => 0,
                        'tabenai_ninzuu' => 0,
                        'room_id'        => $roomId,
                    ];
                }

                $roomUserEatMap[$roomId]     = $roomUserEatMap[$roomId]     ?? [];
                $roomUserNotEatMap[$roomId]  = $roomUserNotEatMap[$roomId]  ?? [];

                // eat_flag で振り分け
                if ($eatFlag == 1) {
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
                    ->enableAutoFields(false) // 自動フィールドを無効化
                    ->matching('MUserInfo', function ($q) use ($date) {
                        return $q->select([
                            'MUserInfo.i_id_user',
                            'MUserInfo.i_del_flag',
                            'MUserInfo.dt_create'
                        ])
                            ->enableAutoFields(false) // 関連側も無効化
                            ->where([
                                'MUserInfo.i_del_flag' => 0
                            ])
                            ->andWhere(function ($exp) use ($date) {
                                return $exp->lte('MUserInfo.dt_create', $date);
                            });
                    })
                    ->where([
                        'MUserGroup.i_id_room'   => $roomId,
                        'MUserGroup.active_flag' => 0
                    ])
                    ->all();

                $tabenaiCount = 0;
                foreach ($usersInRoom as $userGroup) {
                    // matching() で取得した関連データは _matchingData に入る
                    /** @var \Cake\Datasource\EntityInterface|null $userInfo */
                    $userInfo = $userGroup->_matchingData['MUserInfo'] ?? null;
                    if ($userInfo === null) {
                        continue; // 関連が無ければスキップ
                    }

                    $userId = $userInfo->i_id_user;

                    $haveEat = isset($roomUserEatMap[$roomId][$userId]);

                    /* eat_flag=1 のレコードが無ければ「食べない」とみなす */
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
        $this->set(compact('mealDataArray', 'date', 'userRoomId', 'isAdmin'));
    }
    /**
     * 部屋詳細メソッド
     *
     * @param int $roomId 部屋ID
     * @param string $date 日付
     * @param int $mealType 食事タイプ
     * @return \Cake\Http\Response|null|void ビューをレンダリングする
     * 食べる人と食べない人のリストを表示する→データベースに登録されてない場合は食べない人として表示される
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

        // 部屋名を取得
        $room = $this->MRoomInfo->find()
            ->select(['c_room_name'])
            ->where(['i_id_room' => $roomId])
            ->first();

        // 部屋が見つからない場合
        if (!$room) {
            throw new NotFoundException(__('部屋が見つかりません。'));
        }

        // 食べる人を取得
        $eaters = $this->TIndividualReservationInfo->find()
            ->select(['TIndividualReservationInfo.i_id_user', 'MUserInfo.c_user_name'])
            ->contain(['MUserInfo', 'MUserGroup'])
            ->where([
                'TIndividualReservationInfo.i_id_room' => $roomId,
                'TIndividualReservationInfo.d_reservation_date' => $date,
                'TIndividualReservationInfo.i_reservation_type' => $mealType,
                'TIndividualReservationInfo.eat_flag' => 1, // 食べる人
                'MUserInfo.i_del_flag' => 0,
                'MUserGroup.active_flag' => 0
            ])
            ->all();

        // 食べない人（データベース登録済み）を取得
        $nonEaters = $this->TIndividualReservationInfo->find()
            ->select(['TIndividualReservationInfo.i_id_user', 'MUserInfo.c_user_name'])
            ->contain(['MUserInfo', 'MUserGroup'])
            ->where([
                'TIndividualReservationInfo.i_id_room' => $roomId,
                'TIndividualReservationInfo.d_reservation_date' => $date,
                'TIndividualReservationInfo.i_reservation_type' => $mealType,
                'TIndividualReservationInfo.eat_flag' => 0, // 食べない人
                'MUserInfo.i_del_flag' => 0,
                'MUserGroup.active_flag' => 0
            ])
            ->all();

        // 全ユーザーを取得
        $allUsers = $this->MUserGroup->find()
            ->select(['MUserInfo.i_id_user', 'MUserInfo.c_user_name', 'MUserInfo.dt_create'])
            ->contain(['MUserInfo'])
            ->where([
                'MUserGroup.i_id_room' => $roomId,
                'MUserInfo.i_del_flag' => 0,
                'MUserGroup.active_flag' => 0
            ])
            ->all();


        // ↓↓↓↓↓↓↓↓↓↓↓↓↓↓↓↓↓↓↓↓↓ ここから追記 ↓↓↓↓↓↓↓↓↓↓↓↓↓↓↓↓↓↓↓↓↓
        // --- 予約未登録でMUserGroupにactive_flag=0で存在する人も「食べない人」に表示するための加工 ---

        // 全ユーザーIDと名前
        $allUserIds = [];
        $allUserNames = [];

        foreach ($allUsers as $user) {
            $userInfo = $user->m_user_info ?? null;
            if ($userInfo) {
                $allUserIds[] = $userInfo->i_id_user;
                $allUserNames[$userInfo->i_id_user] = $userInfo->c_user_name;
            }
        }
        // 食べる人のID一覧
        $eatUserIds = [];
        foreach ($eaters as $eater) {
            $eatUserIds[] = $eater->i_id_user;
        }

        // 食べない人のID一覧
        $noEatUserIds = [];
        foreach ($nonEaters as $nonEater) {
            $noEatUserIds[] = $nonEater->i_id_user;
        }
        // 未登録(食数テーブルに存在しない)でMUserGroup.active_flag=0のユーザーID
        $notRegisteredUserIds = array_diff($allUserIds, array_merge($eatUserIds, $noEatUserIds));
        // ↓↓↓↓↓↓↓↓↓↓↓↓↓↓↓↓↓↓↓↓↓ ここまで追記 ↓↓↓↓↓↓↓↓↓↓↓↓↓↓↓↓↓↓↓↓↓

        // 予約テーブルに存在するユーザーID一覧を取得
        $reservedUserIds = [];
        foreach ($this->TIndividualReservationInfo->find()
                     ->select(['i_id_user'])
                     ->where([
                         'i_id_room' => $roomId,
                         'd_reservation_date' => $date,
                         'i_reservation_type' => $mealType,
                     ])
                     ->distinct(['i_id_user'])
                     ->all() as $row) {
            $reservedUserIds[] = $row->i_id_user;
        }

        // 食べる人の名前リスト
        $eatUsers = [];
        foreach ($eaters as $eater) {
            if ($eater->has('m_user_info')) {
                $eatUsers[] = $eater->m_user_info->c_user_name;
            }
        }

        // 食べない人の名前リスト（eat_flag==0の登録済みのみ）
        $noEatUsers = [];
        foreach ($nonEaters as $nonEater) {
            if ($nonEater->has('m_user_info')) {
                $userInfo = $nonEater->m_user_info;
                if (empty($userInfo->dt_create) || $userInfo->dt_create <= $date) {
                    $noEatUsers[] = $userInfo->c_user_name;
                }
            }
        }

        // --- ここから修正部分 ---
        // 未登録ユーザーも「食べない人」として追加

        foreach ($notRegisteredUserIds as $userId) {
            if (isset($allUserNames[$userId]) && !in_array($allUserNames[$userId], $noEatUsers, true)) {
                $noEatUsers[] = $allUserNames[$userId];
            }
        }
        // --- ここまで修正部分 ---

        // 他の部屋で食べないとして登録されているユーザーの部屋名を取得
        $otherRoomEaters = [];
        foreach ($eaters as $eater) {
            if ($eater->has('m_user_info') && $eater->i_id_room !== null && $eater->i_id_room != $roomId) {
                $otherRoomRoom = $this->MRoomInfo->find()
                    ->select(['c_room_name'])
                    ->where(['i_id_room' => $eater->i_id_room])
                    ->first();

                $roomName = $otherRoomRoom ? $otherRoomRoom->c_room_name : '不明な部屋';

                $otherRoomEaters[] = [
                    'user_name' => $eater->m_user_info->c_user_name,
                    'room_name' => $roomName
                ];
            }
        }

        // ビューにデータをセット
        $this->set(compact('room', 'date', 'mealType', 'eatUsers', 'noEatUsers', 'otherRoomEaters'));
    }



    /**
     * 所属しているユーザーを取得するメソッド
     * @param int $roomId
     * @param string $users
     *
     */

    public function getUsersByRoom($roomId = null)
    {
        $this->request->allowMethod(['get', 'ajax']); // AJAXリクエストのみ許可

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

        // デバッグログ: リクエストデータを確認
        Log::debug('Request Data: ' . json_encode($data));

        // 必須フィールドの検証
        if (empty($data['d_reservation_date']) || empty($data['i_id_room']) || empty($data['reservation_type'])) {
            Log::debug('必須フィールド不足');
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

        // デバッグログ: 既存の予約を確認
        Log::debug('Existing Reservation: ' . json_encode($existingReservation));

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

            // デバッグログ: 生成されたURLを確認
            Log::debug('Edit URL: ' . $editUrl);

            return $this->response->withType('application/json')
                ->withStringBody(json_encode([
                    'isDuplicate' => true,
                    'editUrl' => $editUrl,
                ]));
        }

        // デバッグログ: 重複予約なし
        Log::debug('No duplicate reservation found');

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
        $user = $this->request->getAttribute('identity');
        if (!$user) {
            return $this->jsonErrorResponse(__('ログイン情報がありません。'));
        }
        $userLevel = $user->i_user_level;

        $userId = $user->get('i_id_user');
        $rooms = $this->getAuthorizedRooms($userId);

        // クエリパラメータから日付を取得（nullの場合は今日の日付を設定）
        $date = $this->request->getQuery('date') ?? date('Y-m-d');

        // バリデーションチェック
        $dateValidation = $this->validateReservationDate($date);
        if ($dateValidation !== true) {
            $this->Flash->error(__($dateValidation));
            return $this->redirect(['action' => 'index']);
        }

        $roomId = $this->MRoomInfo->find('list', [
            'keyField' => 'i_id_room',
            'valueField' => 'c_room_name'
        ])->toArray();

        if (!$roomId) {
            $this->Flash->error(__('部屋が見つかりません。'));
            return $this->redirect(['action' => 'index']);
        }

        $tReservationInfo = $this->TReservationInfo->newEmptyEntity();

        if ($this->request->is('post')) {
            $data = $this->request->getData();
            $reservationType = $data['reservation_type'] ?? '1';

            if ($reservationType == 1) {
                return $this->processIndividualReservation($data['d_reservation_date'], $data, $rooms);
            } else {
                return $this->processGroupReservation($data['d_reservation_date'], $data, $rooms);
            }
        }

        $this->set(compact('rooms', 'tReservationInfo', 'date', 'roomId', 'userLevel'));
    }

    /**
     * 個人予約の処理 - ユーザーの個人予約データを処理する
     *
     * @param string $reservationDate 予約日
     * @param array|string $jsonData 予約データ（JSON文字列または連想配列）
     * @param array $rooms 予約可能な部屋の連想配列
     * @return \Cake\Http\Response JSONレスポンスを返す
     */
    /**
     * 個人予約登録／更新
     *
     * ① JSON の検証
     * ② 日付の検証
     * ③ 各食事区分につき 1 部屋しか登録できないようチェック
     * ④ 既存予約がある場合は更新、無ければ新規作成
     *
     * @param string                          $reservationDate 予約日 (Y-m-d)
     * @param string|array<string, mixed>     $jsonData        送信された JSON
     * @param array<int, string>              $rooms           ログインユーザーが操作可能な部屋一覧
     * @return \Cake\Http\Response
     */
    private function processIndividualReservation($reservationDate, $jsonData, $rooms)
    {
        /* ─────────────────────────────────────
         * ① JSON の検証
         * ───────────────────────────────────── */
        if (empty($jsonData) || (!is_string($jsonData) && !is_array($jsonData))) {
            Log::error('入力データが無効です。空文字列または期待しない形式です。');
            return $this->jsonErrorResponse(__('入力データが無効です。'));
        }

        $data = is_string($jsonData) ? json_decode($jsonData, true) : $jsonData;
        if (is_null($data) || json_last_error() !== JSON_ERROR_NONE) {
            Log::error('JSONデコードエラー: ' . json_last_error_msg());
            return $this->jsonErrorResponse(__('JSONデータの形式が不正です: ') . json_last_error_msg());
        }

        Log::debug("processIndividualReservation called by User: {$this->request->getAttribute('identity')->get('i_id_user')}, Date: {$reservationDate}");
        Log::debug('デコード後のデータ: ' . json_encode($data, JSON_UNESCAPED_UNICODE));

        /* ─────────────────────────────────────
         * ② 日付の検証
         * ───────────────────────────────────── */
        $dateValidation = $this->validateReservationDate($reservationDate);
        if ($dateValidation !== true) {
            Log::error('日付検証エラー: ' . $dateValidation);
            return $this->jsonErrorResponse(__($dateValidation));
        }

        /* ─────────────────────────────────────
         * データ構造の検証
         * ───────────────────────────────────── */
        if (!isset($data['meals']) || !is_array($data['meals'])) {
            Log::error('データ構造が不正: "meals" キーが存在しない、または配列ではありません。データ: ' . json_encode($data));
            return $this->jsonErrorResponse(__('データ構造が不正です。'));
        }

        /* ─────────────────────────────────────
         * 初期化
         * ───────────────────────────────────── */
        $reservationsToSave   = [];
        $operationPerformed   = false;                 // 更新が行われたかどうか
        $selectedRoomPerMeal  = [];                    // 食事区分ごとに選択された部屋
        $userId               = $this->request->getAttribute('identity')->get('i_id_user');
        $userName             = $this->request->getAttribute('identity')->get('c_user_name');

        /* ─────────────────────────────────────
         * ③ 食事区分ごとに 1 部屋制限のチェック &
         *    既存予約の更新／新規作成
         * ───────────────────────────────────── */
        foreach ($data['meals'] as $mealType => $selectedRooms) {
            foreach ($selectedRooms as $roomId => $value) {
                if ($value != 1) {
                    continue; // チェックが付いていない
                }

                Log::debug("Processing reservation for Meal Type: {$mealType}, Room ID: {$roomId}");

                /* 3-1. 同一食事区分に対して複数部屋が選択されていないか */
                if (isset($selectedRoomPerMeal[$mealType]) && $selectedRoomPerMeal[$mealType] !== $roomId) {
                    Log::error("同一食事区分で複数部屋が選択されました。MealType={$mealType}");
                    return $this->jsonErrorResponse(
                        __('同じ食事区分に対して複数の部屋を選択することはできません。')
                    );
                }
                $selectedRoomPerMeal[$mealType] = $roomId;

                /* 3-2. 権限チェック */
                if (!array_key_exists($roomId, $rooms)) {
                    Log::error('権限のない部屋が指定されました。Room ID: ' . $roomId);
                    return $this->jsonErrorResponse(__('選択された部屋は権限がありません。'));
                }

                /* 3-3. 既存予約の確認 */
                $existingReservation = $this->TIndividualReservationInfo->find()
                    ->where([
                        'i_id_user'          => $userId,
                        'd_reservation_date' => $reservationDate,
                        'i_reservation_type' => $mealType,
                    ])
                    ->first();

                if ($existingReservation) {
                    // eat_flag = 0 のときだけ更新可
                    if ($existingReservation->eat_flag == 0) {
                        $updateFields = [
                            'i_id_room'     => $roomId,
                            'eat_flag'      => 1,
                            'c_create_user' => $userName,
                            'dt_create'     => FrozenTime::now(),
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
                    Log::error('同じ日付と食事タイプの予約が既に存在します。');
                    return $this->jsonErrorResponse(__('同じ日付と食事タイプの予約が既に存在します。'));
                }

                /* 3-4. 新規予約エンティティ作成 */
                $newReservation = $this->TIndividualReservationInfo->patchEntity(
                    $this->TIndividualReservationInfo->newEmptyEntity(),
                    [
                        'i_id_user'          => $userId,
                        'd_reservation_date' => $reservationDate,
                        'i_id_room'          => $roomId,
                        'i_reservation_type' => $mealType,
                        'eat_flag'           => 1,
                        'c_create_user'      => $userName,
                        'dt_create'          => FrozenTime::now(),
                    ]
                );
                $reservationsToSave[] = $newReservation;
            }
        }

        /* ─────────────────────────────────────
         * ④ 保存処理
         * ───────────────────────────────────── */
        // 新規予約がある場合
        if (!empty($reservationsToSave)) {
            if ($this->TIndividualReservationInfo->saveMany($reservationsToSave)) {
                return $this->jsonSuccessResponse(
                    __('個人予約が正常に登録されました。'),
                    [],
                    $this->request->getAttribute('webroot') . 'TReservationInfo/'
                );
            }
            return $this->jsonErrorResponse(__('システムエラーが発生しました。'));
        }

        // 更新のみ行われた場合
        if ($operationPerformed) {
            return $this->jsonSuccessResponse(
                __('個人予約が正常に登録されました。'),
                [],
                $this->request->getAttribute('webroot') . 'TReservationInfo/'
            );
        }

        /* ─────────────────────────────────────
         * ここまでに何も保存されていなければエラー
         * ───────────────────────────────────── */
        return $this->jsonErrorResponse(__('システムエラーが発生しました。'));
    }



    /**
     * グループ予約の処理 - 複数ユーザーの予約データを一括で処理する
     *
     * @param string $reservationDate 予約日
     * @param array|string $jsonData 予約データ（JSON文字列または連想配列）
     * @param array $rooms 予約可能な部屋の連想配列
     * @return \Cake\Http\Response JSONレスポンスを返す
     */
    private function processGroupReservation($reservationDate, $jsonData, $rooms)
    {
        // JSON デコードと入力検証
        if (empty($jsonData) || (!is_string($jsonData) && !is_array($jsonData))) {
            Log::error('入力データが無効です。空文字列または想定しない形式です。');
            return $this->jsonErrorResponse(__('入力データが無効です。'));
        }

        $data = is_string($jsonData) ? json_decode($jsonData, true) : $jsonData;
        if (is_null($data) || json_last_error() !== JSON_ERROR_NONE) {
            Log::error('JSON デコードエラー: ' . json_last_error_msg());
            return $this->jsonErrorResponse(__('JSON データの形式が不正です: ') . json_last_error_msg());
        }

        // 日付検証
        $dateValidation = $this->validateReservationDate($reservationDate);
        if ($dateValidation !== true) {
            Log::error('日付検証エラー: ' . $dateValidation);
            return $this->jsonErrorResponse(__($dateValidation));
        }

        if (!isset($data['users']) || !is_array($data['users'])) {
            Log::error('データ構造が不正: "users" キーが存在しない、または配列ではありません。データ: ' . json_encode($data));
            return $this->jsonErrorResponse(__('データ構造が不正です。'));
        }

        // Identity の存在チェック
        $identity = $this->request->getAttribute('identity');
        if (!$identity) {
            Log::error('Identity が設定されていません。');
            return $this->jsonErrorResponse(__('認証情報が不足しています。'));
        }
        $creatorName = $identity->get('c_user_name') ?? '不明なユーザー';

        $reservationsToSave = [];
        $duplicates = [];  // 重複しているユーザー情報を格納

        // 食事種別を日本語に変換するマッピング
        $mealTypeNames = [
            '1' => __('朝食'),
            '2' => __('昼食'),
            '3' => __('夕食'),
            '4' => __('間食')
        ];

        foreach ($data['users'] as $userId => $meals) {
            foreach ($meals as $mealType => $selected) {
                if (!$selected) {
                    continue;
                }

                $roomId = $data['i_id_room'] ?? null;
                if (!isset($rooms[$roomId])) {
                    continue;
                }

                // 重複チェック
                $existingReservation = $this->TIndividualReservationInfo->find()
                    ->contain(['MUserInfo', 'MRoomInfo'])
                    ->where([
                        'TIndividualReservationInfo.i_id_user' => $userId,
                        'TIndividualReservationInfo.d_reservation_date' => $reservationDate,
                        'TIndividualReservationInfo.i_reservation_type' => $mealType,
                    ])
                    ->first();

                if ($existingReservation) {
                    if (empty($existingReservation->MUserInfo)) {
                        $userInfo = $this->MUserInfo->find()
                            ->where(['i_id_user' => $userId])
                            ->first();
                        $reservedUserName = $userInfo ? $userInfo->c_user_name : '不明なユーザー名';
                    } else {
                        $reservedUserName = $existingReservation->MUserInfo->c_user_name ?? '不明なユーザー名';
                    }

                    if (empty($existingReservation->MRoomInfo)) {
                        $roomInfo = $this->MRoomInfo->find()
                            ->where(['i_id_room' => $data['i_id_room']])
                            ->first();
                        $reservedRoomName = $roomInfo ? $roomInfo->c_room_name : '不明な部屋名';
                    } else {
                        $reservedRoomName = $existingReservation->MRoomInfo->c_room_name ?? '不明な部屋名';
                    }

                    $duplicates[] = [
                        'user_name' => $reservedUserName,
                        'meal_type' => $mealTypeNames[$mealType] ?? $mealType,
                        'room_name' => $reservedRoomName
                    ];
                    continue;
                }

                // 新規予約エンティティを作成
                $newReservation = $this->TIndividualReservationInfo->newEmptyEntity();
                $newReservation = $this->TIndividualReservationInfo->patchEntity($newReservation, [
                    'i_id_user'          => $userId,
                    'd_reservation_date' => $reservationDate,
                    'i_id_room'          => $roomId,
                    'i_reservation_type' => $mealType,
                    'eat_flag'           => 1,
                    'c_create_user'      => $creatorName,
                    'dt_create'          => FrozenTime::now(),
                ]);
                $reservationsToSave[] = $newReservation;
            }
        }

        // 予約登録処理
        if (!empty($reservationsToSave)) {
            if (!$this->TIndividualReservationInfo->saveMany($reservationsToSave)) {
                return $this->jsonErrorResponse(__('予約の登録中にエラーが発生しました。'));
            }
        }

        // 重複がある場合は警告付きで成功レスポンスを返す
        if (!empty($duplicates)) {
            return $this->jsonSuccessResponse(
                __('一部の予約はすでに存在していたためスキップされました。'),
                ['skipped' => $duplicates],
                $this->request->getAttribute('webroot') . 'TReservationInfo/'
            );
        }

        return $this->jsonSuccessResponse(__('予約が正常に登録されました。'), [],
            $this->request->getAttribute('webroot') . 'TReservationInfo/');
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
    protected function jsonErrorResponse(string $message, array $data = [])
    {
        return $this->response
            ->withType('application/json')
            ->withStringBody(json_encode(['status' => 'error', 'message' => $message, 'data' => $data], JSON_PRETTY_PRINT));
    }

    /**
     * 成功レスポンスをJSON形式で返す
     *
     * @param string $message 成功メッセージ
     * @param array $data 追加データ
     * @param string|null $redirect リダイレクト先URL
     * @return \Cake\Http\Response JSONレスポンス
     */
    protected function jsonSuccessResponse(string $message, array $data = [], string $redirect = null)
    {
        $responseData = [
            'status' => 'success',
            'message' => $message,
            'data' => $data,
        ];

        if ($redirect) {
            $responseData['redirect'] = $redirect;
        }

        return $this->response
            ->withType('application/json')
            ->withStringBody(json_encode($responseData, JSON_PRETTY_PRINT));
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
     * 編集メソッド
     *
     * @param string|null $id T Reservation Info id.
     * @return \Cake\Http\Response|null|void 成功時にはリダイレクト、ビューをレンダリング
     */
    public function edit($roomId = null, $date = null, $mealType = null)
    {
        if (!$roomId || !$date || !$mealType) {
            throw new \InvalidArgumentException('部屋ID、日付、または食事タイプが指定されていません。');
        }

        // 部屋情報を取得
        $room = $this->MRoomInfo->find()
            ->select(['i_id_room', 'c_room_name'])
            ->where(['i_id_room' => $roomId])
            ->first();

        if (!$room) {
            throw new NotFoundException(__('部屋が見つかりません。'));
        }

        // 利用者情報を取得
        $users = $this->MUserGroup->find()
            ->contain(['MUserInfo'])
            ->where(['MUserGroup.i_id_room' => $roomId, 'MUserGroup.active_flag' => 0, 'MUserInfo.i_del_flag' => 0])
            ->toArray();

        // 全ての予約情報を取得
        $reservations = $this->TIndividualReservationInfo->find()
            ->contain(['MRoomInfo']) // 部屋名取得のために関連付け
            ->where(['d_reservation_date' => $date])
            ->all();

        // 各ユーザーごとに予約情報をマッピング
        $userReservations = [];
        foreach ($reservations as $reservation) {
            $userReservations[$reservation->i_id_user][$reservation->i_reservation_type] = [
                'room_id' => $reservation->i_id_room,
                'eat_flag' => $reservation->eat_flag,
                'room_name' => $reservation->m_room_info->c_room_name ?? '不明な部屋',
            ];
        }

        // POSTまたはPUTリクエストの処理
        if ($this->request->is(['post', 'put'])) {
            $data = $this->request->getData();
            $connection = $this->TIndividualReservationInfo->getConnection();
            $connection->begin();

            try {
                foreach ($data['users'] as $userId => $meals) {
                    foreach ($meals as $type => $value) {

                        // 既存の予約データを取得
                        $reservation = $this->TIndividualReservationInfo->find()
                            ->where([
                                'i_id_user' => $userId,
                                'd_reservation_date' => $date,
                                'i_reservation_type' => $type,
                            ])
                            ->first();

                        // **** 前の部屋IDを収集 ****
                        $originalRoomId = $reservation ? $reservation->i_id_room : null;

                        // 更新処理：データが既に存在している場合
                        if ($reservation) {
                            // eat_flag == 1 で部屋変更制限
                            if ($reservation->eat_flag == 1 && $reservation->i_id_room != $roomId) {
                                $this->log("更新禁止（eat_flag=1) UserID={$userId}", 'warning');
                                continue; // 処理スキップ
                            }

                            // **** 部屋変更処理: 元の部屋と異なる時に処理 ****
                            $this->TIndividualReservationInfo->updateAll(
                                [
                                    'i_id_room' => $roomId,
                                    'eat_flag' => ($value == 1) ? 1 : 0,
                                    'c_update_user' => $this->request->getAttribute('identity')->get('c_user_name'),
                                    'dt_update' => FrozenTime::now(),
                                ],
                                [
                                    'i_id_user' => $userId,
                                    'd_reservation_date' => $date,
                                    'i_id_room' => $originalRoomId,
                                    'i_reservation_type' => $type,
                                ]
                            );

                            $this->log("予約データ更新中: OldRoomId={$originalRoomId}, NewRoomId={$roomId}, UserID={$userId}", 'debug');
                        }else {
                            // 予約データが存在しない場合、新規登録
                            if ($value == 1) {
                                $newReservation = $this->TIndividualReservationInfo->newEmptyEntity();
                                $newReservation = $this->TIndividualReservationInfo->patchEntity($newReservation, [
                                    'i_id_user' => $userId,
                                    'd_reservation_date' => $date,
                                    'i_id_room' => $roomId,
                                    'i_reservation_type' => $mealType,
                                    'eat_flag' => 1,
                                    'c_create_user' => $this->request->getAttribute('identity')->get('c_user_name'),
                                    'dt_create' => FrozenTime::now(),
                                ]);

                                if (!$this->TIndividualReservationInfo->save($newReservation)) {
                                    throw new \Exception("新規予約の保存に失敗しました: UserID={$userId}");
                                }

                                $this->log("新規予約登録: UserID={$userId}, RoomID={$roomId}, MealType={$mealType}", 'debug');
                            }
                        }

                    }
                }

                // トランザクションをコミット
                $connection->commit();
                $this->Flash->success(__('予約情報を更新しました。'));
                return $this->redirect(['action' => 'index']);
            } catch (\Exception $exception) {
                // トランザクションをロールバック
                $connection->rollback();
                $this->log('予約情報の更新中にエラー: ' . $exception->getMessage(), 'error');
                $this->Flash->error(__('予約情報の更新中にエラーが発生しました: ' . $exception->getMessage()));
            }
        }

        // ビューにデータをセット
        $this->set(compact('room', 'users', 'userReservations', 'date', 'mealType'));
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
                'eat_flag' => 1 // 集計対象は eat_flag = 1 のみ
            ])
            ->groupBy('i_reservation_type')
            ->toArray();

        return $mealCounts;
    }


    public function getUsersByRoomForEdit($roomId)
    {
        $date = $this->request->getQuery('date');
        $mealType = $this->request->getQuery('mealType');

        $this->request->allowMethod(['get', 'ajax']);
        $this->autoRender = false;

        // 部屋に所属するユーザーを取得
        $usersByRoom = $this->MUserGroup->find()
            ->select(['i_id_user', 'i_id_room'])
            ->where(['i_id_room' => $roomId])
            ->contain(['MUserInfo']) // ユーザー情報を結合
            ->toArray();

        $completeUserInfo = [];

        foreach ($usersByRoom as $user) {
            // 指定された部屋と日付の既存の予約情報を取得
            $existingReservation = $this->TIndividualReservationInfo->find()
                ->where([
                    'i_id_user' => $user->i_id_user,
                    'i_id_room' => $roomId,
                    'd_reservation_date' => $date,
                    'i_reservation_type' => $mealType,
                ])
                ->first();

            $completeUserInfo[] = [
                'id' => $user->i_id_user,
                'name' => $user->m_user_info->c_user_name,
                'meals' => [
                    'morning' => $existingReservation && $mealType == 1,
                    'noon' => $existingReservation && $mealType == 2,
                    'night' => $existingReservation && $mealType == 3,
                ],
            ];
        }

        return $this->response->withType('application/json')
            ->withStringBody(json_encode(['usersByRoom' => $completeUserInfo]));
    }




    /**
     * 削除メソッド
     *
     * @param string|null $id T Reservation Info id.
     * @return \Cake\Http\Response|null 成功時にはインデックスにリダイレクト
     * @throws \Cake\Datasource\Exception\RecordNotFoundException 記録が見つからない場合
     */
    public function delete($id = null)
    {
        $this->request->allowMethod(['post', 'delete']);
        $tReservationInfo = $this->TReservationInfo->get($id);
        if ($this->TReservationInfo->delete($tReservationInfo)) {
            $this->Flash->success(__('予約情報が削除されました。'));
        } else {
            $this->Flash->error(__('予約情報を削除できませんでした。'));
        }

        return $this->redirect(['action' => 'index']);
    }
    private function saveIndividualReservation($userId, $reservationDate, $roomId, $mealTime, $username): array
    {
        Log::debug("Saving reservation - userId: $userId, reservationDate: $reservationDate, roomId: $roomId, mealTime: $mealTime");

        $reservation = $this->TIndividualReservationInfo->newEntity([
            'i_id_user' => $userId,
            'd_reservation_date' => $reservationDate,
            'i_id_room' => $roomId,
            'i_reservation_type' => $mealTime,
            'eat_flag' => 1,
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
                'TIndividualReservationInfo.eat_flag'              => 1,
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
