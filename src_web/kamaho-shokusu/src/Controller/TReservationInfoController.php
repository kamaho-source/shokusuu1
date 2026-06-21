<?php
declare(strict_types=1);

namespace App\Controller;

use App\Domain\ValueObject\UserRole;
use Cake\Http\Exception\NotFoundException;
use Cake\Http\Exception\UnauthorizedException;
use Cake\Http\Response;
use Cake\I18n\Date;
use App\Service\ReservationWriteService;
use App\Service\ReservationRoomDetailService;
use App\Service\ReservationViewService;
use App\Exception\OptimisticLockConflictException;
use App\Service\ReservationChangeEditService;
use App\Service\ReservationAddService;
use Cake\Routing\Router;

/**
 * TReservationInfo コントローラー（コア予約CRUD・カレンダー表示担当）。
 *
 * 以下の責務を持つ:
 * - カレンダーIndex・イベント取得
 * - 日別ビュー・部屋詳細
 * - 個人/集団予約の新規登録 (add)
 * - 直前編集 (changeEdit)
 * - 部屋別利用者取得・個人予約取得・重複チェック
 *
 * 一括・コピー・レポート・実食管理は各専用コントローラーへ分離済み。
 */
class TReservationInfoController extends ReservationBaseController
{
    private ReservationAddService $addService;
    private ReservationChangeEditService $changeEditService;
    private ReservationRoomDetailService $roomDetailService;
    private ReservationViewService $viewService;
    private ReservationWriteService $writeService;

    /**
     * initialize メソッド
     */
    public function initialize(): void
    {
        parent::initialize();

        $this->addService        = new ReservationAddService();
        $this->changeEditService = new ReservationChangeEditService();
        $this->roomDetailService = new ReservationRoomDetailService();
        $this->viewService       = new ReservationViewService($this->datePolicy);
        $this->writeService      = new ReservationWriteService(
            $this->TIndividualReservationInfo,
            $this->MUserInfo,
            $this->MRoomInfo,
            (string)($this->request->getAttribute('webroot') ?? '')
        );

        $this->FormProtection->setConfig('unlockedActions', [
            'add',
            'checkDuplicateReservation',
            'changeEdit',
            'view',
        ]);
    }


