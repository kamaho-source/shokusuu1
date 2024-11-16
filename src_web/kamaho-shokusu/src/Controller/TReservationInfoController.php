<?php
declare(strict_types=1);

namespace App\Controller;

use Cake\Controller\ComponentRegistry;
use Cake\Datasource\ConnectionManager;
use Cake\Event\EventInterface;
use Cake\Http\Exception\BadRequestException;
use Cake\Http\Exception\NotFoundException;
use App\Controller\InvalidArgumentException;
use Cake\I18n\Date;
use Cake\I18n\FrozenTime;
use Cake\I18n\Time;
use Cake\Log\Log;
use Cake\I18n\FrozenDate;
use mysql_xdevapi\Result;

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

    /**
     * インデックスメソッド
     *
     * @return \Cake\Http\Response|null|void ビューをレンダリングする
     */
    public function index()
    {
        // TReservationInfoテーブルから予約データを取得
        $reservations = $this->TReservationInfo->find()
            ->select([
                'd_reservation_date',
                'c_reservation_type',
                'i_taberu_ninzuu',
            ])
            ->toArray();

        // 日付ごとの食事タイプ（朝、昼、夜）の総数を保持するための連想配列を初期化
        $mealDataArray = [];

        foreach ($reservations as $reservation) {
            $date = $reservation->d_reservation_date->format('Y-m-d');
            $mealType = $reservation->c_reservation_type; // 食事タイプ: 1 (朝), 2 (昼), 3 (夜)

            // 指定された日付のエントリが存在しない場合、初期化
            if (!isset($mealDataArray[$date])) {
                $mealDataArray[$date] = [1 => 0, 2 => 0, 3 => 0]; // 朝、昼、夜のカウントを0で初期化
            }

            // 特定の日付と食事タイプに対して食事の人数を加算
            $mealDataArray[$date][$mealType] += (int)$reservation->i_taberu_ninzuu;
        }

        // 計算した食事データをビューにセット
        $this->set(compact('mealDataArray'));
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
        $reservations = $this->TReservationInfo->find('all');

        // 予約データをFullCalendarで使用する形式に変換
        $events = [];
        foreach ($reservations as $reservation) {
            $events[] = [
                'title' => '合計食数: ' . $reservation->i_taberu_ninzuu,
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

        // 指定された日付の予約データを取得し、関連する部屋情報も含める
        $roomInfos = $this->TReservationInfo->find()
            ->contain(['MRoomInfo']) // MRoomInfoテーブルから部屋情報を含める
            ->where(['d_reservation_date' => $date]) // 指定された日付でフィルタリング
            ->all();

        // 食事タイプごとに部屋情報をグループ化するための連想配列を初期化
        $groupedRoomInfos = [
            '朝' => [],
            '昼' => [],
            '夜' => []
        ];

        // データを食事タイプ（朝、昼、夜）ごとにグループ化
        foreach ($roomInfos as $roomInfo) {
            $mealType = '';
            switch ($roomInfo->c_reservation_type) {
                case 1:
                    $mealType = '朝'; // 朝食
                    break;
                case 2:
                    $mealType = '昼'; // 昼食
                    break;
                case 3:
                    $mealType = '夜'; // 夕食
                    break;
            }

            // 食事タイプが正しく設定された場合のみ連想配列に追加
            if ($mealType) {
                $groupedRoomInfos[$mealType][] = [
                    'room_name' => $roomInfo->m_room_info->c_room_name, // 部屋名
                    'reservation_type' => $mealType, // 予約タイプ
                    'taberu_ninzuu' => $roomInfo->i_taberu_ninzuu, // 食べる人数
                    'tabenai_ninzuu' => $roomInfo->i_tabenai_ninzuu // 食べない人数
                ];
            }
        }

        // グループ化された部屋情報と日付をビューにセット
        $this->set(compact('groupedRoomInfos', 'date'));
    }


    /**
     * 所属しているユーザーを取得するメソッド
     */

    public function getUsersByRoom($roomId)
    {
        Log::debug('Received reservation_type: ' . $this->request->getData('reservation_type'));

        $this->request->allowMethod(['get', 'ajax']);
        $this->autoRender = false;

        // $roomIdを整数に変換
        $roomId = (int) $roomId;

        // reservation_typeを取得 (クエリパラメータから受け取る)
        $reservationType = $this->request->getQuery('reservation_type');

        // 個人予約の場合は現在のログインユーザーのみを取得
        if ($reservationType == '1') {
            $userId = $this->Auth->user('id'); // ログイン中のユーザーのIDを取得

            // ユーザー情報を取得
            $userInfo = $this->MUserInfo->find()
                ->select(['i_id_user', 'c_user_name'])
                ->where(['i_id_user' => $userId])
                ->first();

            if (!$userInfo) {
                throw new NotFoundException(__('ユーザーが見つかりませんでした。'));
            }

            $completeUserInfo[] = [
                'id' => $userInfo->i_id_user,
                'name' => $userInfo->c_user_name,
                'room' => $roomId
            ];
        } else {
            // 集団予約の場合は部屋に属するユーザーを取得
            $usersByRoom = $this->MUserGroup->find()
                ->select(['i_id_user', 'i_id_room'])
                ->where(['i_id_room' => $roomId])
                ->toArray();

            if (empty($usersByRoom)) {
                throw new NotFoundException(__('部屋に属するユーザーが見つかりませんでした。'));
            }

            $completeUserInfo = [];
            foreach ($usersByRoom as $user) {
                $userInfo = $this->MUserInfo->find()
                    ->select(['c_user_name'])
                    ->where(['i_id_user' => $user->i_id_user])
                    ->first();
                if ($userInfo) {
                    $completeUserInfo[] = [
                        'id' => $user->i_id_user,
                        'name' => $userInfo->c_user_name,
                        'room' => $user->i_id_room
                    ];
                }
            }
        }

        // JSON形式でレスポンスを返す
        $response = ['usersByRoom' => $completeUserInfo];
        return $this->response->withType('application/json')
            ->withStringBody(json_encode($response));
    }



    public function add()
    {
        if ($this->request->is('post')) {
            $data = $this->request->getData();
            Log::debug('Received POST data: ' . print_r($data, true));

            // reservationDate の確認
            $reservationDate = $data['d_reservation_date'] ?? null;
            Log::debug('Retrieved reservation date from POST data: ' . $reservationDate);

            if ($reservationDate === null) {
                Log::error('Reservation date is missing!');
                return $this->jsonErrorResponse(__('予約日が選択されていません。'));
            }

            $reservationType = $data['reservation_type'] ?? '1';
            Log::debug('Effective reservation_type: ' . $reservationType);

            if ($reservationType === '2') {
                return $this->processGroupReservation($reservationDate, $data);
            } else {
                return $this->processIndividualReservation($reservationDate, $data);
            }
        }

        // GET リクエスト
        $user = $this->request->getAttribute('identity');
        $userId = $user ? $user->get('i_id_user') : null;

        if ($userId === null) {
            Log::error('User ID is missing!');
            return $this->jsonErrorResponse(__('ユーザー情報が取得できませんでした。'));
        }

        // 部屋情報取得
        $rooms = $this->MRoomInfo->find('list', [
            'keyField' => 'i_id_room',
            'valueField' => 'c_room_name'
        ])
            ->matching('MUserGroup', function ($q) use ($userId) {
                return $q->where(['MUserGroup.i_id_user' => $userId]);
            })
            ->toArray();

        $tReservationInfo = $this->TReservationInfo->newEmptyEntity();
        $this->set(compact('tReservationInfo', 'rooms'));
    }




    /**
     * 個人予約の処理
     */
    /**
     * 個人予約の処理
     */
    private function processIndividualReservation($reservationDate, $data)
    {
        Log::debug('Processing individual reservation.');

        $user = $this->request->getAttribute('identity');
        if (!$user) {
            Log::error('User information could not be retrieved.');
            return $this->jsonErrorResponse(__('ユーザー情報が取得できませんでした。'));
        }

        $userId = $user->get('i_id_user');
        $username = $user->get('c_user_name');

        if (empty($data['meals']) || !is_array($data['meals'])) {
            Log::error('Meals data is missing or invalid.');
            return $this->jsonErrorResponse(__('食事データが不正です。'));
        }

        Log::debug('Received meals data: ' . json_encode($data['meals'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

        foreach ($data['meals'] as $mealType => $rooms) {
            foreach ($rooms as $roomId => $value) {
                if ($value == "1") { // チェックされた項目のみ処理
                    Log::debug("Processing mealType: $mealType, roomId: $roomId for userId: $userId");

                    $mealTime = (int) $mealType; // 1: 朝, 2: 昼, 3: 夜
                    $result = $this->saveIndividualReservation($userId, $reservationDate, $roomId, $mealTime, $username);

                    if (!$result['success']) {
                        Log::error('Failed to save reservation for mealType: ' . $mealType . ', roomId: ' . $roomId);
                        return $this->jsonErrorResponse(
                            __('予約情報の保存に失敗しました。'),
                            ['errors' => $result['errors']]
                        );
                    }
                }
            }
        }

        return $this->jsonSuccessResponse(__('予約を承りました。'));
    }


    /**
     * 集団予約の処理
     *
     */
    private function processGroupReservation($reservationDate, $data)
    {
        Log::debug('Processing group reservation.');
        Log::debug('Received users data: ' . json_encode($data['users'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

        // 必要なデータが存在するか確認
        if (empty($data['i_id_room'])) {
            Log::error('Room ID is missing.');
            return $this->jsonErrorResponse(__('部屋が選択されていません。'));
        }

        if (!isset($data['users']) || !is_array($data['users']) || empty($data['users'])) {
            Log::error('ユーザー情報が不足しています。');
            return $this->jsonErrorResponse(__('ユーザー情報が不足しています。'));
        }

        // 日付のフォーマットチェックと変換
        try {
            $reservationDateObj = new FrozenDate($reservationDate);
            Log::debug('Converted reservation date: ' . $reservationDateObj);
        } catch (\Exception $e) {
            Log::error('Failed to convert reservation date: ' . $e->getMessage());
            return $this->jsonErrorResponse(__('予約日が不正です。'));
        }

        // フィルタリング: 選択されたデータのみ取得
        $filteredUsersData = array_filter($data['users'], function ($meals) {
            return is_array($meals) && array_filter($meals, fn($value) => intval($value) === 1);
        });

        Log::debug('Filtered users data: ' . json_encode($filteredUsersData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

        $connection = ConnectionManager::get('default');
        $connection->begin();

        try {
            foreach ($filteredUsersData as $userId => $meals) {
                foreach ([1 => '朝', 2 => '昼', 3 => '夜'] as $mealTime => $mealName) {
                    if (isset($meals[$mealTime]) && intval($meals[$mealTime]) === 1) {
                        $individualReservation = $this->TIndividualReservationInfo->newEntity([
                            'i_id_user' => $userId,
                            'd_reservation_date' => $reservationDateObj,
                            'i_id_room' => $data['i_id_room'],
                            'i_reservation_type' => $mealTime,
                            'eat_flag' => 1,
                            'c_create_user' => $this->request->getAttribute('identity')->get('c_user_name'),
                            'dt_create' => FrozenTime::now()
                        ]);

                        // 主キーの各フィールドが設定されているかを確認
                        Log::debug('Attempting to save reservation: ' . json_encode([
                                'i_id_user' => $userId,
                                'd_reservation_date' => $reservationDateObj,
                                'i_id_room' => $data['i_id_room'],
                            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

                        if (!$this->TIndividualReservationInfo->save($individualReservation)) {
                            $errors = $individualReservation->getErrors();
                            Log::error('Individual reservation save failed: ' . json_encode($errors, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
                            $connection->rollback();
                            return $this->jsonErrorResponse(__('予約情報の保存に失敗しました。'), ['errors' => $errors]);
                        }
                    }
                }
            }

            $connection->commit();
            return $this->jsonSuccessResponse(__('予約を承りました。'));

        } catch (\Exception $e) {
            Log::error('Exception during reservation save: ' . $e->getMessage());
            $connection->rollback();
            return $this->jsonErrorResponse(__('予約を受け付けることができませんでした。'), ['exception' => $e->getMessage()]);
        }
    }




    protected function jsonErrorResponse(string $message, array $data = [])
    {
        return $this->response
            ->withType('application/json')
            ->withStringBody(json_encode(['status' => 'error', 'message' => $message, 'data' => $data], JSON_PRETTY_PRINT));
    }

    protected function jsonSuccessResponse(string $message, array $data = [])
    {
        return $this->response
            ->withType('application/json')
            ->withStringBody(json_encode(['status' => 'success', 'message' => $message, 'data' => $data], JSON_PRETTY_PRINT));
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
            $startDate->modify('monday this week'); // 週の月曜日を取得
        } catch (\Exception $e) {
            $this->Flash->error(__('無効な日付が指定されました。'));
            return $this->redirect(['action' => 'index']);
        }

        $dates = [];
        for ($i = 0; $i < 5; $i++) { // 月曜日から金曜日まで
            $dates[] = clone $startDate;
            $startDate->modify('+1 day');
        }

        // 部屋の情報を取得
        $MRoomInfoTable = $this->fetchTable('MRoomInfo');
        $rooms = $MRoomInfoTable->find('list', [
            'keyField' => 'i_id_room',
            'valueField' => 'c_room_name'
        ])->toArray();

        // ビューにデータをセット
        $this->set(compact('dates', 'rooms'));
    }


    /**
     * 予約情報を保存するメソッド
     *
     * @param int $counts 予約数
     * @param \DateTime $reservationDate 予約日
     * @param int $mealType 食事タイプ
     * @param string $userName ユーザー名
     * @return bool 保存に成功した場合はtrue、それ以外はfalse
     */

    private function saveReservation($data, $reservationDate, $mealType, $userName)
    {
        $reservation = $this->TReservationInfo->newEmptyEntity();

        // データをエンティティにパッチ
        $reservation->d_reservation_date = $reservationDate;
        $reservation->c_reservation_type = $mealType;
        $reservation->i_id_room = $data['i_id_room'] ?? null;
        $reservation->i_taberu_ninzuu = $data['taberu'] ?? 0;
        $reservation->i_tabenai_ninzuu = $data['tabenai'] ?? 0;
        $reservation->dt_create = FrozenTime::now();
        $reservation->c_create_user = $userName;

        // 予約情報を保存
        return $this->TReservationInfo->save($reservation);
    }



    /**
     * 一括追加メソッド
     *
     * @return \Cake\Http\Response|null|void 成功時にはリダイレクト、ビューをレンダリング
     */


    public function bulkAddSubmit()
    {
        // 送信されたデータをデバッグする（配列をJSON形式でログ出力）
        $this->log(json_encode($this->request->getData(), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), 'debug');

        $data = $this->request->getData();
        $reservations = [];
        $user = $this->request->getAttribute('identity');
        $currentUser = $user->get('c__user_name');// ログインしているユーザーの取得
        $currentDateTime = date('Y-m-d H:i:s'); // 現在の日時

        // 各日付に対して予約データを作成
        foreach ($data['data'] as $date => $reservationData) {
            foreach (['morning', 'noon', 'night'] as $mealTime) {
                $reservation = $this->TReservationInfo->newEmptyEntity();

                // 主キーの値を確認するデバッグ出力（文字列化してログ出力）
                $this->log("Date: $date, Room: {$data['i_id_room']}, Reservation Type: {$reservationData[$mealTime]['reservation_type']}", 'debug');

                // 主キーおよび必要なデータの設定
                $reservation->d_reservation_date = $date;
                $reservation->i_id_room = $data['i_id_room']; // 部屋番号
                $reservation->c_reservation_type = $reservationData[$mealTime]['reservation_type']; // 朝昼夜の区別

                // 他のフィールドの設定
                $reservation->i_taberu_ninzuu = $reservationData[$mealTime]['taberu'] ?? 0; // デフォルト値0
                $reservation->i_tabenai_ninzuu = $reservationData[$mealTime]['tabenai'] ?? 0; // デフォルト値0
                $reservation->dt_create = $currentDateTime; // 作成日時
                $reservation->c_create_user = $currentUser; // ログインユーザー名

                // エンティティが正しく作成されたか確認（オブジェクトを文字列化してログ出力）
                $this->log(print_r($reservation, true), 'debug');

                $reservations[] = $reservation;
            }
        }

        // データベースに保存
        if ($this->TReservationInfo->saveMany($reservations)) {
            $this->Flash->success(__('月曜日から金曜日までの一括予約が完了しました。.'));
        } else {
            $this->Flash->error(__('月曜日から金曜日までの一括予約を完了できませんでした。再度やり直してください。'));
        }

        return $this->redirect(['action' => 'index']);
    }

    /**
     * 編集メソッド
     *
     * @param string|null $id T Reservation Info id.
     * @return \Cake\Http\Response|null|void 成功時にはリダイレクト、ビューをレンダリング
     */
    public function edit($id = null)
    {
        $tReservationInfos = [];

        // `id`が指定されている場合、そのIDで予約情報を取得
        if ($id !== null) {
            try {
                $tReservationInfo = $this->TReservationInfo->get($id);
                $tReservationInfos[] = $tReservationInfo;
                Log::debug('取得した予約情報: ' . print_r($tReservationInfo, true));
            } catch (RecordNotFoundException $e) {
                $this->Flash->error(__('予約情報が見つかりませんでした。'));
                Log::error('予約情報が見つかりませんでした: ' . $id);
                return $this->redirect(['action' => 'index']);
            }
        } else {
            // クエリパラメータから`date`を取得
            $reservationDate = $this->request->getQuery('date');
            Log::debug('クエリパラメータから取得した日付: ' . $reservationDate);

            if (!$reservationDate) {
                $this->Flash->error(__('予約日が指定されていません。'));
                Log::error('予約日が指定されていません');
                return $this->redirect(['action' => 'index']);
            }

            // 日付で予約情報を検索
            $tReservationInfos = $this->TReservationInfo->find()
                ->where(['d_reservation_date' => $reservationDate])
                ->all()
                ->toArray();
            Log::debug('取得した予約情報のリスト: ' . print_r($tReservationInfos, true));

            if (empty($tReservationInfos)) {
                $this->Flash->error(__('指定された日付の予約が見つかりませんでした。'));
                Log::error('指定された日付の予約が見つかりませんでした: ' . $reservationDate);
                return $this->redirect(['action' => 'index']);
            }
        }

        if ($this->request->is(['post', 'put'])) {
            $data = $this->request->getData();
            Log::debug('フォームデータ: ' . print_r($data, true));

            // ログインユーザー情報を取得
            $user = $this->request->getAttribute('identity');
            if ($user) {
                foreach ($tReservationInfos as $index => $tReservationInfo) {
                    if (isset($data['tReservationInfos'][$index])) {
                        $editData = $data['tReservationInfos'][$index];

                        $tReservationInfo = $this->TReservationInfo->patchEntity($tReservationInfo, [
                            'i_id_room' => $editData['i_id_room'] ?? $tReservationInfo->i_id_room,
                            'c_reservation_type' => $editData['c_reservation_type'] ?? $tReservationInfo->c_reservation_type,
                            'i_taberu_ninzuu' => $editData['i_taberu_ninzuu'] ?? $tReservationInfo->i_taberu_ninzuu,
                            'i_tabenai_ninzuu' => $editData['i_tabenai_ninzuu'] ?? $tReservationInfo->i_tabenai_ninzuu,
                        ]);

                        // ログインユーザー情報を正しくセット
                        $tReservationInfo->c_update_user = $user->get('c_user_name');
                        $tReservationInfo->dt_update = date('Y-m-d H:i:s');
                        Log::debug('保存前の予約情報: ' . print_r($tReservationInfo, true));

                        if (!$this->TReservationInfo->save($tReservationInfo)) {
                            Log::error('予約情報の保存に失敗: ' . print_r($tReservationInfo->getErrors(), true));
                            $this->Flash->error(__('予約情報の更新に失敗しました。もう一度お試しください。'));
                            return $this->redirect(['action' => 'index']);
                        }
                    } else {
                        Log::error("該当するフォームデータが見つかりませんでした: {$index}");
                    }
                }

                $this->Flash->success(__('予約情報が更新されました。'));
                return $this->redirect(['action' => 'index']);
            } else {
                $this->Flash->error(__('ユーザー情報が取得できませんでした。'));
                Log::error('ユーザー情報の取得に失敗');
                return $this->redirect(['action' => 'edit', $id]);
            }
        }

        // 部屋情報を取得してビューに渡す
        $MRoomInfoTable = $this->fetchTable('MRoomInfo');
        $rooms = $MRoomInfoTable->find('list', [
            'keyField' => 'i_id_room',
            'valueField' => 'c_room_name'
        ])->toArray();
        Log::debug('取得した部屋情報リスト: ' . print_r($rooms, true));

        $this->set(compact('tReservationInfos', 'rooms', 'id'));
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
    private function saveIndividualReservation($userId, $reservationDate, $roomId, $mealTime, $username)
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


}
