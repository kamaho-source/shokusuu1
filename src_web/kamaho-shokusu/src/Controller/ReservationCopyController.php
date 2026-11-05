<?php
declare(strict_types=1);

namespace App\Controller;

use App\Application\UseCase\CopyReservations\CopyReservationsInput;
use App\Application\UseCase\CopyReservations\CopyReservationsUseCase;
use App\Application\UseCase\CopyReservations\PreviewReservationCopyUseCase;
use App\Domain\Exception\ConflictException;
use App\Domain\Exception\InvalidInputException;
use App\Domain\Exception\ReservationValidationException;
use App\Domain\Exception\UnauthorizedException;
use Cake\Http\Response;
use Cake\Log\Log;

/**
 * 予約コピー専用コントローラー。
 *
 * 週・月単位の予約コピーおよびプレビューAPIを担当する。
 */
class ReservationCopyController extends ReservationBaseController
{
    public function initialize(): void
    {
        parent::initialize();

        $this->FormProtection->setConfig('unlockedActions', ['copy', 'copyPreview']);
    }

    /**
     * 予約コピー実行API（週／月）。
     *
     * @return Response|null
     */
    public function copy(CopyReservationsUseCase $copyReservations): ?Response
    {
        if ($denied = $this->authorizeReservation('copy', [], true)) {
            return $denied;
        }
        $this->request->allowMethod(['post']);
        $this->viewBuilder()->disableAutoLayout();
        $this->response = $this->response->withType('application/json');

        $data = (array)$this->request->getData();
        if (empty($data)) {
            try {
                $raw = (string)$this->request->getBody();
                if ($raw !== '') {
                    $json = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
                    if (is_array($json)) {
                        $data = $json;
                    }
                }
            } catch (\JsonException $e) {
                Log::warning('copy: invalid JSON body: ' . $e->getMessage());
            }
        }

        try {
            $output = $copyReservations->execute($this->copyInput($data));
            $msg = $output->mode === CopyReservationsInput::WEEK ? '週コピーが完了しました。' : '月コピーが完了しました。';
            $this->recordReservationAudit('reservation_copy', null, $output->toArray(), true);

            return $this->apiResponseService->success($this->response, $output->toArray(), $msg);
        } catch (InvalidInputException | UnauthorizedException $e) {
            $this->recordReservationAudit('reservation_copy', null, ['mode' => $data['mode'] ?? null], false, $e->getMessage());

            return $this->apiResponseService->error($this->response, $e->getMessage(), $e->getStatusCode());
        } catch (ConflictException $e) {
            $this->recordReservationAudit('reservation_copy', null, ['mode' => $data['mode'] ?? null], false, $e->getMessage());
            // 二重予約・昼食と弁当の同時予約になる行があると、コピー全体を取り消す。理由（誰の・いつの・どの食事か）を返す
            return $this->apiResponseService->error($this->response, 'コピーできませんでした。' . $e->getMessage(), 409);
        } catch (ReservationValidationException $e) {
            // コピー先の検証ルール（昼食と弁当の同時予約など）に当たった。昼食と弁当なら理由を返す
            Log::error('Reservation copy rejected: ' . $e->getMessage());
            $this->recordReservationAudit('reservation_copy', null, ['mode' => $data['mode'] ?? null], false, $e->lunchBentoMessage ?? 'コピー先の検証ルールに当たり、コピーできませんでした');
            return $e->lunchBentoMessage !== null
                ? $this->apiResponseService->error($this->response, 'コピーできませんでした。' . $e->lunchBentoMessage, 409)
                : $this->apiResponseService->error($this->response, 'コピー処理中にエラーが発生しました。', 500);
        } catch (\Throwable $e) {
            Log::error(sprintf('Reservation copy failed: %s in %s:%d', $e->getMessage(), $e->getFile(), $e->getLine()));
            $this->recordReservationAudit('reservation_copy', null, ['mode' => $data['mode'] ?? null], false, '予期しないエラーでコピーできませんでした');
            return $this->apiResponseService->error($this->response, 'コピー処理中にエラーが発生しました。', 500);
        }
    }

    /**
     * 予約コピープレビューAPI（件数のみ取得）。
     *
     * @return Response|null
     */
    public function copyPreview(PreviewReservationCopyUseCase $previewCopy): ?Response
    {
        if ($denied = $this->authorizeReservation('copy', [], true)) {
            return $denied;
        }
        $this->request->allowMethod(['post', 'get']);
        $this->viewBuilder()->disableAutoLayout();
        $this->response = $this->response->withType('application/json');

        $data = (array)$this->request->getData();
        if (empty($data) && $this->request->is('get')) {
            $data = (array)$this->request->getQueryParams();
        }
        if (empty($data)) {
            try {
                $raw = (string)$this->request->getBody();
                if ($raw !== '') {
                    $json = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
                    if (is_array($json)) {
                        $data = $json;
                    }
                }
            } catch (\JsonException $e) {
                Log::warning('copyPreview: invalid JSON body: ' . $e->getMessage());
            }
        }

        try {
            $preview = $previewCopy->execute($this->copyInput($data));

            return $this->apiResponseService->success($this->response, ['preview' => $preview->toArray()]);
        } catch (InvalidInputException | UnauthorizedException $e) {
            return $this->apiResponseService->error($this->response, $e->getMessage(), $e->getStatusCode());
        } catch (\Throwable $e) {
            Log::error(sprintf('Reservation copy preview failed: %s in %s:%d', $e->getMessage(), $e->getFile(), $e->getLine()));
            return $this->apiResponseService->error($this->response, 'プレビュー取得中にエラーが発生しました。', 500);
        }
    }

    /**
     * 送られた値とログインしている人から、コピーの入力を作る。
     *
     * @param array<array-key, mixed> $data
     * @throws InvalidInputException mode・日付の誤り（422）
     */
    private function copyInput(array $data): CopyReservationsInput
    {
        $user = $this->request->getAttribute('identity') ?: null;

        return CopyReservationsInput::fromRequest(
            $data,
            (int)($user?->get('i_id_user') ?? 0),
            $user ? (string)$user->get('c_user_name') : null,
        );
    }
}
