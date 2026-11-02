<?php
declare(strict_types=1);

namespace App\Controller;

use App\Application\UseCase\CopyReservations\CopyReservationsUseCase;
use App\Domain\Exception\ConflictException;
use App\Domain\Exception\ReservationValidationException;
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
     * コピー対象の部屋が無いときの理由。部屋を選んだか（所属外）、所属全部屋か（所属部屋なし）で分ける。
     */
    private function copyRoomDeniedMessage(?int $requestedRoomId): string
    {
        return $requestedRoomId !== null
            ? '所属していない部屋はコピーできません。'
            : '所属している部屋がないため、コピーできません。';
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
            $user = $this->request->getAttribute('identity') ?: null;
            $norm = $copyReservations->normalizeCopyParams($data);
            if (!$norm['ok']) {
                return $this->apiResponseService->error(
                    $this->response,
                    (string)$norm['message'],
                    (int)($norm['status'] ?? 422)
                );
            }

            $roomIds = $copyReservations->resolveTargetRoomIds($norm['roomId'], (int)($user?->get('i_id_user') ?? 0));
            if ($roomIds === null) {
                return $this->apiResponseService->error($this->response, $this->copyRoomDeniedMessage($norm['roomId']), 403);
            }

            $actorName = $user ? (string)$user->get('c_user_name') : null;
            $result = ($norm['mode'] === 'week')
                ? $copyReservations->copyWeek($norm['src'], $norm['dst'], $roomIds, $actorName, $norm['onlyChildren'])
                : $copyReservations->copyMonth($norm['src'], $norm['dst'], $roomIds, $actorName, $norm['onlyChildren']);

            $msg = ($norm['mode'] === 'week') ? '週コピーが完了しました。' : '月コピーが完了しました。';

            return $this->apiResponseService->success($this->response, [
                'mode'         => $norm['mode'],
                'total'        => $result['total']        ?? 0,
                'copied'       => $result['copied']       ?? 0,
                'skipped'      => $result['skipped']      ?? 0,
                'invalid_date' => $result['invalid_date'] ?? 0,
                'affected'     => $result['copied']       ?? 0,
            ], $msg);
        } catch (ConflictException $e) {
            // 二重予約・昼食と弁当の同時予約になる行があると、コピー全体を取り消す。理由（誰の・いつの・どの食事か）を返す
            return $this->apiResponseService->error($this->response, 'コピーできませんでした。' . $e->getMessage(), 409);
        } catch (ReservationValidationException $e) {
            // コピー先の検証ルール（昼食と弁当の同時予約など）に当たった。昼食と弁当なら理由を返す
            Log::error('Reservation copy rejected: ' . $e->getMessage());
            return $e->lunchBentoMessage !== null
                ? $this->apiResponseService->error($this->response, 'コピーできませんでした。' . $e->lunchBentoMessage, 409)
                : $this->apiResponseService->error($this->response, 'コピー処理中にエラーが発生しました。', 500);
        } catch (\Throwable $e) {
            Log::error(sprintf('Reservation copy failed: %s in %s:%d', $e->getMessage(), $e->getFile(), $e->getLine()));
            return $this->apiResponseService->error($this->response, 'コピー処理中にエラーが発生しました。', 500);
        }
    }

    /**
     * 予約コピープレビューAPI（件数のみ取得）。
     *
     * @return Response|null
     */
    public function copyPreview(CopyReservationsUseCase $copyReservations): ?Response
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
            $norm = $copyReservations->normalizeCopyParams($data);
            if (!$norm['ok']) {
                return $this->apiResponseService->error(
                    $this->response,
                    (string)$norm['message'],
                    (int)($norm['status'] ?? 422)
                );
            }

            $user    = $this->request->getAttribute('identity') ?: null;
            $roomIds = $copyReservations->resolveTargetRoomIds($norm['roomId'], (int)($user?->get('i_id_user') ?? 0));
            if ($roomIds === null) {
                return $this->apiResponseService->error($this->response, $this->copyRoomDeniedMessage($norm['roomId']), 403);
            }

            $preview = ($norm['mode'] === 'week')
                ? $copyReservations->previewWeek($norm['src'], $norm['dst'], $roomIds, $norm['onlyChildren'])
                : $copyReservations->previewMonth($norm['src'], $norm['dst'], $roomIds, $norm['onlyChildren']);

            return $this->apiResponseService->success($this->response, ['preview' => $preview]);
        } catch (\Throwable $e) {
            Log::error(sprintf('Reservation copy preview failed: %s in %s:%d', $e->getMessage(), $e->getFile(), $e->getLine()));
            return $this->apiResponseService->error($this->response, 'プレビュー取得中にエラーが発生しました。', 500);
        }
    }
}
