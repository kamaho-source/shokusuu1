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
        $oneMonthLater = (new FrozenTime($currentDate))->modify('+1 month');
        $oneMonthLaterDate = new FrozenDate($oneMonthLater);

        // 予約日は「当日から１ヶ月後」以降でなければならない
        if ($reservationDateObj < $oneMonthLaterDate) {
            return '当日から１ヶ月後までは予約の登録ができません。';
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
                $mealDataArray[$date] = [1 => 0, 2 => 0, 3 => 0,4 => 0]; // 朝、昼、夜のカウントを0で初期化
            }

            // 特定の日付と食事タイプに対して食事の人数を加算
            $mealDataArray[$date][$mealType] += (int)$reservation->total_eaters;
        }

        // 計算した食事データをビューにセット
        $this->set(compact('mealDataArray','user'));
    }

    /**
     * イベントメソッド
     *
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
     */
    public function view()
    {
        // クエリパラメータから日付を取得
        $date = $this->request->getQuery('date');

        // 日付が指定されていない場合は例外をスロー
        if ($date === null) {
            throw new \InvalidArgumentException('日付が指定されていません。');
        }

        // 部屋情報を取得
        $rooms = $this->MRoomInfo->find('list', ['keyField' => 'i_id_room', 'valueField' => 'c_room_name'])->toArray();

        // 食事タイプ（朝、昼、夜）の集計データ
        $mealTypes = [1 => '朝', 2 => '昼', 3 => '夜', 4 => '弁当'];
        $mealDataArray = [];

        foreach ($mealTypes as $mealType => $mealLabel) {
            // 食べる人数を集計
            $reservations = $this->TIndividualReservationInfo->find()
                ->select([
                    'room_id' => 'TIndividualReservationInfo.i_id_room', // 部屋IDを取得
                    'eat_flag',
                    'taberu_ninzuu' => $this->TIndividualReservationInfo->find()->func()->count('TIndividualReservationInfo.i_id_user') // 食べる人数を集計
                ])
                ->where([
                    'd_reservation_date' => $date,
                    'i_reservation_type' => $mealType
                ])
                ->group(['TIndividualReservationInfo.i_id_room', 'TIndividualReservationInfo.eat_flag'])
                ->toArray();

            $mealDataArray[$mealLabel] = [];

            // 各部屋の食べる人数と食べない人数を集計
            foreach ($reservations as $reservation) {
                $roomId = $reservation->room_id;
                $eatFlag = $reservation->eat_flag;
                $count = $reservation->taberu_ninzuu;

                if (!isset($mealDataArray[$mealLabel][$roomId])) {
                    $mealDataArray[$mealLabel][$roomId] = [
                        'room_name' => $rooms[$roomId] ?? '不明な部屋',
                        'taberu_ninzuu' => 0,
                        'tabenai_ninzuu' => 0,
                        'room_id' => $roomId,
                    ];
                }

                if ($eatFlag == 1) {
                    // 食べる人数を加算
                    $mealDataArray[$mealLabel][$roomId]['taberu_ninzuu'] += $count;
                }
            }

            // 食べない人数は部屋の所属人数から食べる人数を引いたもの
            foreach ($mealDataArray[$mealLabel] as &$roomData) {
                // 部屋に所属する有効なユーザー数を取得（active_flag=1、i_del_flag=0）
                $totalUsersInRoom = $this->MUserGroup->find()
                    ->matching('MUserInfo', function ($q) {
                        return $q->where([
                            'MUserInfo.i_del_flag' => 0, // 削除されていない
                            'MUserGroup.active_flag' => 0 // アクティブなユーザー
                        ]);
                    })
                    ->where(['MUserGroup.i_id_room' => $roomData['room_id']])
                    ->count();
                //debug($totalUsersInRoom);

                $totalUsersInRoom = $this->MUserGroup->find()
                    ->matching('MUserInfo', function ($q) {
                        return $q->where([
                            'MUserInfo.i_del_flag' => 0, // 削除されていない
                            'MUserGroup.active_flag' => 0 // アクティブなユーザー
                        ]);
                    })
                    ->where(['MUserGroup.i_id_room' => $roomData['room_id']])
                    ->count();


                // デバッグログ
                $this->log("食べる人数 in room {$roomData['room_id']}: " . $roomData['taberu_ninzuu'], 'debug');
                $this->log("部屋 {$roomData['room_id']} に所属する有効ユーザー数: $totalUsersInRoom", 'debug');

                // 食べない人数は部屋の総ユーザー数から食べる人数を引く
                if ($totalUsersInRoom > 0) {
                    $remainingUsers = $totalUsersInRoom - $roomData['taberu_ninzuu'];
                    $roomData['tabenai_ninzuu'] = ($remainingUsers < 0) ? 0 : $remainingUsers;
                } else {
                    $roomData['tabenai_ninzuu'] = 0;
                }


                // 食べない人数の最終確認ログ
                $this->log("最終的な食べない人数 in room {$roomData['room_id']}: " . $roomData['tabenai_ninzuu'], 'debug');
            }
        }

        // ビューにデータをセット
        $this->set(compact('mealDataArray', 'date'));
    }





    /**
     * 部屋詳細メソッド
     *
     * @param int $roomId 部屋ID
     * @param string $date 日付
     * @param int $mealType 食事タイプ
     * @return \Cake\Http\Response|null|void ビューをレンダリングする
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

        // 全ユーザーを取得して「登録なし」のユーザーを集計
        $allUsers = $this->MUserGroup->find()
            ->select(['MUserInfo.i_id_user', 'MUserInfo.c_user_name'])
            ->contain(['MUserInfo'])
            ->where([
                'MUserGroup.i_id_room' => $roomId,
                'MUserInfo.i_del_flag' => 0, // 削除されていない
                'MUserGroup.active_flag' => 0 // グループが有効
            ])
            ->all();

        $nonEatersFull = [];
        foreach ($allUsers as $user) {
            // 食べる人に含まれていたらスキップ
            $isEater = $eaters->filter(function ($eater) use ($user) {
                    return $eater->i_id_user === $user->m_user_info->i_id_user;
                })->count() > 0;

            $isNonEater = $nonEaters->filter(function ($nonEater) use ($user) {
                    return $nonEater->i_id_user === $user->m_user_info->i_id_user;
                })->count() > 0;

            // 食べる人に含まれず、食べない人に含まれていない場合
            if (!$isEater && !$isNonEater) {
                $nonEatersFull[] = [
                    'i_id_user' => $user->m_user_info->i_id_user,
                    'c_user_name' => $user->m_user_info->c_user_name,
                ];
            }
        }

        // 食べる人の名前リスト
        $eatUsers = [];
        foreach ($eaters as $eater) {
            if ($eater->has('m_user_info')) {
                $eatUsers[] = $eater->m_user_info->c_user_name;
            }
        }

        // 食べない人の名前リスト（登録済み＋登録なし）
        $noEatUsers = [];
        foreach ($nonEaters as $nonEater) {
            if ($nonEater->has('m_user_info')) {
                $noEatUsers[] = $nonEater->m_user_info->c_user_name;
            }
        }

        // 登録なしユーザーを追加
        foreach ($nonEatersFull as $nonEater) {
            $noEatUsers[] = $nonEater['c_user_name'];
        }

        // 他の部屋で食べないとして登録されているユーザーの部屋名を取得
        $otherRoomEaters = [];
        foreach ($eaters as $eater) {
            // i_id_roomがnullでないことを確認
            if ($eater->has('m_user_info') && $eater->i_id_room !== null && $eater->i_id_room != $roomId) {
                $otherRoomRoom = $this->MRoomInfo->find()
                    ->select(['c_room_name'])
                    ->where(['i_id_room' => $eater->i_id_room])
                    ->first();

                // otherRoomRoomがnullの場合に備えてデフォルト値を設定
                $roomName = $otherRoomRoom ? $otherRoomRoom->c_room_name : '不明な部屋';

                $otherRoomEaters[] = [
                    'user_name' => $eater->m_user_info->c_user_name,
                    'room_name' => $otherRoomRoom->c_room_name
                ];
            }
        }


        // ビューにデータをセット
        $this->set(compact('room', 'date', 'mealType', 'eatUsers', 'noEatUsers', 'otherRoomEaters'));
    }




    /**
     * 所属しているユーザーを取得するメソッド
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

    // サーバーエラー時にJSONエラーレスポンスを返す
    // サーバー側の修正例 (checkDuplicateReservationメソッド)
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
     * 個人予約の処理
     */
    /**
     * 個人予約の処理
     */
    private function processIndividualReservation($reservationDate, $jsonData, $rooms)
    {
        // JSONデコードと入力検証
        if (empty($jsonData) || (!is_string($jsonData) && !is_array($jsonData))) {
            Log::error('入力データが無効です。空文字列または期待しない形式です。');
            return $this->jsonErrorResponse(__('入力データが無効です。'));
        }

        $data = is_string($jsonData) ? json_decode($jsonData, true) : $jsonData;
        if (is_null($data) || json_last_error() !== JSON_ERROR_NONE) {
            Log::error('JSONデコードエラー: ' . json_last_error_msg());
            return $this->jsonErrorResponse(__('JSONデータの形式が不正です: ') . json_last_error_msg());
        }

        // ログデバッグ
        Log::debug("processIndividualReservation called by User: {$this->request->getAttribute('identity')->get('i_id_user')}, Date: {$reservationDate}");
        Log::debug('デコード後のデータ: ' . json_encode($data, JSON_UNESCAPED_UNICODE));

        // 日付検証
        $dateValidation = $this->validateReservationDate($reservationDate);
        if ($dateValidation !== true) {
            Log::error('日付検証エラー: ' . $dateValidation);
            return $this->jsonErrorResponse(__($dateValidation));
        }

        // データ構造の検証
        if (!isset($data['meals']) || !is_array($data['meals'])) {
            Log::error('データ構造が不正: "meals" キーが存在しない、または配列ではありません。データ: ' . json_encode($data));
            return $this->jsonErrorResponse(__('データ構造が不正です。'));
        }

        $reservationsToSave = [];
        $operationPerformed = false; // 更新または新規予約の実施を追跡するフラグ
        $errors = [];
        $userId   = $this->request->getAttribute('identity')->get('i_id_user');
        $userName = $this->request->getAttribute('identity')->get('c_user_name');

        foreach ($data['meals'] as $mealType => $selectedRooms) {
            foreach ($selectedRooms as $roomId => $value) {
                if ($value == 1) {
                    Log::debug("Processing reservation for Meal Type: {$mealType}, Room ID: {$roomId}");

                    if (!array_key_exists($roomId, $rooms)) {
                        Log::error('権限のない部屋が指定されました。Room ID: ' . $roomId);
                        return $this->jsonErrorResponse(__('選択された部屋は権限がありません。'));
                    }

                    // 既存予約の確認
                    $existingReservation = $this->TIndividualReservationInfo->find()
                        ->where([
                            'i_id_user' => $userId,
                            'd_reservation_date' => $reservationDate,
                            'i_reservation_type' => $mealType,
                        ])
                        ->first();

                    if ($existingReservation) {
                        if ($existingReservation->eat_flag == 0) {
                            // 更新処理
                            $updateFields = [
                                'i_id_room'     => $roomId,
                                'eat_flag'      => 1,
                                'c_create_user' => $userName,
                                'dt_create'     => FrozenTime::now()
                            ];
                            $this->TIndividualReservationInfo->updateAll($updateFields, ['i_id_room' => $existingReservation->i_id_room]);
                            $operationPerformed = true; // 更新が成功したことを記録
                            continue;
                        } else {
                            Log::error('同じ日付と食事タイプの予約が既に存在します。');
                            return $this->jsonErrorResponse(__('同じ日付と食事タイプの予約が既に存在します。'));
                        }
                    }

                    // 新規予約作成
                    $newReservation = $this->TIndividualReservationInfo->newEmptyEntity();
                    $newReservation = $this->TIndividualReservationInfo->patchEntity($newReservation, [
                        'i_id_user'          => $userId,
                        'd_reservation_date' => $reservationDate,
                        'i_id_room'          => $roomId,
                        'i_reservation_type' => $mealType,
                        'eat_flag'           => 1,
                        'c_create_user'      => $userName,
                        'dt_create'          => FrozenTime::now(),
                    ]);

                    $reservationsToSave[] = $newReservation;
                }
            }
        }

        // 新規予約の一括保存処理
        if (!empty($reservationsToSave)) {
            if ($this->TIndividualReservationInfo->saveMany($reservationsToSave)) {
                return $this->jsonSuccessResponse(
                    __('個人予約が正常に登録されました。'),
                    [],
                $this->request->getAttribute('webroot') . 'TReservationInfo/'
                );

            } else {
                return $this->jsonErrorResponse(__('システムエラーが発生しました。'));
            }
        }

        // 更新処理のみが実行された場合
        if ($operationPerformed) {
            return $this->jsonSuccessResponse(
                __('個人予約が正常に登録されました。'),
                [],
                $this->request->getAttribute('webroot') . 'TReservationInfo/'
            );


        }

        return $this->jsonErrorResponse(__('システムエラーが発生しました。'));
    }



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




    protected function jsonErrorResponse(string $message, array $data = [])
    {
        return $this->response
            ->withType('application/json')
            ->withStringBody(json_encode(['status' => 'error', 'message' => $message, 'data' => $data], JSON_PRETTY_PRINT));
    }

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


    public function exportJson()
    {


        try {
            // リクエストから月を取得（フォーマット: YYYY-MM）
            $month = $this->request->getQuery('month'); // 例: 2023-11
            if (!$month) {
                throw new \InvalidArgumentException('月を指定してください (例: 2023-11)');
            }

            // 指定された月の開始日と終了日を取得
            $startDate = new \DateTime("$month-01");
            $endDate = (clone $startDate)->modify('last day of this month');

            // 弁当、朝、昼、夜のデータをDBから集計（指定された月）
            $reservations = $this->TIndividualReservationInfo->find()
                ->select([
                    'room_id' => 'TIndividualReservationInfo.i_id_room', // i_id_roomを明確に指定
                    'room_name' => 'MRoomInfo.c_room_name', // m_room_infoから部屋名を取得
                    'd_reservation_date',
                    'meal_type' => 'TIndividualReservationInfo.i_reservation_type', // テーブルを明確に
                    'total_eaters' => $this->TIndividualReservationInfo->find()->func()->count('*') // 人数をカウント
                ])
                ->join([
                    'table' => 'm_room_info', // 部屋情報テーブル
                    'alias' => 'MRoomInfo', // エイリアスを設定
                    'type' => 'INNER', // INNER JOIN
                    'conditions' => 'MRoomInfo.i_id_room = TIndividualReservationInfo.i_id_room', // 結合条件
                ])
                ->where([
                    'TIndividualReservationInfo.eat_flag' => 1, // eat_flagの条件を明確化
                    'TIndividualReservationInfo.d_reservation_date >=' => $startDate->format('Y-m-d'),
                    'TIndividualReservationInfo.d_reservation_date <=' => $endDate->format('Y-m-d'),
                ])
                ->groupBy([
                    'TIndividualReservationInfo.i_id_room',
                    'MRoomInfo.c_room_name',
                    'TIndividualReservationInfo.d_reservation_date',
                    'TIndividualReservationInfo.i_reservation_type'
                ]) // グループ化
                ->enableHydration(false) // 結果を配列形式で取得
                ->toArray();

            // 結果を部屋ごとに整形
            $result = ['rooms' => []];
            foreach ($reservations as $reservation) {
                $roomName = $reservation['room_name']; // 部屋名
                $date = $reservation['d_reservation_date']->format('Y-m-d'); // 日付フォーマット
                $mealType = $reservation['meal_type']; // 食事タイプ
                $totalEaters = $reservation['total_eaters'];

                // 部屋ごとのデータを初期化
                if (!isset($result['rooms'][$roomName])) {
                    $result['rooms'][$roomName] = [];
                }

                // 日付ごとのデータを初期化
                if (!isset($result['rooms'][$roomName][$date])) {
                    $result['rooms'][$roomName][$date] = ['朝' => 0, '昼' => 0, '夜' => 0, '弁当' => 0];
                }

                // 食事タイプごとの合計を増加
                $mealTypeMap = [1 => '朝', 2 => '昼', 3 => '夜', 4 => '弁当'];
                $result['rooms'][$roomName][$date][$mealTypeMap[$mealType]] += (int)$totalEaters;
            }

            // JSON形式で返却
            return $this->response
                ->withType('application/json')
                ->withStringBody(json_encode($result, JSON_UNESCAPED_UNICODE)); // 部屋名を正確に出力
        } catch (\Exception $e) {
            // エラー時の例外処理
            Log::write('error', $e->getMessage());
            return $this->response
                ->withStatus(500) // ステータスコード 500 を設定
                ->withType('application/json')
                ->withStringBody(json_encode(['error' => $e->getMessage()], JSON_UNESCAPED_UNICODE));
        }
    }

    public function exportJsonrank()
    {
        $this->autoRender = false;

        // クエリパラメータから月情報を取得
        $month = $this->request->getQuery('month');
        if (!$month || !preg_match('/^\d{4}-\d{2}$/', $month)) { // 年-月形式のチェック
            $output = [
                'message' => '無効な月が指定されました。'
            ];
            $this->response = $this->response
                ->withType('application/json')
                ->withStringBody(json_encode($output));
            return $this->response; // ここで処理停止
        }

        // ランク名とランクIDのマッピング
        $rankNames = [
            1 => '3~5歳',
            2 => '低学年',
            3 => '中学年',
            4 => '高学年',
            5 => '中学生',
            6 => '高校生',
            7 => '大人',
        ];

        // データ取得: 指定された月のデータを取得
        $reservations = $this->TIndividualReservationInfo->find()
            ->select([
                'user_rank' => 'MUserInfo.i_user_rank', // ランクID
                'gender' => 'MUserInfo.i_user_gender', // 性別
                'reservation_date' => 'TIndividualReservationInfo.d_reservation_date', // 日付
                'meal_type' => 'TIndividualReservationInfo.i_reservation_type', // 食事タイプ（朝・昼・夜・弁当）
                'total_eaters' => $this->TIndividualReservationInfo->find()->func()->count('*') // 合計人数
            ])
            ->join([
                [
                    'table' => 'm_user_info',
                    'alias' => 'MUserInfo',
                    'type' => 'INNER',
                    'conditions' => 'MUserInfo.i_id_user = TIndividualReservationInfo.i_id_user',
                ]
            ])
            ->where([
                'TIndividualReservationInfo.eat_flag' => 1, // 食事済み
                'TIndividualReservationInfo.d_reservation_date >=' => $month . '-01',
                'TIndividualReservationInfo.d_reservation_date <' => date('Y-m-d', strtotime($month . ' +1 month'))
            ])
            ->groupBy([
                'MUserInfo.i_user_rank',
                'MUserInfo.i_user_gender',
                'TIndividualReservationInfo.d_reservation_date',
                'TIndividualReservationInfo.i_reservation_type' // 食事タイプ別でグループ化
            ])
            ->enableHydration(false)
            ->toArray();

        // 結果データ整形
        $output = [];
        foreach ($reservations as $reservation) {
            $rankId = $reservation['user_rank'];
            $gender = $reservation['gender'] === 1 ? '男子' : ($reservation['gender'] === 2 ? '女子' : '不明');
            $rankName = isset($rankNames[$rankId]) ? $rankNames[$rankId] : '不明';

            // ランクと性別、日付ですでにキーが存在するか確認
            $key = $rankId . '_' . $gender . '_' . $reservation['reservation_date']->format('Y-m-d');
            if (!isset($output[$key])) {
                $output[$key] = [
                    'rank_name' => $rankName,
                    'gender' => $gender,
                    'reservation_date' => $reservation['reservation_date']->format('Y-m-d'),
                    'breakfast' => 0, // 朝食初期化
                    'lunch' => 0,     // 昼食初期化
                    'dinner' => 0,    // 夕食初期化
                    'bento' => 0,     // 弁当初期化
                    'total_eaters' => 0 // 合計人数初期化
                ];
            }

            // 食事タイプを判別して人数を追加
            $totalEaters = $reservation['total_eaters'] ?? 0;
            if ($reservation['meal_type'] === 1) {
                $output[$key]['breakfast'] += $totalEaters; // 朝食カウント更新
            } elseif ($reservation['meal_type'] === 2) {
                $output[$key]['lunch'] += $totalEaters; // 昼食カウント更新
            } elseif ($reservation['meal_type'] === 3) {
                $output[$key]['dinner'] += $totalEaters; // 夕食カウント更新
            } elseif ($reservation['meal_type'] === 4) {
                $output[$key]['bento'] += $totalEaters; // 弁当カウント更新
            }

            // 総人数を更新
            $output[$key]['total_eaters'] += $totalEaters;
        }

        // データフォーマットを調整
        $finalOutput = array_values($output);

        // データが空の場合の処理
        if (empty($finalOutput)) {
            $finalOutput = [
                'message' => '指定された月にデータが見つかりませんでした。'
            ];
        }

        // JSON形式で返却
        $this->response = $this->response
            ->withType('application/json')
            ->withStringBody(json_encode($finalOutput));

        return $this->response;
    }
}
