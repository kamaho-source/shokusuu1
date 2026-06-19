<?php
declare(strict_types=1);

namespace App\Controller;

use App\Service\ReservationCopyService;
use Cake\Http\Response;
use Cake\Log\Log;

/**
 * 予約コピー専用コントローラー。
 *
 * 週・月単位の予約コピーおよびプレビューAPIを担当する。
 */
class ReservationCopyController extends ReservationBaseController
{
    private ReservationCopyService $copyService;

    public function initialize(): void
    {
        parent::initialize();

        $this->copyService = new ReservationCopyService();

        $this->FormProtection->setConfig('unlockedActions', ['copy', 'copyPreview']);
    }

    /**
     * 予約コピー実行API（週／月）。
     *
     * @return Response|null
     */
    public function copy(): ?Response
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
            $norm = $this->copyService->normalizeCopyParams($data);
            if (!$norm['ok']) {
                return $this->apiResponseService->error(
                    $this->response,
                    (string)$norm['message'],
                    (int)($norm['status'] ?? 422)
                );
            }

            $result = ($norm['mode'] === 'week')
                ? $this->copyService->copyWeek($norm['src'], $norm['dst'], $norm['roomId'], false, $user, $norm['onlyChildren'])
                : $this->copyService->copyMonth($norm['src'], $norm['dst'], $norm['roomId'], false, $user, $norm['onlyChildren']);

            $msg = ($norm['mode'] === 'week') ? '週コピーが完了しました。' : '月コピーが完了しました。';

            return $this->apiResponseService->success($this->response, [
                'mode'         => $norm['mode'],
                'total'        => $result['total']        ?? 0,
                'copied'       => $result['copied']       ?? 0,
                'skipped'      => $result['skipped']      ?? 0,
                'invalid_date' => $result['invalid_date'] ?? 0,
                'affected'     => $result['copied']       ?? 0,
            ], $msg);
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
    public function copyPreview(): ?Response
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
            $norm = $this->copyService->normalizeCopyParams($data);
            if (!$norm['ok']) {
                return $this->apiResponseService->error(
                    $this->response,
                    (string)$norm['message'],
                    (int)($norm['status'] ?? 422)
                );
            }

            $preview = ($norm['mode'] === 'week')
                ? $this->copyService->previewWeek($norm['src'], $norm['dst'], $norm['roomId'], $norm['onlyChildren'])
                : $this->copyService->previewMonth($norm['src'], $norm['dst'], $norm['roomId'], $norm['onlyChildren']);

            return $this->apiResponseService->success($this->response, ['preview' => $preview]);
        } catch (\Throwable $e) {
            Log::error(sprintf('Reservation copy preview failed: %s in %s:%d', $e->getMessage(), $e->getFile(), $e->getLine()));
            return $this->apiResponseService->error($this->response, 'プレビュー取得中にエラーが発生しました。', 500);
        }
    }
}
