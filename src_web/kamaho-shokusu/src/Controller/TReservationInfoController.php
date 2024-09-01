<?php
declare(strict_types=1);

namespace App\Controller;

use Cake\Event\EventInterface;
use Cake\Http\Exception\NotFoundException;
use App\Controller\InvalidArgumentException;
use Cake\I18n\DateTime;
use Cake\Log\Log;
use Cake\I18n\FrozenDate;
use Cake\I18n\FrozenTime;

/**
 * TReservationInfo コントローラー
 *
 * @property \App\Model\Table\TReservationInfoTable $TReservationInfo
 */
class TReservationInfoController extends AppController
{
    public function initialize(): void
    {
        parent::initialize();
        $this->fetchTable('TReservationInfo');
        $this->fetchTable('MRoomInfo');
        $this->fetchTable('MUserInfo');
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
     * 追加メソッド
     *
     * @return \Cake\Http\Response|null|void 成功時にはリダイレクト、ビューをレンダリング
     */
    public function add()
    {
        $tReservationInfo = $this->TReservationInfo->newEmptyEntity();

        // URLのクエリパラメータから予約日を取得
        $reservationDate = $this->request->getQuery('date');

        if ($this->request->is('post')) {
            $data = $this->request->getData();
            $tReservationInfo->dt_create = date('Y-m-d H:i:s'); // 作成日時を設定

            // 予約日が指定されているか確認し、エンティティに設定
            if (!empty($reservationDate)) {
                $tReservationInfo->d_reservation_date = $reservationDate;
            } else {
                $this->Flash->error(__('予約日が選択されていません。'));
                return $this->redirect(['action' => 'add']);
            }

            // 部屋IDと予約タイプが指定されているか確認
            if (!empty($data['i_id_room']) && !empty($data['c_reservation_type'])) {
                $tReservationInfo->i_id_room = $data['i_id_room'];
                $tReservationInfo->c_reservation_type = $data['c_reservation_type'];
            } else {
                $this->Flash->error(__('部屋IDまたは予約タイプが選択されていません。'));
                return $this->redirect(['action' => 'add']);
            }

            // ログインユーザー情報を取得
            $user = $this->request->getAttribute('identity');
            if ($user) {
                $tReservationInfo->c_create_user = $user->get('c__user_name'); // 作成ユーザーを設定
            } else {
                $this->Flash->error(__('ユーザー情報が取得できませんでした。'));
                return $this->redirect(['action' => 'add']);
            }

            // 他のフィールドをリクエストデータからパッチ
            $tReservationInfo = $this->TReservationInfo->patchEntity($tReservationInfo, $data);

            // 予約情報をデータベースに保存
            if ($this->TReservationInfo->save($tReservationInfo)) {
                $this->Flash->success(__('予約を承りました。'));
                return $this->redirect(['action' => 'index']);
            }
            $this->Flash->error(__('予約を受け付けることができませんでした。もう一度お試しください。'));
        }

        // 部屋情報を取得してビューに渡す
        $MRoomInfoTable = $this->fetchTable('MRoomInfo');
        $rooms = $MRoomInfoTable->find('list', [
            'keyField' => 'i_id_room',
            'valueField' => 'c_room_name'
        ])->toArray();

        // 予約情報と部屋リストをビューにセット
        $this->set(compact('tReservationInfo', 'rooms', 'reservationDate'));
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


    public function bulkAddSubmit()
    {
        // リクエストがPOSTであるか確認
        if (!$this->request->is('post')) {
            $this->Flash->error(__('不正なリクエストです。POSTメソッドで送信してください。'));
            return $this->redirect(['action' => 'bulkAddForm']);
        }

        // フォームデータの取得
        $data = $this->request->getData();

        // デバッグのためのログ出力
        Log::debug('--- フォームデータの内容 ---');
        Log::debug(print_r($data, true)); // フォームデータを文字列形式でログに記録

        // 部屋IDが選択されているか確認
        if (empty($data['i_id_room'])) {
            Log::error('部屋が選択されていません。');
            $this->Flash->error(__('部屋が選択されていません。'));
            return $this->redirect(['action' => 'bulkAddForm', 'date' => $data['start_date'] ?? null]);
        }

        // データの中身が正しいか確認
        if (empty($data['data']) || !is_array($data['data'])) {
            Log::error('日付が指定されていません。またはデータが正しくありません。');
            $this->Flash->error(__('日付が指定されていません。'));
            return $this->redirect(['action' => 'bulkAddForm', 'date' => $data['start_date'] ?? null]);
        }

        // 食事タイプのマッピング
        $mealTypeMapping = [
            'morning' => 1,
            'noon' => 2,
            'night' => 3
        ];

        // 各日付のデータを処理
        foreach ($data['data'] as $date => $mealData) {
            Log::debug("Processing date: $date");

            foreach ($mealTypeMapping as $mealTypeStr => $mealTypeInt) {
                if (!empty($mealData[$mealTypeStr]['taberu']) || !empty($mealData[$mealTypeStr]['tabenai'])) {
                    $reservation = $this->TReservationInfo->newEmptyEntity();
                    $reservation->d_reservation_date = $date;
                    $reservation->c_reservation_type = $mealTypeInt; // 整数値を使用
                    $reservation->i_id_room = $data['i_id_room'];
                    $reservation->i_taberu_ninzuu = $mealData[$mealTypeStr]['taberu'];
                    $reservation->i_tabenai_ninzuu = $mealData[$mealTypeStr]['tabenai'];
                    $reservation->dt_create = FrozenTime::now();
                    $reservation->c_create_user = $this->request->getAttribute('identity')->c_user_name;

                    Log::debug("Meal type: $mealTypeStr, Taberu: {$mealData[$mealTypeStr]['taberu']}, Tabenai: {$mealData[$mealTypeStr]['tabenai']}");

                    if ($this->TReservationInfo->save($reservation)) {
                        Log::debug("予約が正常に保存されました: 日付=" . $date . " 食事タイプ=" . $mealTypeInt);
                    } else {
                        Log::error("予約の保存に失敗しました: 日付=" . $date . " 食事タイプ=" . $mealTypeInt);
                        Log::error(print_r($reservation->getErrors(), true));
                    }
                }
            }
        }

        $this->Flash->success(__('データが正常に保存されました。'));
        return $this->redirect(['action' => 'index']);
    }

    private function saveReservation($startDate, $reservationType, $roomId, $eatingCount, $notEatingCount)
    {
        // $startDate が null の場合の処理
        if ($startDate === null) {
            $this->Flash->error(__('開始日が指定されていません。'));
            return;
        }

        // 有効な日付文字列であることを確認
        try {
            $currentDate = new \DateTime($startDate);
        } catch (\Exception $e) {
            $this->Flash->error(__('無効な日付が指定されました。'));
            return;
        }

        for ($day = 0; $day < 5; $day++) { // 月曜日から金曜日まで
            $reservationDate = $currentDate->format('Y-m-d');

            $reservation = $this->TReservationInfo->find()
                ->where([
                    'd_reservation_date' => $reservationDate,
                    'c_reservation_type' => $reservationType,
                    'i_id_room' => $roomId
                ])
                ->first();

            if (!$reservation) {
                $reservation = $this->TReservationInfo->newEmptyEntity();
                $reservation->d_reservation_date = $reservationDate;
                $reservation->c_reservation_type = $reservationType;
                $reservation->i_id_room = $roomId;
            }

            $reservation->i_eating_count = $eatingCount;
            $reservation->i_not_eating_count = $notEatingCount;

            if ($this->TReservationInfo->save($reservation)) {
                // 成功した場合の処理
            } else {
                $this->Flash->error(__('予約を保存できませんでした。もう一度お試しください。'));
            }

            $currentDate->modify('+1 day');
        }
    }

    private function getHolidays($startDate, $endDate)
    {
        // 祝日情報を取得するロジックを実装
        // この例では、祝日を管理するための日本の祝日APIなどを呼び出すことを想定
        // APIが無い場合、固定の祝日リストなどを利用

        return [
            // 'YYYY-MM-DD'形式の祝日リスト
            '2024-09-16', // 敬老の日
            '2024-09-23', // 秋分の日
        ];
    }


    /**
     * 編集メソッド
     *
     * @param string|null $id T Reservation Info id.
     * @return \Cake\Http\Response|null|void 成功時にはリダイレクト、ビューをレンダリング
     */
    public function edit($id = null)
    {
        date_default_timezone_set('Asia/Tokyo'); // タイムゾーンを東京に設定
        $tReservationInfos = [];

        // `id`が指定された場合、そのIDで予約情報を取得
        if ($id !== null) {
            $tReservationInfo = $this->TReservationInfo->get($id);
            $tReservationInfos[] = $tReservationInfo; // 単一の予約情報でも配列に格納
        } else {
            // `id`が指定されていない場合、クエリパラメータから`date`を取得
            $reservationDate = $this->request->getQuery('date');

            if (!$reservationDate) {
                $this->Flash->error(__('予約日が指定されていません。'));
                return $this->redirect(['action' => 'index']);
            }

            // 指定された日付の予約情報を取得
            $tReservationInfos = $this->TReservationInfo->find()
                ->where(['d_reservation_date' => $reservationDate])
                ->all()
                ->toArray();

            if (empty($tReservationInfos)) {
                $this->Flash->error(__('Reservation not found.'));
                return $this->redirect(['action' => 'index']);
            }
        }

        // リクエストがPOSTまたはPUTの場合、データを保存する
        if ($this->request->is(['post', 'put'])) {
            $data = $this->request->getData();

            // ログインユーザー情報を取得して更新者を設定
            $user = $this->request->getAttribute('identity');
            if ($user) {
                foreach ($tReservationInfos as $tReservationInfo) {
                    $tReservationInfo->c_update_user = $user->get('c__user_name'); // 更新ユーザーを設定
                    $tReservationInfo->dt_update = date('Y-m-d H:i:s'); // 更新日時を設定
                    // データをパッチ
                    $tReservationInfo = $this->TReservationInfo->patchEntity($tReservationInfo, $data);

                    // データベースに保存
                    if (!$this->TReservationInfo->save($tReservationInfo)) {
                        $this->Flash->error(__('予約情報の更新に失敗しました。もう一度お試しください。'));
                        return $this->redirect(['action' => 'index']);
                    }
                }

                $this->Flash->success(__('予約情報が更新されました。'));
                return $this->redirect(['action' => 'index']);
            } else {
                $this->Flash->error(__('ユーザー情報が取得できませんでした。'));
                return $this->redirect(['action' => 'edit', $id]);
            }
        }

        // 部屋情報を取得してビューに渡す
        $MRoomInfoTable = $this->fetchTable('MRoomInfo');
        $rooms = $MRoomInfoTable->find('list', keyField: 'i_id_room', valueField: 'c_room_name')->toArray();

        // 予約情報と部屋リストをビューにセット
        $this->set(compact('tReservationInfos', 'rooms'));
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
}
