<?php
declare(strict_types=1);

namespace App\Controller\Traits;

use Cake\Core\Configure;
use Cake\Log\Log;

trait ReservationCopyActionsTrait
{
    protected function runCopy()
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
                    $json = json_decode($raw, true);
                    if (json_last_error() === JSON_ERROR_NONE && is_array($json)) {
                        $data = $json;
                    }
                }
            } catch (\Throwable $e) {
            }
        }

        try {
            $user = $this->request->getAttribute('identity') ?: null;
            $service = $this->copyService;
            $norm = $service->normalizeCopyParams($data);
            if (!$norm['ok']) {
                return $this->apiResponseService->error(
                    $this->response,
                    (string)$norm['message'],
                    (int)($norm['status'] ?? 422)
                );
            }

            $overwrite = false;

            $result = ($norm['mode'] === 'week')
                ? $service->copyWeek($norm['src'], $norm['dst'], $norm['roomId'], $overwrite, $user, $norm['onlyChildren'])
                : $service->copyMonth($norm['src'], $norm['dst'], $norm['roomId'], $overwrite, $user, $norm['onlyChildren']);

            $total = $result['total'] ?? 0;
            $copied = $result['copied'] ?? 0;
            $skipped = $result['skipped'] ?? 0;
            $invalidDate = $result['invalid_date'] ?? 0;

            $msg = ($norm['mode'] === 'week')
                ? sprintf('週コピーが完了しました。', $copied)
                : sprintf('月コピーが完了しました。', $copied);

            $responseData = [
                'mode' => $norm['mode'],
                'total' => $total,
                'copied' => $copied,
                'skipped' => $skipped,
                'invalid_date' => $invalidDate,
                'affected' => $copied,
            ];

            Log::debug('[copy] Response data: ' . json_encode($responseData));

            return $this->apiResponseService->success($this->response, $responseData, $msg);
        } catch (\Throwable $e) {
            Log::error(sprintf(
                'Reservation copy failed: %s in %s:%d',
                $e->getMessage(),
                $e->getFile(),
                $e->getLine()
            ));
            return $this->apiResponseService->error(
                $this->response,
                'コピー処理中にエラーが発生しました。',
                500,
                ['detail' => Configure::read('debug') ? $e->getMessage() : null]
            );
        }
    }

    protected function runCopyPreview()
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
                    $json = json_decode($raw, true);
                    if (json_last_error() === JSON_ERROR_NONE && is_array($json)) {
                        $data = $json;
                    }
                }
            } catch (\Throwable $e) {
            }
        }

        try {
            $service = $this->copyService;
            $norm = $service->normalizeCopyParams($data);
            if (!$norm['ok']) {
                return $this->apiResponseService->error(
                    $this->response,
                    (string)$norm['message'],
                    (int)($norm['status'] ?? 422)
                );
            }

            $preview = ($norm['mode'] === 'week')
                ? $service->previewWeek($norm['src'], $norm['dst'], $norm['roomId'], $norm['onlyChildren'])
                : $service->previewMonth($norm['src'], $norm['dst'], $norm['roomId'], $norm['onlyChildren']);

            return $this->apiResponseService->success(
                $this->response,
                ['preview' => $preview]
            );
        } catch (\Throwable $e) {
            Log::error(sprintf(
                'Reservation copy preview failed: %s in %s:%d',
                $e->getMessage(),
                $e->getFile(),
                $e->getLine()
            ));
            return $this->apiResponseService->error(
                $this->response,
                'プレビュー取得中にエラーが発生しました。',
                500,
                ['detail' => Configure::read('debug') ? $e->getMessage() : null]
            );
        }
    }
}
