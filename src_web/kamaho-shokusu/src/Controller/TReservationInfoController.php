<?php
declare(strict_types=1);

namespace App\Controller;

use App\Controller\Traits\ReservationBulkActionsTrait;
use App\Controller\Traits\ReservationCopyActionsTrait;
use App\Controller\Traits\ReservationReportActionsTrait;
use Authorization\Exception\ForbiddenException;
use App\Service\BulkReservationFormService;
use Cake\Http\Exception\NotFoundException;
use Cake\Http\Exception\UnauthorizedException;
use Cake\Http\Response;
use Cake\I18n\Date;
use App\Service\ReservationCopyService;
use App\Service\ReservationCalendarService;
use App\Service\ReservationQueryService;
use App\Service\ReservationBulkService;
use App\Service\ReservationReportService;
use App\Service\ReservationWriteService;
use App\Service\ReservationDatePolicy;
use App\Service\ReservationRoomDetailService;
use App\Service\ReservationViewService;
use App\Service\ReservationChangeEditService;
use App\Service\ReservationAddService;
use App\Service\ApiResponseService;
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
    use ReservationBulkActionsTrait;
    use ReservationCopyActionsTrait;
    use ReservationReportActionsTrait;

    protected $MUserGroup;
    protected $MUserInfo;
    protected $MRoomInfo;
    protected $TIndividualReservationInfo;
    protected $TReservationInfo;
    private ReservationCalendarService $calendarService;
    private ReservationQueryService $queryService;
    private ReservationReportService $reportService;
    private ReservationAddService $addService;
    private BulkReservationFormService $bulkFormService;
    private ReservationBulkService $bulkService;
    private ReservationChangeEditService $changeEditService;
    private ReservationRoomDetailService $roomDetailService;
    private ReservationViewService $viewService;
    private ReservationWriteService $writeService;
    private ReservationCopyService $copyService;
    private ReservationDatePolicy $datePolicy;
    protected ApiResponseService $apiResponseService;
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

        $this->calendarService = new ReservationCalendarService();
        $this->datePolicy = new ReservationDatePolicy();
        $this->queryService = new ReservationQueryService($this->datePolicy);
        $this->reportService = new ReservationReportService();
        $this->addService = new ReservationAddService();
        $this->bulkFormService = new BulkReservationFormService();
        $this->bulkService = new ReservationBulkService();
        $this->changeEditService = new ReservationChangeEditService();
        $this->roomDetailService = new ReservationRoomDetailService();
        $this->viewService = new ReservationViewService($this->datePolicy);
        $this->writeService = new ReservationWriteService(
            $this->TIndividualReservationInfo,
            $this->MUserInfo,
            $this->MRoomInfo,
            (string)($this->request->getAttribute('webroot') ?? '')
        );
        $this->copyService = new ReservationCopyService();
        $this->apiResponseService = new ApiResponseService();
        $this->loadComponent('Flash');

        // ★ これがあると全アクションが“勝手に JSON 化”されやすいので外す
        // $this->viewBuilder()->setOption('serialize', true);
        $this->viewBuilder()->setLayout('default');

        if (isset($this->FormProtection)) {
            $this->FormProtection->setConfig('unlockedActions', ['toggle']);
        }
    }


    /**
     * インデックスメソッド
     *
     * @return Response|null|void ビューをレンダリングする
     */
    public function index(): ?Response
    {
        $this->authorizeReservation('index');

        /* ========== 基本情報 ========== */
        $authUser = $this->Authentication->getIdentity();
        if (!$authUser) {
            throw new UnauthorizedException('ログインが必要です。');
        }
        $userId   = $authUser->get('i_id_user');
        $user     = $this->MUserInfo->get($userId);
        $today = Date::today();

        $userRoomIds = $this->calendarService->getUserRoomIds($this->MUserGroup, (int)$userId);
        $userRoomId = $this->calendarService->getPrimaryRoomId($userRoomIds);

        /* ========== ここから: 部屋セレクト用の $rooms を必ず用意 ========== */
        $isAdmin = (int)($user->i_admin ?? 0) === 1;
        $isOfficeUser = $this->calendarService->isOfficeUser($this->MUserGroup, $this->MRoomInfo, (int)$userId);
        $canViewAllRooms = $isAdmin || $isOfficeUser;
        $rooms = $this->calendarService->getRoomsForUser($this->MRoomInfo, $userRoomIds, $isAdmin, $isOfficeUser);
        /* ========== ここまで: $rooms 準備 ========== */

        /* =====================================================
         * ① 部屋フィルタ確定（cal_room_id クエリパラメータ対応）
         * ===================================================== */
        $calRoomIdQuery = $this->request->getQuery('cal_room_id');
        $calRoomId = null;
        if ($calRoomIdQuery !== null && $calRoomIdQuery !== '') {
            $requestedCalRoomId = (int)$calRoomIdQuery;
            if (isset($rooms[$requestedCalRoomId])) {
                $calRoomId = $requestedCalRoomId;
            }
        } elseif (!$canViewAllRooms && $userRoomId !== null && isset($rooms[(int)$userRoomId])) {
            $calRoomId = (int)$userRoomId;
        }
        $calRoomFilter = $calRoomId !== null
            ? [$calRoomId]
            : ((!$canViewAllRooms && !empty($userRoomIds)) ? $userRoomIds : null);

        /* =====================================================
         * ② 全利用者の食数集計（朝昼夜弁当）
         *    部屋フィルタ対応・日付範囲なし（全期間）
         *    15 日より先 → eat_flag、14 日前以内 → i_change_flag
         * ===================================================== */
        $mealDataArray = $this->calendarService->buildMealCountsByDate(
            $this->TIndividualReservationInfo,
            $calRoomFilter
        );

        /* =====================================================
         * ② 自分の予約詳細（朝昼夜弁当）
         * ===================================================== */
        $myReservationDetails = $this->calendarService->buildMyReservationDetails(
            $this->TIndividualReservationInfo,
            (int)$userId
        );
        $myReservationDates = $this->calendarService->buildMyReservationDates($myReservationDetails);
        //職員情報を取得する
        $staff_user = $this->MUserGroup->find()
            ->enableAutoFields(false)
            ->select([
                'user_name' => 'MUserInfo.c_user_name',
                'staff_id' => 'MUserInfo.i_id_staff',
                'is_admin' => 'MUserInfo.i_admin',
            ])
            ->innerJoin(
                ['MUserInfo' => 'm_user_info'],
                ['MUserInfo.i_id_user = MUserGroup.i_id_user']
            )
            ->where(['MUserGroup.i_id_user' => $userId])
            ->enableHydration(false)
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
            'staff_user',
            'isAdmin',
            'canViewAllRooms',
            'calRoomId'
        ));
        return null;
    }
    /**
     * イベントメソッド - FullCalendarで使用するイベントデータを提供する
     * @return Response|null|void JSONレスポンスを返す
     */
    public function events(): ?Response
    {
        if ($denied = $this->authorizeReservation('events', [], true)) {
            return $denied;
        }

        // GET のみ許可（"ajax" は HTTPメソッドではないので allowMethod には入れない）
        $this->request->allowMethod(['get']);

        // 14日前境界を考慮して、実効フラグ(≤14日: i_change_flag, >14日: eat_flag)でカウント
        $events = $this->calendarService->buildTotalEvents($this->TIndividualReservationInfo);

        return $this->apiResponseService->success($this->response, ['events' => $events]);
    }

    /**
     * カレンダー表示用イベント（食数 + 自分の予約 + 未予約）
     *
     * @return Response JSONレスポンス
     */
    public function calendarEvents(): Response
    {
        $resource = $this->TReservationInfo->newEmptyEntity();
        $this->Authorization->authorize($resource, 'calendarEvents');
        $this->request->allowMethod(['get']);

        $authUser = $this->Authentication->getIdentity();
        if (!$authUser) {
            throw new UnauthorizedException('ログインが必要です。');
        }

        $userId = (int)$authUser->get('i_id_user');
        $user   = $this->MUserInfo->get($userId);

        $start = (string)$this->request->getQuery('start');
        $end   = (string)$this->request->getQuery('end');

        try {
            $startDate = new Date($start);
            $endDate   = new Date($end);
        } catch (\Throwable $e) {
            return $this->apiResponseService->error($this->response, 'Invalid date range', 400);
        }

        $isAdmin = (int)($user->i_admin ?? 0) === 1;
        $canViewAllRooms = $isAdmin || $this->calendarService->isOfficeUser($this->MUserGroup, $this->MRoomInfo, (int)$userId);

        $userRoomIds = $this->calendarService->getUserRoomIds($this->MUserGroup, (int)$userId);

        $mealDataArray = $this->calendarService->buildMealCountsByDate(
            $this->TIndividualReservationInfo,
            (!$canViewAllRooms && !empty($userRoomIds)) ? $userRoomIds : null,
            $startDate->format('Y-m-d'),
            $endDate->format('Y-m-d')
        );

        $myReservationDetails = $this->calendarService->buildMyReservationDetails(
            $this->TIndividualReservationInfo,
            (int)$userId,
            $startDate->format('Y-m-d'),
            $endDate->format('Y-m-d')
        );

        $events = $this->calendarService->buildCalendarEvents(
            $mealDataArray,
            $myReservationDetails,
            $startDate,
            $endDate
        );

        return $this->apiResponseService->success($this->response, ['events' => $events]);
    }


    /**
     * ビューメソッド
     *
     * @param string|null $id T Reservation Info id.
     * @return Response|null|void ビューをレンダリングする
     * @throws \Cake\Datasource\Exception\RecordNotFoundException 記録が見つからない場合
     * 管理者および所属部屋のみ詳細閲覧と修正可能
     */
    public function view(): ?Response
    {
        $this->authorizeReservation('view');

        $date = $this->request->getParam('date')
            ?? $this->request->getParam('pass.0')
            ?? $this->request->getQuery('date');
        $context = $this->viewService->buildViewContext(
            $this->request->getAttribute('identity'),
            $date,
            $this->request->getQuery('room_id') !== null ? (int)$this->request->getQuery('room_id') : null,
            $this->MRoomInfo,
            $this->MUserGroup,
            $this->TIndividualReservationInfo,
            $this->queryService
        );

        /* ───────────────────────────────────────
         * ⑤ ビューにデータをセット
         * ─────────────────────────────────────── */
        $this->set($context);
        return null;
    }

    /**
     * 部屋詳細メソッド
     *
     * @param int $roomId 部屋ID
     * @param string $date 日付
     * @param int $mealType 食事タイプ
     * @return Response|null|void ビューをレンダリングする
     * 食べる人と食べない人のリストを表示する→データベースに登録されていない場合は食べない人として表示される
     */
    public function roomDetails($roomId, $date, $mealType): ?Response
    {
        $this->authorizeReservation('roomDetails', ['i_id_room' => (int)$roomId]);

        // パラメータのログ出力
        $this->log("roomId: $roomId, date: $date, mealType: $mealType", 'debug');

        if (empty($roomId) || empty($date) || empty($mealType)) {
            throw new \InvalidArgumentException('部屋ID、日付、または食事タイプが指定されていません。');
        }

        if (!is_numeric($mealType)) {
            throw new \InvalidArgumentException('食事タイプは整数である必要があります。');
        }

        $detail = $this->roomDetailService->getRoomDetails(
            (int)$roomId,
            (string)$date,
            (int)$mealType,
            $this->MRoomInfo,
            $this->TIndividualReservationInfo,
            $this->MUserGroup
        );

        $room = $detail['room'];
        $eatUsers = $detail['eatUsers'];
        $noEatUsers = $detail['noEatUsers'];
        $otherRoomEaters = $detail['otherRoomEaters'];
        $useChangeFlag = $detail['useChangeFlag'];

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
        return null;
    }


    /**
     * 所属しているユーザーを取得するメソッド
     * @param int $roomId
     * @param string $users
     *
     */

    public function getUsersByRoom($roomId = null): ?Response
    {
        if ($denied = $this->authorizeReservation('getUsersByRoom', ['i_id_room' => (int)$roomId], true)) {
            return $denied;
        }

        $this->request->allowMethod(['get']); // AJAXリクエストのみ許可

        if (!$roomId) {
            // 部屋IDが指定されていない場合はエラーメッセージを返す
            return $this->jsonErrorResponse(__('部屋IDが指定されていません。'));
        }

        // クエリパラメータから日付を取得
        $date = $this->request->getQuery('date');

        $usersByRoom = $this->queryService->getUsersByRoom(
            $this->MUserGroup,
            $this->TIndividualReservationInfo,
            (int)$roomId,
            $date
        );


        return $this->apiResponseService->success($this->response, ['usersByRoom' => $usersByRoom]);

    }

    /**
     * 個人予約情報を取得するメソッド
     * このメソッドは、ログイン中のユーザーの個人予約情報を取得し、指定された日付に基づいて食事タイプごとの予約状況を返します。
     * @return Response JSON形式で予約情報を返す
     *
     */
    public function getPersonalReservation(): ?Response
    {
        if ($denied = $this->authorizeReservation('getPersonalReservation', [], true)) {
            return $denied;
        }

        $this->autoRender = false;
        $this->viewBuilder()->disableAutoLayout();

        // GET リクエストのみ許可
        $this->request->allowMethod(['get']);

        // クエリパラメータから日付を取得
        $date = $this->request->getQuery('date');
        if (empty($date)) {
            return $this->apiResponseService->error($this->response, '日付が指定されていません。', 400);
        }

        // ログイン中のユーザーを取得
        $user = $this->Authentication->getIdentity();
        if (!$user) {
            return $this->apiResponseService->error($this->response, 'ログイン情報がありません。', 403);
        }
        $userId   = $user->get('i_id_user');
        $userName = $user->get('c_user_name');

        $output = $this->queryService->getPersonalReservationData(
            $this->TIndividualReservationInfo,
            $this->MRoomInfo,
            $this->MUserGroup,
            (int)$userId,
            (string)$userName,
            (string)$date
        );

        return $this->apiResponseService->success($this->response, $output);
    }




    /**
     * ユーザーが予約可能な部屋を取得するメソッド
     * @param int $userId ユーザーID
     * @return array 予約可能な部屋のリスト
     * このメソッドは、指定されたユーザーIDに基づいて、ユーザーが所属する部屋の情報を取得します。
     */

    // getAuthorizedRooms は ReservationQueryService に移動

    /**
     * 重複予約のチェックを行うメソッド
     * @return Response JSON形式で重複予約の有無を返す
     * このメソッドは、指定された日付、部屋ID、および予約タイプに基づいて、既存の予約と重複するかどうかを確認します。
     */
    public function checkDuplicateReservation(): ?Response
    {
        $roomId = (int)($this->request->getData('i_id_room') ?? 0);
        if ($denied = $this->authorizeReservation('checkDuplicateReservation', ['i_id_room' => $roomId], true)) {
            return $denied;
        }

        $this->request->allowMethod(['post']);

        $data = $this->request->getData();

        // 必須フィールドの検証
        if (empty($data['d_reservation_date']) || empty($data['i_id_room']) || empty($data['reservation_type'])) {
            return $this->apiResponseService->error(
                $this->response,
                '必要なデータが不足しています。',
                400,
                [
                    'isDuplicate' => false,
                ]
            );
        }

        $isDuplicate = $this->queryService->hasDuplicateReservation(
            $this->TIndividualReservationInfo,
            (string)$data['d_reservation_date'],
            (int)$data['i_id_room'],
            (int)$data['reservation_type']
        );

        if ($isDuplicate) {
            $editUrl = Router::url([
                'controller' => 'TReservationInfo',
                'action' => 'edit',
                'roomId' => $data['i_id_room'],
                'date' => $data['d_reservation_date'],
                'mealType' => $data['reservation_type'],
            ]);


            return $this->apiResponseService->success($this->response, [
                    'isDuplicate' => true,
                    'editUrl' => $editUrl,
                ]);
        }


        return $this->apiResponseService->success($this->response, ['isDuplicate' => false]);
    }


    /**
     * 予約の追加メソッド(日付ごとの個人予約またはグループ予約を追加)
     *
     * @return Response|null|void ビューをレンダリングする
     * ユーザーが新しい予約を追加するためのメソッドです。
     * ユーザーの権限に基づいて、個人予約またはグループ予約を処理します。
     */

    public function add(): ?Response
    {
        $this->authorizeReservation('add');

        $user    = $this->request->getAttribute('identity');
        $isModal = ($this->request->getQuery('modal') === '1');
        $addService = $this->addService;

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
            return null;
        }

        $userLevel = $user->i_user_level;
        $userId    = $user->get('i_id_user');
        $rooms     = $this->queryService->getAuthorizedRooms(
            $this->MRoomInfo,
            $this->MUserGroup,
            $userId
        );

        if ($this->request->is('get')) {
            $validation = $addService->validateDate((string)$date, $this->datePolicy);
            $roomList = $addService->buildRoomList($this->MRoomInfo);

            $tReservationInfo = $this->TReservationInfo->newEmptyEntity();

            $this->set(compact('rooms', 'tReservationInfo', 'date', 'roomList', 'userLevel'));

            if ($validation !== true) {
                $this->set('errorMessage', __($validation));
            } elseif (!$roomList) {
                $this->set('errorMessage', __('部屋が見つかりません。'));
            }

            return null;
        }

        $validation = $addService->validateDate((string)$date, $this->datePolicy);
        if ($validation !== true) {
            $msg = __((string)$validation);
            if ($this->request->is('ajax')) {
                return $this->jsonErrorResponse($msg, 422);
            }
            $this->Flash->error($msg);
            // ★ 配列ルートで指定（ベース重複しない）
            return $this->redirect(['action' => 'index', '?' => ['date' => $date]]);
        }

        $roomList = $addService->buildRoomList($this->MRoomInfo);

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
            $data = $addService->ensureReservationDate($this->request->getData(), (string)$date);

            $conflict = $addService->validateLunchBento($data);
            if ($conflict) {
                if ($this->request->is('ajax')) {
                    return $this->jsonErrorResponse(__($conflict), 409);
                }
                $this->Flash->error(__($conflict));
                // ★ 配列ルートで指定
                return $this->redirect(['action' => 'index', '?' => ['date' => $data['d_reservation_date']]]);
            }

            $reservationType = $data['reservation_type'] ?? '1';
            $result = ((string)$reservationType === '1')
                ? $this->writeService->processIndividualReservation(
                    $data['d_reservation_date'],
                    $data,
                    $rooms,
                    (int)$userId,
                    (string)$user->get('c_user_name'),
                    fn($d) => $this->datePolicy->validateReservationDate((string)$d)
                )
                : $this->writeService->processGroupReservation(
                    $data['d_reservation_date'],
                    $data,
                    $rooms,
                    (string)$user->get('c_user_name'),
                    fn($d) => $this->datePolicy->validateReservationDate((string)$d)
                );

            $resultResponse = $result['ok']
                ? $this->jsonSuccessResponse($result['message'], $result['data'] ?? [], $result['redirect'] ?? null)
                : $this->jsonErrorResponse($result['message'], $result['status'] ?? 400, $result['data'] ?? []);

            // ★ ここからが重要：非AJAXでは「常にサーバ側で配列ルート→redirect()」に集約
            if ($this->request->is('ajax')) {
                return $resultResponse instanceof Response ? $resultResponse : $this->response;
            }

            // 成功時の既定遷移先（配列ルートのみを使う）
            $defaultRedirect = ['action' => 'index', '?' => ['date' => $data['d_reservation_date']]];

            if ($resultResponse instanceof Response) {
                $ctype = $resultResponse->getType() ?? '';
                if (stripos($ctype, 'application/json') !== false) {
                    // JSON を返してくる実装でも、非AJAXでは必ずここで食べてサーバリダイレクトに変換する
                    $body = (string)$resultResponse->getBody();
                    $json = null;
                    try {
                        $json = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
                    } catch (\Throwable $e) {
                        $this->log('Failed to decode add action JSON response: ' . $e->getMessage(), 'warning');
                    }

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
                    $this->log('JSON decode failed for add result response.', 'warning');
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
    public function copy(): ?Response
    {
        return $this->runCopy();
    }

    /**
     * 予約コピーのプレビュー（件数のみ取得）
     */
    public function copyPreview(): ?Response
    {
        return $this->runCopyPreview();
    }

    /**
     * エラーレスポンスをJSON形式で返す
     *
     * @param string $message エラーメッセージ
     * @param array $data 追加データ
     * @return Response JSONレスポンス
     */


    /**
     * 成功レスポンスをJSON形式で返す
     *
     * @param string $message 成功メッセージ
     * @param array $data 追加データ
     * @param string|null $redirect リダイレクト先URL
     * @return Response JSONレスポンス
     */
    protected function jsonErrorResponse(string $message, int $status = 400, array $data = [])
    {
        return $this->apiResponseService->error($this->response, $message, $status, $data);
    }

    protected function jsonSuccessResponse(string $message, array $data = [], ?string $redirect = null, int $status = 200)
    {
        if ($redirect !== null && $redirect !== '') {
            $data['redirect'] = $redirect;
        }
        return $this->apiResponseService->success($this->response, $data, $message, $status);
    }


    public function getUsersByRoomForBulk($roomId): ?Response
    {
        if ($denied = $this->authorizeReservation('getUsersByRoomForBulk', ['i_id_room' => (int)$roomId], true)) {
            return $denied;
        }

        return $this->runGetUsersByRoomForBulk($roomId);
    }

    public function getReservationSnapshots(): ?Response
    {
        $roomId = (int)($this->request->getData('room_id') ?? 0);
        if ($denied = $this->authorizeReservation('getReservationSnapshots', ['i_id_room' => $roomId], true)) {
            return $denied;
        }

        return $this->runGetReservationSnapshots();
    }


    /**
     * 一括登録に必要なフォームを作成するメソッド
     *
     * 日付をクエリパラメータから取得し、週の月曜日から金曜日までの日付を生成します。
     * 自分が所属している部屋しか表示できないように設計されている。
     *
     * @return Response|void|null リダイレクトまたはビューのレンダリング
     * @throws \DateMalformedStringException 無効な日付形式の場合
     */
    public function bulkAddForm(): ?Response
    {
        $this->authorizeReservation('bulkAddForm');

        return $this->runBulkAddForm();
    }

    /**
     * 直前編集の一括画面（ExcelライクUI）
     *
     * @return Response|void|null
     */
    public function bulkChangeEditForm(): ?Response
    {
        $this->authorizeReservation('bulkChangeEditForm');

        return $this->runBulkChangeEditForm();
    }

    /**
     * 直前編集（一括）送信
     *
     * day_users[date][userId][mealType] = 1 を受け取り i_change_flag を更新する
     */
    public function bulkChangeEditSubmit(): ?Response
    {
        return $this->runBulkChangeEditSubmit();
    }

    /**
     * @return Response
     * 一括登録のフォームから送信されたデータを処理するメソッド
     * 予約タイプ（個人予約 or 集団予約）を選択し、各日付と食事タイプに対して登録を行います。
     * 個人予約の場合は、ユーザーごとに日付と食事タイプを選択し、登録済みの予約がないか確認します。→登録済みの場合は登録をスキップします
     * 集団予約の場合は、日付ごとにユーザーと食事タイプを選択し、登録済みの予約がないか確認します。→登録済みの場合は登録をスキップします
     *
     */
    public function bulkAddSubmit(): ?Response
    {
        return $this->runBulkAddSubmit();
    }

    /**
     * Backward-compatible wrapper for date validation used by legacy tests.
     */
    private function validateReservationDate(?string $reservationDate): string|bool
    {
        return $this->datePolicy->validateReservationDate($reservationDate);
    }

    /**
     * Format duplicate reservation rows into a readable message.
     *
     * @param array<int, array<string, mixed>> $duplicates
     */
    private function formatDuplicateMessage(array $duplicates): string
    {
        if (empty($duplicates)) {
            return '重複した予約はありません。';
        }

        $parts = [];
        foreach ($duplicates as $row) {
            $userName = (string)($row['user_name'] ?? '不明ユーザー');
            $mealType = (string)($row['meal_type'] ?? '不明');
            $roomName = (string)($row['room_name'] ?? '不明な部屋');
            $parts[] = sprintf('%s（%s・%s）', $userName, $mealType, $roomName);
        }

        return '既に予約済みのためスキップ: ' . implode('、', $parts);
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
    public function changeEdit($roomId = null, $date = null, $mealType = null): ?Response
    {
        $this->authorizeReservation('changeEdit');

        // 応答種別（try/catch の両スコープで参照するため外側で定義する）
        $wantsJson =
            $this->request->is('ajax') ||
            $this->request->getQuery('ajax') === '1' || // ★ クエリで強制的に JSON を要求
            $this->request->accepts('application/json') ||
            $this->request->getParam('_ext') === 'json';

        try {
            // ---- パラメータ補完（route/query/POST 両対応）----
            $roomId   = $roomId   ?? $this->request->getParam('roomId')   ?? $this->request->getQuery('roomId')   ?? $this->request->getData('i_id_room');
            $date     = $date     ?? $this->request->getParam('date')     ?? $this->request->getQuery('date')     ?? $this->request->getData('d_reservation_date');
            // ALL モード固定（モーダルで 4 食種まとめ）

            // "モーダルの殻"GET（/TReservationInfo/changeEdit?modal=1 ...）を許容
            $isModalShell = $this->request->is('get') && ((string)$this->request->getQuery('modal') === '1');

            // ログインユーザー
            $loginUser = $this->request->getAttribute('identity');
            $loginUid  = $loginUser?->get('i_id_user');

            // ---- 選択可能な部屋を「ログインユーザーの所属部屋のみに制限」----
            // 管理者であっても"全部屋"は出しません。
            $changeEditService = $this->changeEditService;
            $allowedRooms = $changeEditService->getAllowedRooms(
                $loginUser,
                $roomId ? (int)$roomId : null,
                $this->MUserGroup,
                $this->MRoomInfo
            );

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
                // 部屋が空の場合は警告ログ
                if (empty($rooms)) {
                    $this->log('changeEdit modal: No rooms available for user ' . $loginUid, 'warning');
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
                        ->withStringBody(json_encode([
                            'ok' => false,
                            'status' => 'error',
                            'message' => 'この部屋を操作する権限がありません。',
                            'data' => [],
                        ], JSON_UNESCAPED_UNICODE));
                }
                $this->Flash->error(__('この部屋を操作する権限がありません。'));
                return $this->redirect(['action' => 'index']);
            }

            // 直前編集の当日制限は解除（過去日/当日も編集可能にする）

            $context = $changeEditService->buildContext(
                (int)$roomId,
                (string)$date,
                $this->MRoomInfo,
                $this->MUserGroup,
                $this->MUserInfo,
                $this->TIndividualReservationInfo
            );
            $room = $context['room'];
            $users = $context['users'];
            $userReservations = $context['userReservations'];
            $userIdList = $context['userIdList'];

            // ---- GET: JSON/HTML ----
            if ($this->request->is('get')) {
                if ($wantsJson) {
                    $usersForJson = $changeEditService->buildUsersForJson($users, $loginUser);

                    return $this->response->withType('application/json')
                        ->withStringBody(json_encode([
                            'ok' => true,
                            'status' => 'success',
                            'message' => null,
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

                try {
                    $result = $changeEditService->processUpdate(
                        $usersData,
                        $userIdList,
                        (string)$date,
                        (int)$roomId,
                        $loginUser,
                        $this->TIndividualReservationInfo,
                        $this->MUserInfo
                    );

                    $updated = $result['updated'] ?? [];
                    $created = $result['created'] ?? [];
                    $skipped = $result['skipped'] ?? [];

                    $payload = [
                        'ok'      => true,
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
                    $this->log('直前編集エラー: '.$e->getMessage(), 'error');
                    if ($wantsJson) {
                        return $this->response->withStatus(500)->withType('application/json')
                            ->withStringBody(json_encode([
                                'ok' => false,
                                'status' => 'error',
                                'message' => '直前予約の更新中にエラーが発生しました。',
                                'data' => [],
                            ], JSON_UNESCAPED_UNICODE));
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
                        'ok' => false,
                        'status'  => 'error',
                        'message' => $e->getMessage() ?: '直前予約の取得中にエラーが発生しました。',
                        'data' => [],
                    ], JSON_UNESCAPED_UNICODE));
            }

            // 通常アクセスなら今まで通り Cake に投げる
            throw $e;
        }
    }


    public function getMealCounts($date): ?Response
    {
        return $this->runGetMealCounts($date);
    }


    public function getUsersByRoomForEdit($roomId): ?Response
    {
        if ($denied = $this->authorizeReservation('getUsersByRoomForEdit', ['i_id_room' => (int)$roomId], true)) {
            return $denied;
        }

        return $this->runGetUsersByRoomForEdit($roomId);
    }

    /**
     * JSON形式で予約情報をエクスポートするメソッド
     * Json形式で指定された月の予約情報をエクスポートします。
     * @return Response JSONレスポンス
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
    public function exportJson(): Response
    {
        if ($denied = $this->authorizeReservation('exportJson', [], true)) {
            return $denied;
        }

        return $this->runExportJson();
    }

    /**
     * JSON形式でランク別の予約情報をエクスポートするメソッド
     * 指定された月のランク別予約情報をJSON形式でエクスポートします。
     *
     * @return Response JSONレスポンス
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
    public function exportJsonrank(): Response
    {
        if ($denied = $this->authorizeReservation('exportJsonrank', [], true)) {
            return $denied;
        }

        return $this->runExportJsonrank();
    }

    /**
     * 予約トグルAPI（個人が自分の1日1食区分をON/OFF）
     * 既存の Table 側に toggleMeal(...) が実装済みである前提です。
     * 14日前ルールやeat/changeの扱いは Table 側の実装に委譲します。
     */
    public function toggle(?int $roomId = null): ?Response
    {
        $this->request->allowMethod(['post']);
        $this->response = $this->response->withType('application/json');

        // ペイロード（form→JSON）
        $payload = (array)$this->request->getData();
        if (empty($payload)) {
            $payload = (array)($this->request->input('json_decode', true) ?? []);
        }
        if ($roomId === null) {
            $roomId = isset($payload['roomId']) ? (int)$payload['roomId'] : (int)($payload['i_id_room'] ?? 0);
        }
        if ($roomId <= 0) {
            return $this->apiResponseService->error($this->response, 'roomId is required.', 400);
        }
        $targetUserId = isset($payload['userId']) ? (int)$payload['userId'] : 0;

        if ($denied = $this->authorizeReservation('toggle', [
            'i_id_room' => (int)$roomId,
            'i_id_user' => $targetUserId,
        ], true)) {
            return $denied;
        }

        // 認証ユーザー
        $loginUser = $this->request->getAttribute('identity');
        $loginUserId   = (int)($loginUser?->get('i_id_user') ?? $loginUser?->get('id') ?? 0);
        $loginUserName = (string)($loginUser?->get('c_login_account') ?? $loginUser?->get('c_user_name') ?? $loginUserId);
        if ($loginUserId <= 0) {
            return $this->apiResponseService->error($this->response, 'Unauthorized', 401);
        }

        $result = $this->writeService->processToggle(
            roomId: $roomId,
            payload: $payload,
            loginUserId: $loginUserId,
            loginUserName: $loginUserName
        );

        $status = (int)($result['status'] ?? 200);
        $body = (array)($result['body'] ?? []);
        $ok = (bool)($body['ok'] ?? ($status >= 200 && $status < 300));
        $message = (string)($body['message'] ?? '');
        $data = $body;
        unset($data['ok'], $data['message']);

        if ($ok) {
            return $this->apiResponseService->success($this->response, $data, $message !== '' ? $message : null, $status);
        }

        return $this->apiResponseService->error(
            $this->response,
            $message !== '' ? $message : '処理に失敗しました。',
            $status,
            $data
        );
    }

    public function reportNoMeal(): ?Response
    {
        if ($denied = $this->authorizeReservation('reportNoMeal', [], true)) {
            return $denied;
        }

        return $this->runReportNoMeal();
    }

    public function reportEat(): ?Response
    {
        if ($denied = $this->authorizeReservation('reportEat', [], true)) {
            return $denied;
        }

        return $this->runReportEat();
    }

    /**
     * 全部屋食数取得API（管理者用）
     * 管理者が全部屋の食数をカレンダーに表示するために使用
     * 
     * @return Response JSONレスポンス
     */
    public function getAllRoomsMealCounts(): ?Response
    {
        if ($denied = $this->authorizeReservation('getAllRoomsMealCounts', [], true)) {
            return $denied;
        }

        return $this->runGetAllRoomsMealCounts();
    }

    /**
     * 部屋別食数取得API（職員用）
     * 職員が自分の所属部屋の食数をカレンダーに表示するために使用
     * 
     * @param string $roomId 部屋ID
     * @return Response JSONレスポンス
     */
    public function getRoomMealCounts($roomId = null): ?Response
    {
        if ($denied = $this->authorizeReservation('getRoomMealCounts', ['i_id_room' => (int)$roomId], true)) {
            return $denied;
        }

        return $this->runGetRoomMealCounts($roomId);
    }
    /* =============================================================== */
    /*  実食確認管理（大人限定）                                         */
    /* =============================================================== */

    /**
     * 実食確認管理画面を表示する（大人限定）。
     *
     * 対象: i_id_staff を保持する大人ユーザーのみ。
     * 表示: 週単位グリッド（朝・昼・夜 × ユーザー × 7日間）。
     * 管理者: 過去2ヶ月まで遡って編集可能。
     *
     * @return Response|null
     */
    public function actualMealManagement(): ?Response
    {
        $this->authorizeReservation('actualMealManagement');

        $authUser = $this->Authentication->getIdentity();
        if (!$authUser) {
            throw new \Cake\Http\Exception\UnauthorizedException('ログインが必要です。');
        }

        $userId  = (int)$authUser->get('i_id_user');
        $isAdmin = (int)($authUser->get('i_admin') ?? 0) === 1;
        $isOfficeUser = $this->calendarService->isOfficeUser($this->MUserGroup, $this->MRoomInfo, $userId);
        $canViewAllRooms = $isAdmin || $isOfficeUser;

        // 部屋リスト
        $userRoomIds = $this->calendarService->getUserRoomIds($this->MUserGroup, $userId);
        $rooms       = $this->calendarService->getRoomsForUser($this->MRoomInfo, $userRoomIds, $isAdmin, $isOfficeUser);

        // 選択部屋
        $selectedRoomId = $this->request->getQuery('room_id')
            ? (int)$this->request->getQuery('room_id')
            : (!empty($rooms) ? (int)array_key_first($rooms) : null);
        if ($selectedRoomId !== null && !isset($rooms[$selectedRoomId])) {
            $selectedRoomId = !empty($rooms) ? (int)array_key_first($rooms) : null;
        }

        // 週パラメータ: デフォルトは今週月曜
        $today = new \DateTimeImmutable('now', new \DateTimeZone('Asia/Tokyo'));
        $defaultMonday = $today->modify('monday this week')->format('Y-m-d');
        $weekParam = $this->request->getQuery('week') ?? $defaultMonday;

        try {
            $weekDate = new \DateTimeImmutable($weekParam, new \DateTimeZone('Asia/Tokyo'));
        } catch (\Throwable $e) {
            $weekDate = new \DateTimeImmutable($defaultMonday, new \DateTimeZone('Asia/Tokyo'));
        }
        // 常に月曜日に正規化する
        $weekMonday = (int)$weekDate->format('N') === 1
            ? $weekDate
            : $weekDate->modify('monday this week');

        // 管理者の編集可能最古週
        $service = new \App\Service\ActualMealManagementService();
        $oldestMonday = $isAdmin ? $service->getAdminOldestAllowedMonday() : $today->modify('monday this week');

        // 週の範囲チェック（管理者以外は現在週のみ）
        $futureMonday = $today->modify('monday this week')->modify('+4 weeks');
        if (!$isAdmin && $weekMonday < $today->modify('monday this week')) {
            $weekMonday = new \DateTimeImmutable($defaultMonday, new \DateTimeZone('Asia/Tokyo'));
        }
        if ($weekMonday < $oldestMonday) {
            $weekMonday = $oldestMonday;
        }
        if ($weekMonday > $futureMonday) {
            $weekMonday = $futureMonday;
        }

        $weekMondayStr = $weekMonday->format('Y-m-d');

        // 大人ユーザーリストとグリッドデータ
        $adultUsers = [];
        $gridData   = ['dates' => [], 'meals' => [], 'grid' => [], 'versions' => []];

        if ($selectedRoomId) {
            $adultUsers = $service->getAdultUsers($this->MUserGroup, $this->MUserInfo, $selectedRoomId);
            $gridData   = $service->buildWeekGrid($this->TIndividualReservationInfo, $adultUsers, $weekMondayStr);
        }

        // 前週・次週の月曜日
        $prevMonday = $weekMonday->modify('-7 days');
        $nextMonday = $weekMonday->modify('+7 days');

        // 前週ナビが使えるか（最古週より前はNG）
        $canGoPrev = $isAdmin && $prevMonday >= $oldestMonday;
        $canGoNext = $nextMonday <= $futureMonday;

        $this->set(compact(
            'rooms',
            'selectedRoomId',
            'adultUsers',
            'gridData',
            'weekMondayStr',
            'prevMonday',
            'nextMonday',
            'canGoPrev',
            'canGoNext',
            'isAdmin'
        ));

        return null;
    }

    /**
     * 実食実績を保存する（POST）。
     *
     * リクエストボディ:
     *   - room_id     : int
     *   - date        : YYYY-MM-DD
     *   - meal_type   : 1|2|3
     *   - user_id     : int
     *   - checked     : 1|0
     *   - version     : int （楽観的ロック）
     *
     * @return Response JSON
     */
    public function actualMealSave(): ?Response
    {
        if ($denied = $this->authorizeReservation('actualMealSave', [], true)) {
            return $denied;
        }

        $this->request->allowMethod(['post']);

        $data       = $this->request->getData();
        $roomId     = (int)($data['room_id']   ?? 0);
        $date       = (string)($data['date']       ?? '');
        $mealType   = (int)($data['meal_type'] ?? 0);
        $targetUid  = (int)($data['user_id']   ?? 0);
        $checked    = (bool)((int)($data['checked'] ?? 0));
        $version    = (int)($data['version']   ?? 1);

        if (!$roomId || !$date || !$mealType || !$targetUid) {
            return $this->apiResponseService->error($this->response, '必要なパラメータが不足しています。', 400);
        }

        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            return $this->apiResponseService->error($this->response, '日付の形式が正しくありません。', 400);
        }

        $authUser = $this->Authentication->getIdentity();
        $actor    = (string)($authUser?->get('c_user_name') ?? 'system');

        $service = new \App\Service\ActualMealManagementService();
        $result  = $service->saveActualMeal(
            $this->TIndividualReservationInfo,
            $targetUid,
            $roomId,
            $date,
            $mealType,
            $checked,
            $version,
            $actor
        );

        if (!$result['ok']) {
            return $this->apiResponseService->error($this->response, $result['message'], 409);
        }

        return $this->apiResponseService->success($this->response, [], $result['message']);
    }

    /* =============================================================== */
    /*  以下はこのコントローラ内に配置する簡易レスポンスヘルパーメソッド  */
    /* =============================================================== */

    private function authorizeReservation(string $action, array $context = [], bool $asJson = false): ?Response
    {
        $resource = $this->TReservationInfo->newEmptyEntity();
        if ($context) {
            $resource->set($context, ['guard' => false]);
        }
        try {
            $this->Authorization->authorize($resource, $action);
            return null;
        } catch (ForbiddenException $e) {
            if (!$asJson) {
                throw $e;
            }
        }

        return $this->apiResponseService->forbidden($this->response);
    }
}