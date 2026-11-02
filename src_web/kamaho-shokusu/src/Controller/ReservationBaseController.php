<?php
declare(strict_types=1);

namespace App\Controller;

use App\Application\Query\CalendarQueryInterface;
use App\Application\Query\ReservationReadQueryInterface;
use App\Application\Query\RoomQueryInterface;
use App\Infrastructure\Persistence\Entity\TReservationInfo;
use App\Service\ApiResponseService;
use App\Service\ReservationDatePolicy;
use Authorization\Exception\ForbiddenException;
use Cake\Http\ServerRequest;
use Cake\Http\Response;

/**
 * 予約系コントローラーの共通基底クラス。
 *
 * テーブル参照・共有サービス・認可ヘルパーを提供する。
 * 各予約サブコントローラーはこのクラスを継承すること。
 */
abstract class ReservationBaseController extends AppController
{
    /** @var \App\Infrastructure\Persistence\Table\TReservationInfoTable */
    protected $TReservationInfo;
    /** @var \App\Infrastructure\Persistence\Table\MRoomInfoTable */
    protected $MRoomInfo;
    /** @var \App\Infrastructure\Persistence\Table\MUserInfoTable */
    protected $MUserInfo;
    /** @var \App\Infrastructure\Persistence\Table\MUserGroupTable */
    protected $MUserGroup;
    /** @var \App\Infrastructure\Persistence\Table\TIndividualReservationInfoTable */
    protected $TIndividualReservationInfo;

    protected ReservationDatePolicy $datePolicy;
    protected ApiResponseService $apiResponseService;

    /**
     * 予約系の全アクションで使う読み取り（Query）は、コンテナからコンストラクタで受け取る（登録は Application::services()）。
     */
    public function __construct(
        ServerRequest $request,
        protected readonly CalendarQueryInterface $calendarQuery,
        protected readonly RoomQueryInterface $roomQuery,
        protected readonly ReservationReadQueryInterface $reservationRead,
    ) {
        parent::__construct($request);
    }

    public function initialize(): void
    {
        parent::initialize();

        $this->TReservationInfo           = $this->fetchTable('TReservationInfo');
        $this->MRoomInfo                  = $this->fetchTable('MRoomInfo');
        $this->MUserInfo                  = $this->fetchTable('MUserInfo');
        $this->MUserGroup                 = $this->fetchTable('MUserGroup');
        $this->TIndividualReservationInfo = $this->fetchTable('TIndividualReservationInfo');

        $this->datePolicy       = new ReservationDatePolicy();
        $this->apiResponseService = new ApiResponseService();

        $this->viewBuilder()->setLayout('default');
    }

    /**
     * Authorization::authorize() をラップし、失敗時を JSON または例外で返す。
     *
     * @param string $action ポリシーアクション名
     * @param array<string, mixed> $context エンティティに設定するコンテキスト
     * @param bool $asJson true のとき ForbiddenException を JSON レスポンスに変換する
     * @return Response|null 認可失敗時は Response、成功時は null
     */
    protected function authorizeReservation(string $action, array $context = [], bool $asJson = false): ?Response
    {
        $resource = new TReservationInfo();
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
            return $this->apiResponseService->forbidden($this->response);
        }
    }

    /**
     * @param array<string, mixed> $data
     */
    protected function jsonErrorResponse(string $message, int $status = 400, array $data = []): Response
    {
        return $this->apiResponseService->error($this->response, $message, $status, $data);
    }

    /**
     * @param array<string, mixed> $data
     */
    protected function jsonSuccessResponse(string $message, array $data = [], ?string $redirect = null, int $status = 200): Response
    {
        if ($redirect !== null && $redirect !== '') {
            $data['redirect'] = $redirect;
        }
        return $this->apiResponseService->success($this->response, $data, $message, $status);
    }
}