    /**
     * インデックスメソッド
     *
     * @return Response|null|void ビューをレンダリングする
     */
    public function index(): ?Response
    {
        $this->authorizeReservation('index');

        $authUser = $this->Authentication->getIdentity();
        if (!$authUser) {
            throw new UnauthorizedException('ログインが必要です。');
        }
        $userId   = $authUser->get('i_id_user');
        $user     = $this->MUserInfo->get($userId);
        $today    = Date::today();

        $userRoomIds = $this->calendarService->getUserRoomIds($this->MUserGroup, (int)$userId);
        $userRoomId  = $this->calendarService->getPrimaryRoomId($userRoomIds);

        $isAdmin       = UserRole::isAdmin((int)($user->i_admin ?? 0));
        $isOfficeUser  = $this->calendarService->isOfficeUser($this->MUserGroup, $this->MRoomInfo, (int)$userId);
        $canViewAllRooms = $isAdmin || $isOfficeUser;
        $rooms         = $this->calendarService->getRoomsForUser($this->MRoomInfo, $userRoomIds, $isAdmin, $isOfficeUser);

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

        $mealDataArray = $this->calendarService->buildMealCountsByDate(
            $this->TIndividualReservationInfo,
            $calRoomFilter
        );

        $myReservationDetails = $this->calendarService->buildMyReservationDetails(
            $this->TIndividualReservationInfo,
            (int)$userId
        );
        $myReservationDates = $this->calendarService->buildMyReservationDates($myReservationDetails);
        $staff_user = $this->calendarService->getStaffUserInfo($this->MUserGroup, (int)$userId);

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
     *
     * @return Response|null|void JSONレスポンスを返す
     */
    public function events(): ?Response
    {
        if ($denied = $this->authorizeReservation('events', [], true)) {
            return $denied;
        }

        $this->request->allowMethod(['get']);

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
        } catch (\Throwable) {
            return $this->apiResponseService->error($this->response, 'Invalid date range', 400);
        }

        $diffDays = $startDate->diffInDays($endDate, false);
        if ($diffDays < 0) {
            return $this->apiResponseService->error($this->response, 'Invalid date range', 400);
        }

        if ($diffDays > 366) {
            return $this->apiResponseService->error($this->response, 'Date range too large', 400);
        }

        $isAdmin = UserRole::isAdmin((int)($user->i_admin ?? 0));
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
     * @return Response|null|void ビューをレンダリングする
     */
    public function view(): ?Response
    {
        $this->authorizeReservation('view');

        $date       = $this->request->getParam('date')
            ?? $this->request->getParam('pass.0')
            ?? $this->request->getQuery('date');
        $roomIdRaw  = $this->request->getData('room_id') ?? $this->request->getQuery('room_id');
        $context    = $this->viewService->buildViewContext(
            $this->request->getAttribute('identity'),
            $date,
            $roomIdRaw !== null ? (int)$roomIdRaw : null,
            $this->MRoomInfo,
            $this->MUserGroup,
            $this->TIndividualReservationInfo,
            $this->queryService
        );

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
     */
    public function roomDetails($roomId, $date, $mealType): ?Response
    {
        $this->authorizeReservation('roomDetails', ['i_id_room' => (int)$roomId]);

        $this->log(sprintf('roomId: %d, date: %s, mealType: %d', (int)$roomId, preg_replace('/[\r\n\t]/', '', (string)$date), (int)$mealType), 'debug');

        if (empty($roomId) || empty($date) || empty($mealType)) {
            throw new \InvalidArgumentException('部屋ID、日付、または食事タイプが指定されていません。');
        }

        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$date)) {
            throw new \InvalidArgumentException('日付の形式が正しくありません。');
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

        $this->set([
            'room'            => $detail['room'],
            'date'            => $date,
            'mealType'        => $mealType,
            'eatUsers'        => $detail['eatUsers'],
            'noEatUsers'      => $detail['noEatUsers'],
            'otherRoomEaters' => $detail['otherRoomEaters'],
            'useChangeFlag'   => $detail['useChangeFlag'],
        ]);
        return null;
    }

    /**
     * 所属部屋の利用者一覧取得API。
     *
     * @param int|null $roomId
     * @return Response|null
     */
    public function getUsersByRoom($roomId = null): ?Response
    {
        if ($denied = $this->authorizeReservation('getUsersByRoom', ['i_id_room' => (int)$roomId], true)) {
            return $denied;
        }

        $this->request->allowMethod(['get']);

        if (!$roomId) {
            return $this->jsonErrorResponse(__('部屋IDが指定されていません。'));
        }

        $date        = $this->request->getQuery('date');
        $usersByRoom = $this->queryService->getUsersByRoom(
            $this->MUserGroup,
            $this->TIndividualReservationInfo,
            (int)$roomId,
            $date
        );

        return $this->apiResponseService->success($this->response, ['usersByRoom' => $usersByRoom]);
    }

    /**
     * 個人予約情報取得API。
     *
     * @return Response
     */
    public function getPersonalReservation(): ?Response
    {
        if ($denied = $this->authorizeReservation('getPersonalReservation', [], true)) {
            return $denied;
        }

        $this->autoRender = false;
        $this->viewBuilder()->disableAutoLayout();
        $this->request->allowMethod(['get']);

        $date = $this->request->getQuery('date');
        if (empty($date)) {
            return $this->apiResponseService->error($this->response, '日付が指定されていません。', 400);
        }

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
     * 重複予約チェックAPI。
     *
     * @return Response|null
     */
    public function checkDuplicateReservation(): ?Response
    {
        $roomId = (int)($this->request->getData('i_id_room') ?? 0);
        if ($denied = $this->authorizeReservation('checkDuplicateReservation', ['i_id_room' => $roomId], true)) {
            return $denied;
        }

        $this->request->allowMethod(['post']);

        $data = $this->request->getData();

        if (empty($data['d_reservation_date']) || empty($data['i_id_room']) || empty($data['reservation_type'])) {
            return $this->apiResponseService->error(
                $this->response,
                '必要なデータが不足しています。',
                400,
                ['isDuplicate' => false]
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
                'action'     => 'edit',
                'roomId'     => $data['i_id_room'],
                'date'       => $data['d_reservation_date'],
                'mealType'   => $data['reservation_type'],
            ]);

            return $this->apiResponseService->success($this->response, [
                'isDuplicate' => true,
                'editUrl'     => $editUrl,
            ]);
        }

        return $this->apiResponseService->success($this->response, ['isDuplicate' => false]);
    }

    /**
     * 予約登録メソッド（個人予約・集団予約）。
     */
    public function add()
    {
        $this->authorizeReservation('add');

        $user       = $this->request->getAttribute('identity');
        $isModal    = ($this->request->getQuery('modal') === '1');
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
            $roomList   = $addService->buildRoomList($this->MRoomInfo);

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
            return $this->redirect(['action' => 'index', '?' => ['date' => $date]]);
        }

        $roomList = $addService->buildRoomList($this->MRoomInfo);

        if (!$roomList) {
            if ($this->request->is('ajax')) {
                return $this->jsonErrorResponse(__('部屋が見つかりません。'), 404);
            }
            $this->Flash->error(__('部屋が見つかりません。'));
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
                return $this->redirect(['action' => 'index', '?' => ['date' => $data['d_reservation_date']]]);
            }

            $reservationType = $data['reservation_type'] ?? '1';
            $auditSuccess = 0;
            try {
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
                        fn($d) => $this->datePolicy->validateReservationDate((string)$d),
                        (int)$userId,
                        UserRole::isAdmin((int)$user->get('i_admin')),
                        (int)$user->get('i_user_level')
                    );
                $auditSuccess = 1;
                $resultResponse = $this->jsonSuccessResponse($result['message'], $result['data'] ?? [], $result['redirect'] ?? null);
            } catch (\App\Domain\Exception\DomainException $e) {
                $resultResponse = $this->jsonErrorResponse($e->getMessage(), $e->getStatusCode());
            } catch (\Throwable $e) {
                $this->log('予約登録エラー: ' . $e->getMessage(), 'error');
                $resultResponse = $this->jsonErrorResponse('内部エラーが発生しました。', 500);
            }

