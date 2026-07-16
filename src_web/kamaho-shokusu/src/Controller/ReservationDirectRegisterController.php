<?php
declare(strict_types=1);

namespace App\Controller;

use App\Application\UseCase\DirectRegisterMeals\DirectRegisterMealsInput;
use App\Application\UseCase\DirectRegisterMeals\DirectRegisterMealsUseCase;
use App\Domain\Exception\DomainException;
use App\Service\AuditLogService;
use App\Service\ReservationWriteService;
use Cake\Http\Response;

/**
 * カレンダークリック直接登録コントローラー。
 *
 * モーダルを介さない dateClick フローからのみ呼ばれる。
 * 1リクエストで複数食事をまとめて登録し、登録済みはスキップして返す。
 */
class ReservationDirectRegisterController extends ReservationBaseController
{
    private DirectRegisterMealsUseCase $useCase;

    public function initialize(): void
    {
        parent::initialize();

        $writeService  = new ReservationWriteService(
            $this->TIndividualReservationInfo,
            $this->MUserInfo,
            $this->MRoomInfo,
            (string)($this->request->getAttribute('webroot') ?? '')
        );
        $this->useCase = new DirectRegisterMealsUseCase($writeService);

        $this->FormProtection->setConfig('unlockedActions', ['register']);
    }

    /**
     * 複数食事を一括直接登録する API エンドポイント。
     *
     * リクエスト JSON:
     * {
     *   "date":   "YYYY-MM-DD",
     *   "roomId": 1,
     *   "meals":  [1, 2, 3],   // 1=朝 2=昼 3=夕
     *   "userId": 42            // 省略時はログインユーザー自身
     * }
     *
     * @return Response|null
     */
    public function register(): ?Response
    {
        $this->request->allowMethod(['post']);
        $this->response = $this->response->withType('application/json');

        $payload = (array)$this->request->getData();
        if (empty($payload)) {
            $payload = (array)($this->request->input('json_decode', true) ?? []);
        }

        $roomId = isset($payload['roomId']) ? (int)$payload['roomId'] : 0;
        if ($roomId <= 0) {
            return $this->apiResponseService->error($this->response, 'roomId is required.', 400);
        }

        $dateStr = (string)($payload['date'] ?? '');
        if ($dateStr === '') {
            return $this->apiResponseService->error($this->response, 'date is required.', 400);
        }

        $rawMeals = isset($payload['meals']) && is_array($payload['meals']) ? $payload['meals'] : [];
        if (empty($rawMeals)) {
            return $this->apiResponseService->error($this->response, 'meals is required.', 400);
        }
        $mealIndices = array_values(array_unique(array_map('intval', $rawMeals)));

        $loginUser     = $this->request->getAttribute('identity');
        $loginUserId   = (int)($loginUser?->get('i_id_user') ?? 0);
        $loginUserName = (string)($loginUser?->get('c_user_name') ?? '不明');
        $loginAccount  = (string)($loginUser?->get('c_login_account') ?? '');
        if ($loginUserId <= 0) {
            return $this->apiResponseService->error($this->response, 'Unauthorized', 401);
        }

        $targetUserId = isset($payload['userId']) && (int)$payload['userId'] > 0
            ? (int)$payload['userId']
            : $loginUserId;

        if ($denied = $this->authorizeReservation('toggle', [
            'i_id_room' => $roomId,
            'i_id_user' => $targetUserId,
        ], true)) {
            return $denied;
        }

        $auditContext = ['date' => $dateStr, 'meals' => $mealIndices, 'targetUserId' => $targetUserId];
        try {
            $input = new DirectRegisterMealsInput(
                date:          $dateStr,
                roomId:        $roomId,
                loginUserId:   $loginUserId,
                loginUserName: $loginUserName,
                targetUserId:  $targetUserId,
                mealIndices:   $mealIndices,
            );

            $output = $this->useCase->execute($input);

            AuditLogService::record(
                'reservation', 'direct_register_meals', $loginUserName, $loginUserId,
                't_reservation_info', "room:{$roomId}", $auditContext, $this->getClientIp(), 1, $loginAccount
            );

            return $this->apiResponseService->success($this->response, [
                'registered' => $output->registered,
                'skipped'    => $output->skipped,
            ], null, 200);
        } catch (\InvalidArgumentException $e) {
            return $this->apiResponseService->error($this->response, $e->getMessage(), 400);
        } catch (DomainException $e) {
            AuditLogService::record(
                'reservation', 'direct_register_meals', $loginUserName, $loginUserId,
                't_reservation_info', "room:{$roomId}", $auditContext, $this->getClientIp(), 0, $loginAccount
            );

            return $this->apiResponseService->error($this->response, $e->getMessage(), $e->getStatusCode());
        }
    }
}
