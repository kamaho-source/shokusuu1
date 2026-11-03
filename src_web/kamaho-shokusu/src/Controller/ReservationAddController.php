<?php
declare(strict_types=1);

namespace App\Controller;

use App\Application\UseCase\RegisterGroupReservation\RegisterGroupReservationInput;
use App\Application\UseCase\RegisterGroupReservation\RegisterGroupReservationUseCase;
use App\Application\UseCase\RegisterIndividualReservation\RegisterIndividualReservationInput;
use App\Application\UseCase\RegisterIndividualReservation\RegisterIndividualReservationUseCase;
use App\Domain\ValueObject\MealType;
use App\Domain\ValueObject\UserRole;
use App\Infrastructure\Persistence\Entity\TReservationInfo;
use Cake\Http\Response;
use Cake\I18n\Date;

/**
 * 予約の新規登録（個人予約・集団予約）。URL は /TReservationInfo/add のまま（routes.php）、テンプレートは TReservationInfo/add。
 */
class ReservationAddController extends ReservationBaseController
{
    public function initialize(): void
    {
        parent::initialize();

        $this->viewBuilder()->setTemplatePath('TReservationInfo');
        $this->FormProtection->setConfig('unlockedActions', ['add']);
    }

    /**
     * 予約登録メソッド（個人予約・集団予約）。
     *
     * @return Response|null POST はリダイレクトまたは JSON、GET は画面（null）
     */
    public function add(RegisterIndividualReservationUseCase $registerIndividual, RegisterGroupReservationUseCase $registerGroup): ?Response
    {
        $this->authorizeReservation('add');

        $user       = $this->request->getAttribute('identity');
        $isModal    = ($this->request->getQuery('modal') === '1');

        if ($isModal) {
            $this->viewBuilder()->disableAutoLayout();
            $this->response = $this->response
                ->withHeader('X-Frame-Options', 'SAMEORIGIN')
                ->withHeader('Content-Security-Policy', "frame-ancestors 'self'");
        }

        $date = $this->queryDate('date') ?? Date::today()->format('Y-m-d');

        if (!$user) {
            $this->set('errorMessage', __('ログインが必要です。'));
            $this->set('date', $date);
            $this->set('rooms', []);
            $this->set('roomList', []);
            $this->set('userLevel', null);
            $this->set('tReservationInfo', self::emptyReservation());
            return null;
        }

        $userLevel = $user->i_user_level;
        $userId    = $user->get('i_id_user');
        $rooms     = $this->roomQuery->getAccessibleRooms((int)$userId);

        if ($this->request->is('get')) {
            $validation = $this->datePolicy->validateReservationDate((string)$date);
            $roomList   = $this->roomQuery->getAllActiveRooms();

            $tReservationInfo = self::emptyReservation();

            $this->set(compact('rooms', 'tReservationInfo', 'date', 'roomList', 'userLevel'));

            if ($validation !== true) {
                $this->set('errorMessage', __((string)$validation));
            } elseif (!$roomList) {
                $this->set('errorMessage', __('部屋が見つかりません。'));
            }

            return null;
        }

        $validation = $this->datePolicy->validateReservationDate((string)$date);
        if ($validation !== true) {
            $msg = __((string)$validation);
            if ($this->request->is('ajax')) {
                return $this->jsonErrorResponse($msg, 422);
            }
            $this->Flash->error($msg);
            return $this->redirect(['controller' => 'TReservationInfo', 'action' => 'index', '?' => ['date' => $date]]);
        }

        $roomList = $this->roomQuery->getAllActiveRooms();

        if (!$roomList) {
            if ($this->request->is('ajax')) {
                return $this->jsonErrorResponse(__('部屋が見つかりません。'), 404);
            }
            $this->Flash->error(__('部屋が見つかりません。'));
            return $this->redirect(['controller' => 'TReservationInfo', 'action' => 'index', '?' => ['date' => $date]]);
        }

        $tReservationInfo = self::emptyReservation();

        if ($this->request->is('post')) {
            $data = $this->request->getData();
            if (empty($data['d_reservation_date'])) {
                $data['d_reservation_date'] = (string)$date;
            }

            $conflict = MealType::exclusiveSelectionError(!empty($data['lunch']), !empty($data['bento']));
            if ($conflict) {
                if ($this->request->is('ajax')) {
                    return $this->jsonErrorResponse(__($conflict), 409);
                }
                $this->Flash->error(__($conflict));
                return $this->redirect(['controller' => 'TReservationInfo', 'action' => 'index', '?' => ['date' => $data['d_reservation_date']]]);
            }

            $reservationType = $data['reservation_type'] ?? '1';
            $output = null;
            $errorMessage = '';
            try {
                // 日付の検証（通常予約の基準）と監査ログは UseCase が行う
                $output = ((string)$reservationType === '1')
                    ? $registerIndividual->execute(new RegisterIndividualReservationInput(
                        reservationDate: $data['d_reservation_date'],
                        data: $data,
                        rooms: $rooms,
                        userId: (int)$userId,
                        userName: (string)$user->get('c_user_name'),
                        checkNormalOrderDate: true,
                        auditActor: $this->auditActor(),
                    ))
                    : $registerGroup->execute(new RegisterGroupReservationInput(
                        reservationDate: $data['d_reservation_date'],
                        data: $data,
                        rooms: $rooms,
                        creatorName: (string)$user->get('c_user_name'),
                        loginUserId: (int)$userId,
                        isAdmin: UserRole::isAdmin((int)$user->get('i_admin')),
                        loginUserLevel: (int)$user->get('i_user_level'),
                        isBlockLeader: UserRole::isBlockLeader((int)$user->get('i_admin')),
                        checkNormalOrderDate: true,
                        auditActor: $this->auditActor(),
                    ));
                $result = $output->toArray($this->reservationIndexUrl());
                $resultResponse = $this->jsonSuccessResponse($result['message'], $result['data'] ?? [], $result['redirect'] ?? null);
            } catch (\App\Domain\Exception\DomainException $e) {
                $errorMessage = $e->getMessage();
                $resultResponse = $this->jsonErrorResponse($errorMessage, $e->getStatusCode());
            } catch (\Throwable $e) {
                $this->log('予約登録エラー: ' . $e->getMessage(), 'error');
                $errorMessage = '内部エラーが発生しました。';
                $resultResponse = $this->jsonErrorResponse($errorMessage, 500);
            }

            if ($this->request->is('ajax')) {
                return $resultResponse;
            }

            // フォーム送信は JSON を経由せず、UseCase の Output で Flash を決める（#989 不具合1）。
            // 戻り先は常にその日の一覧（Output の redirect は Ajax の JS 向けの文字列 URL）
            $defaultRedirect = ['controller' => 'TReservationInfo', 'action' => 'index', '?' => ['date' => $data['d_reservation_date']]];
            if ($output === null) {
                $this->Flash->error($errorMessage);
                return $this->redirect($defaultRedirect);
            }
            $this->Flash->success($output->message);
            return $this->redirect($defaultRedirect);
        }

        $this->set(compact('rooms', 'tReservationInfo', 'date', 'roomList', 'userLevel'));

        return null;
    }

    /** FormHelper に渡す空の予約（テーブルを引かずに作る。source は FormHelper がスキーマを読むのに使う）。 */
    private static function emptyReservation(): TReservationInfo
    {
        return new TReservationInfo([], ['source' => 'TReservationInfo']);
    }
}
