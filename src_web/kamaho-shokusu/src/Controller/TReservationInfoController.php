<?php
declare(strict_types=1);

namespace App\Controller;

use Cake\Datasource\Exception\RecordNotFoundException;
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
        // 新規の予約情報エンティティを作成
        $tReservationInfo = $this->TReservationInfo->newEmptyEntity();
        $reservationDate = $this->request->getQuery('date');

        if ($this->request->is('post')) {
            $data = $this->request->getData();

            // 予約日が指定されているか確認
            if (empty($reservationDate)) {
                $this->Flash->error(__('予約日が選択されていません。'));
                return $this->redirect(['action' => 'add']);
            }

            // 部屋IDと予約タイプが指定されているか確認
            if (empty($data['i_id_room']) || empty($data['c_reservation_type'])) {
                $this->Flash->error(__('部屋IDまたは予約タイプが選択されていません。'));
                return $this->redirect(['action' => 'add']);
            }

            // ログインユーザー情報の取得
            $user = $this->request->getAttribute('identity');
            if (!$user) {
                $this->Flash->error(__('ユーザー情報が取得できませんでした。'));
                return $this->redirect(['action' => 'add']);
            }

            // データを予約情報に設定
            $tReservationInfo->d_reservation_date = $reservationDate;
            $tReservationInfo->i_id_room = $data['i_id_room'];
            $tReservationInfo->c_reservation_type = $data['c_reservation_type'];
            $tReservationInfo->c_create_user = $user->get('c__user_name');
            $tReservationInfo->dt_create = date('Y-m-d H:i:s');

            // 他のフィールドをリクエストデータからパッチ
            $tReservationInfo = $this->TReservationInfo->patchEntity($tReservationInfo, $data);

            // 予約情報を保存
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
        date_default_timezone_set('Asia/Tokyo');
        if (!$this->request->is('post')) {
            $this->Flash->error(__('不正なリクエストです。POSTメソッドで送信してください。'));
            return $this->redirect(['action' => 'bulkAddForm']);
        }

        $data = $this->request->getData();
        $user = $this->request->getAttribute('identity');

        if (!$user) {
            $this->Flash->error(__('ユーザー情報が取得できませんでした。'));
            return $this->redirect(['action' => 'bulkAddForm']);
        }

        $twoWeeksAgo = (new DateTime())->modify('-2 weeks');

        foreach ($data['data'] as $date => $meals) {
            $reservationDate = new DateTime($date);

            if ($reservationDate < $twoWeeksAgo) {
                $this->Flash->error(__('過去二週間以前の日付には予約を登録できません。'));
                return $this->redirect(['action' => 'bulkAddForm']);
            }

            foreach ($meals as $mealType => $counts) {
                $mealTypeMapping = [
                    'morning' => 1,
                    'noon' => 2,
                    'night' => 3
                ];

                if (!isset($mealTypeMapping[$mealType])) {
                    $this->Flash->error(__('無効な食事タイプが指定されました。'));
                    return $this->redirect(['action' => 'bulkAddForm']);
                }

                if (!$this->saveReservation($counts, $reservationDate, $mealTypeMapping[$mealType], $user->get('c__user_name'))) {
                    $this->Flash->error(__('予約情報の保存に失敗しました。'));
                    return $this->redirect(['action' => 'bulkAddForm']);
                }
            }
        }

        $this->Flash->success(__('データが正常に保存されました。'));
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
}