            \App\Service\AuditLogService::record(
                'reservation',
                (string)$reservationType === '1' ? 'reservation_individual_save' : 'reservation_group_save',
                (string)$user->get('c_user_name'),
                (int)$userId,
                't_reservation_info',
                $data['d_reservation_date'] ?? null,
                ['date' => $data['d_reservation_date'] ?? null],
                $this->getClientIp(),
                $auditSuccess
            );

            // ★ ここからが重要：非AJAXでは「常にサーバ側で配列ルート→redirect()」に集約
            if ($this->request->is('ajax')) {
                return $resultResponse instanceof Response ? $resultResponse : $this->response;
            }

            $defaultRedirect = ['action' => 'index', '?' => ['date' => $data['d_reservation_date']]];

            if ($resultResponse instanceof Response) {
                $ctype = $resultResponse->getType() ?? '';
                if (stripos($ctype, 'application/json') !== false) {
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
     * 直前編集（モーダル用API/画面）。
     *
     * @param int|null $roomId
     * @param string|null $date YYYY-MM-DD
     * @param int|null $mealType 1=朝,2=昼,3=夜,4=弁当
     */
    public function changeEdit($roomId = null, $date = null, $mealType = null): ?Response
    {
        $wantsJson =
            $this->request->is('ajax') ||
            $this->request->getQuery('ajax') === '1' ||
            $this->request->accepts('application/json') ||
            $this->request->getParam('_ext') === 'json';

        $loginUser = $this->request->getAttribute('identity');
        if ($loginUser !== null) {
            $isAdmin  = UserRole::isAdmin((int)($loginUser->get('i_admin') ?? 0));
            $isStaff  = (int)($loginUser->get('i_user_level') ?? -1) === 0;
            if (!$isAdmin && !$isStaff) {
                $loginUidEarly = (int)($loginUser->get('i_id_user') ?? 0);
                $hasAffiliation = $loginUidEarly > 0 && $this->changeEditService->userHasRoomAccess($loginUidEarly);
                if (!$hasAffiliation) {
                    $reason = '直前編集は職員・管理者・所属グループがある方のみ使用できます。予約変更は通常の予約画面からご利用ください。';
                    if ($wantsJson) {
                        return $this->response->withStatus(403)->withType('application/json')
                            ->withStringBody(json_encode(['ok' => false, 'status' => 'forbidden', 'message' => $reason], JSON_UNESCAPED_UNICODE));
                    }
                    $this->Flash->error($reason);
                    return $this->redirect(['action' => 'index']);
                }
            }
        }

        if ($denied = $this->authorizeReservation('changeEdit', [], $wantsJson)) {
            return $denied;
        }

        try {
            $roomId   = $roomId   ?? $this->request->getParam('roomId')   ?? $this->request->getQuery('roomId')   ?? $this->request->getData('i_id_room');
            $date     = $date     ?? $this->request->getParam('date')     ?? $this->request->getQuery('date')     ?? $this->request->getData('d_reservation_date');

            $isModalShell = $this->request->is('get') && ((string)$this->request->getQuery('modal') === '1');

            $loginUser = $this->request->getAttribute('identity');
            $loginUid  = $loginUser?->get('i_id_user');

            $changeEditService = $this->changeEditService;
            $allowedRooms = $changeEditService->getAllowedRooms(
                $loginUser,
                $roomId ? (int)$roomId : null,
                $this->MUserGroup,
                $this->MRoomInfo
            );
            $isRoomManager = !empty($allowedRooms);

            if ($isModalShell) {
                $rooms = $allowedRooms;
                $room  = null;

                if (!$roomId && $date && !empty($rooms)) {
                    $roomId = $changeEditService->resolveDefaultRoomId(
                        $rooms,
                        (string)$date,
                        $this->TIndividualReservationInfo
                    );
                }

                if ($roomId && isset($rooms[(int)$roomId])) {
                    $room = $this->MRoomInfo->find()
                        ->select(['i_id_room', 'c_room_name'])
                        ->where(['i_id_room' => $roomId])
                        ->first();
                }
                if (empty($rooms)) {
                    $this->log('changeEdit modal: No rooms available for user ' . $loginUid, 'warning');
                }
                $users = new \Cake\Collection\Collection([]);
                $userReservations = [];
                $individualReservations = [];
                if ($loginUid && $date) {
                    $allIndiv = $this->TIndividualReservationInfo->find()
                        ->select(['i_reservation_type', 'i_id_room', 'eat_flag', 'i_change_flag'])
                        ->where(['i_id_user' => $loginUid, 'd_reservation_date' => $date])
                        ->toArray();
                    $individualReservations = array_values(array_filter($allIndiv, function ($r) {
                        $effective = $r->i_change_flag !== null ? (int)$r->i_change_flag : (int)$r->eat_flag;
                        return $effective === 1;
                    }));
                }
                $this->set(compact('room', 'rooms', 'date', 'users', 'userReservations', 'individualReservations'));
                return $this->render('change_edit');
            }

            if ($this->request->is(['post', 'put'])) {
                $earlyData = $this->request->getData();
                if (empty($earlyData)) {
                    $earlyParsed = $this->request->input('json_decode', true);
                    if (is_array($earlyParsed)) {
                        $earlyData = $earlyParsed;
                    }
                }
                if (($earlyData['reservation_type'] ?? '2') === '1') {
                    $postDate = $date ?? ($earlyData['d_reservation_date'] ?? null);
                    if (!$postDate) {
                        if ($wantsJson) {
                            return $this->response->withStatus(422)->withType('application/json')
                                ->withStringBody(json_encode(['ok' => false, 'status' => 'error', 'message' => '日付が指定されていません。'], JSON_UNESCAPED_UNICODE));
                        }
                        $this->Flash->error('日付が指定されていません。');
                        return $this->redirect(['action' => 'index']);
                    }
                    try {
                        $result = $this->writeService->processIndividualReservation(
                            (string)$postDate,
                            $earlyData,
                            $allowedRooms,
                            (int)$loginUid,
                            (string)($loginUser->get('c_user_name') ?? ''),
                            fn($d) => true
                        );
                        $payload = [
                            'ok'      => true,
                            'status'  => 'success',
                            'message' => '直前予約（個人）を更新しました。',
                            'data'    => $result,
                            'date'    => $postDate,
                        ];
                        if ($wantsJson) {
                            return $this->response->withType('application/json')
                                ->withStringBody(json_encode($payload, JSON_UNESCAPED_UNICODE));
                        }
                        $this->Flash->success($payload['message']);
                        return $this->redirect(['action' => 'index']);
                    } catch (\App\Domain\Exception\DomainException $e) {
                        $this->log('直前編集（個人）エラー: ' . $e->getMessage(), 'warning');
                        if ($wantsJson) {
                            return $this->response->withStatus($e->getStatusCode())->withType('application/json')
                                ->withStringBody(json_encode(['ok' => false, 'status' => 'error', 'message' => $e->getMessage()], JSON_UNESCAPED_UNICODE));
                        }
                        $this->Flash->error($e->getMessage());
                        return $this->redirect(['action' => 'index']);
                    } catch (\Throwable $e) {
                        $this->log('直前編集（個人）エラー: ' . $e->getMessage(), 'error');
                        if ($wantsJson) {
                            return $this->response->withStatus(500)->withType('application/json')
                                ->withStringBody(json_encode(['ok' => false, 'status' => 'error', 'message' => '予約の更新中にエラーが発生しました。'], JSON_UNESCAPED_UNICODE));
                        }
                        $this->Flash->error('予約の更新中にエラーが発生しました。');
                        return $this->redirect(['action' => 'index']);
                    }
                }
            }

            if (!$roomId || !$date) {
                throw new \InvalidArgumentException('部屋IDまたは日付が指定されていません。');
            }

            if (!isset($allowedRooms[(int)$roomId])) {
                if ($wantsJson) {
                    return $this->response->withStatus(403)->withType('application/json')
                        ->withStringBody(json_encode([
                            'ok'      => false,
                            'status'  => 'error',
                            'message' => 'この部屋を操作する権限がありません。',
                            'data'    => [],
                        ], JSON_UNESCAPED_UNICODE));
                }
                $this->Flash->error(__('この部屋を操作する権限がありません。'));
                return $this->redirect(['action' => 'index']);
            }

            $context = $changeEditService->buildContext(
                (int)$roomId,
                (string)$date,
                $this->MRoomInfo,
                $this->MUserGroup,
                $this->MUserInfo,
                $this->TIndividualReservationInfo
            );
            $room             = $context['room'];
            $users            = $context['users'];
            $userReservations = $context['userReservations'];
            $userIdList       = $context['userIdList'];

            if ($this->request->is('get')) {
                if ($wantsJson) {
                    $usersForJson = $changeEditService->buildUsersForJson($users, $loginUser, $isRoomManager);

                    return $this->response->withType('application/json')
                        ->withStringBody(json_encode([
                            'ok'     => true,
                            'status' => 'success',
                            'message' => null,
                            'data'   => [
                                'contextRoom'      => ['id' => (int)$room->i_id_room, 'name' => (string)$room->c_room_name],
                                'date'             => $date,
                                'users'            => $usersForJson,
                                'userReservations' => $userReservations,
                            ],
                        ], JSON_UNESCAPED_UNICODE));
                }

                $rooms = $allowedRooms;
                $individualReservations = [];
                if ($loginUid && $date) {
                    $allIndiv = $this->TIndividualReservationInfo->find()
                        ->select(['i_reservation_type', 'i_id_room', 'eat_flag', 'i_change_flag'])
                        ->where(['i_id_user' => $loginUid, 'd_reservation_date' => $date])
                        ->toArray();
                    $individualReservations = array_values(array_filter($allIndiv, function ($r) {
                        $effective = $r->i_change_flag !== null ? (int)$r->i_change_flag : (int)$r->eat_flag;
                        return $effective === 1;
                    }));
                }
                $this->set(compact('room', 'rooms', 'date', 'users', 'userReservations', 'individualReservations'));
                return $this->render('change_edit');
            }

            if ($this->request->is(['post', 'put'])) {
                $data = $this->request->getData();
                if (empty($data)) {
                    $parsed = $this->request->input('json_decode', true);
                    if (is_array($parsed)) {
                        $data = $parsed;
                    }
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
                        $this->MUserInfo,
                        $isRoomManager
                    );

                    $updated = $result['updated'] ?? [];
                    $created = $result['created'] ?? [];
                    $skipped = $result['skipped'] ?? [];

                    $payload = [
                        'ok'      => true,
                        'status'  => 'success',
                        'message' => '直前予約を更新しました。',
                        'data'    => ['updated' => $updated, 'created' => $created, 'skipped' => $skipped],
                    ];
                    if ($wantsJson) {
                        return $this->response->withType('application/json')->withStringBody(json_encode($payload));
                    }
                    $this->Flash->success(__($payload['message']));
                    return $this->redirect(['action' => 'index']);

                } catch (OptimisticLockConflictException $e) {
                    $this->log('直前編集 競合: ' . $e->getMessage(), 'warning');
                    if ($wantsJson) {
                        return $this->response->withStatus(409)->withType('application/json')
                            ->withStringBody(json_encode([
                                'ok'      => false,
                                'status'  => 'conflict',
                                'message' => $e->getMessage(),
                                'data'    => [],
                            ], JSON_UNESCAPED_UNICODE));
                    }
                    $this->Flash->error(__($e->getMessage()));
                } catch (\Throwable $e) {
                    $this->log('直前編集エラー: ' . $e->getMessage(), 'error');
                    if ($wantsJson) {
                        return $this->response->withStatus(500)->withType('application/json')
                            ->withStringBody(json_encode([
                                'ok'      => false,
                                'status'  => 'error',
                                'message' => '直前予約の更新中にエラーが発生しました。',
                                'data'    => [],
                            ], JSON_UNESCAPED_UNICODE));
                    }
                    $this->Flash->error(__('直前予約の更新中にエラーが発生しました。'));
                }
            }

            $rooms = $allowedRooms;
            if (!isset($users)) {
                $users = [];
            }
            if (!isset($userReservations)) {
                $userReservations = [];
            }
            $this->set(compact('room', 'rooms', 'date', 'users', 'userReservations'));
            return $this->render('change_edit');

        } catch (\Throwable $e) {
            $this->log('changeEdit error: ' . $e->getMessage(), 'error');

            if ($wantsJson) {
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
                        'ok'      => false,
                        'status'  => 'error',
                        'message' => '直前予約の取得中にエラーが発生しました。',
                        'data'    => [],
                    ], JSON_UNESCAPED_UNICODE));
            }

            throw $e;
        }
    }

    private function validateReservationDate(?string $reservationDate): string|bool
    {
        return $this->datePolicy->validateReservationDate($reservationDate);
    }

    /**
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
            $parts[]  = sprintf('%s（%s・%s）', $userName, $mealType, $roomName);
        }

        return '既に予約済みのためスキップ: ' . implode('、', $parts);
    }
}
