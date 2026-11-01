<?php
declare(strict_types=1);

namespace App\Application\UseCase\BulkAddReservations;

use App\Application\Port\ReservationCacheInterface;
use App\Application\Port\ReservationDatePolicyInterface;
use App\Application\Port\TransactionManagerInterface;
use App\Application\UseCase\BulkReservation\BulkReservationSupport;
use App\Domain\Exception\ConflictException;
use App\Domain\Exception\ReservationValidationException;
use App\Domain\Repository\ReservationRepositoryInterface;
use App\Domain\Repository\RoomMembershipRepositoryInterface;
use App\Domain\Repository\UserRepositoryInterface;
use Psr\Log\LoggerInterface;

/**
 * 一括登録を保存する。
 *
 * - personal: 自分の予約を、複数日 × 食事 × 部屋でまとめて登録する（PersonalBulkAddPlanner）。
 * - group: 部屋の利用者の予約を、複数日 × 食事でまとめて登録・取消する（GroupBulkAddPlanner）。
 * 書く行を決めるのは各 Planner、このクラスは保存（1トランザクション）とキャッシュの破棄を受け持つ。
 */
final class BulkAddReservationsUseCase
{
    private const SAVE_ERROR_MESSAGE = '予約の保存中にエラーが発生しました。';

    private readonly PersonalBulkAddPlanner $personalPlanner;

    private readonly GroupBulkAddPlanner $groupPlanner;

    public function __construct(
        private readonly ReservationRepositoryInterface $reservations,
        RoomMembershipRepositoryInterface $rooms,
        UserRepositoryInterface $users,
        private readonly TransactionManagerInterface $transaction,
        private readonly ReservationCacheInterface $cache,
        ReservationDatePolicyInterface $datePolicy,
        BulkReservationSupport $support,
        private readonly LoggerInterface $logger,
        /** 過去日を選んだときの文言（設定 App.messages.pastDateUnavailable） */
        string $pastDateMessage = '過去日の内容はこの画面では表示できません。修正が必要な場合は管理者にお問い合わせください。',
    ) {
        $validator             = new BulkAddRequestValidator($datePolicy, $rooms, $pastDateMessage);
        $this->personalPlanner = new PersonalBulkAddPlanner($reservations, $rooms, $validator);
        $this->groupPlanner    = new GroupBulkAddPlanner($reservations, $rooms, $users, $support, $validator);
    }

    /**
     * @return array{ok: bool, message: string, redirect_url?: string, conflict_date?: string}
     * @throws \RuntimeException 保存前の読み取りに失敗した場合
     */
    public function execute(BulkAddReservationsInput $input): array
    {
        $data            = $input->data;
        $userId          = $input->userId;
        $reservationType = $data['reservation_type'] ?? null;
        if (!$reservationType) {
            return [
                'ok' => false,
                'message' => '予約タイプが選択されていません。',
            ];
        }

        // ログインユーザーを特定できない場合は権限判定できないため拒否する（fail-closed）
        if ($userId <= 0) {
            return [
                'ok'      => false,
                'message' => 'ログインユーザーを特定できないため処理を中断しました。',
            ];
        }

        $plan = match ($reservationType) {
            'personal' => $this->personalPlanner->plan($input),
            'group'    => $this->groupPlanner->plan($input),
            default    => [
                'ok' => false,
                'message' => '無効な予約タイプが選択されました。',
            ],
        };
        if (isset($plan['ok'])) {
            return $plan;
        }

        $saveError = $this->save($plan, $input->userName);
        if ($saveError !== null) {
            return $saveError;
        }

        $this->forgetCaches($plan, $userId);

        return [
            'ok' => true,
            'message' => ($plan['skipped'] !== []
                ? "一部の予約は既に存在していたためスキップされました。\n" . implode("\n", $plan['skipped'])
                : 'すべての予約が正常に登録されました。')
                . ($plan['swapped'] !== [] ? "\n" . implode("\n", $plan['swapped']) : ''),
            'redirect_url' => './',
        ];
    }

    /**
     * 取消 → 有効化 → 新規保存を1トランザクションで行う。失敗したら取り消して ok=false の結果を返す。
     *
     * @param array<string, mixed> $plan
     * @return array{ok: false, message: string}|null 成功なら null
     */
    private function save(array $plan, string $userName): ?array
    {
        if ($plan['deactivate'] === [] && $plan['activate'] === [] && $plan['create'] === []) {
            return null;
        }
        $this->transaction->begin();
        try {
            foreach ($plan['deactivate'] as $row) {
                if (!$this->reservations->updateWithVersion($row, 0, 0, $userName)) {
                    throw new \RuntimeException('optimistic_conflict');
                }
            }

            foreach ($plan['activate'] as $row) {
                if (!$this->reservations->updateWithVersion($row, 1, 1, $userName)) {
                    throw new \RuntimeException('optimistic_conflict');
                }
            }

            if ($plan['create'] !== []) {
                // 送られた部屋 ID が数でない（"1abc" など）ときは、従来は保存時の型変換で失敗していた。同じく保存の失敗にする
                if (!is_numeric($plan['roomId']) && !is_bool($plan['roomId'])) {
                    throw new \InvalidArgumentException('部屋 ID が数ではありません（' . get_debug_type($plan['roomId']) . '）。');
                }
                // 一括登録は、従来どおり入力の検証（型変換）をせずに保存する
                $this->reservations->addMany($plan['create'], $userName, false);
            }
            $this->transaction->commit();
        } catch (ConflictException $e) {
            // 承認済み・別の部屋の予約と重なる・昼食と弁当の同時予約（有効にする更新）
            $this->transaction->rollback();
            return [
                'ok' => false,
                'message' => $e->getMessage(),
            ];
        } catch (ReservationValidationException $e) {
            // 新しい行の検証ルール（昼食と弁当の同時予約など）に当たった。理由を返す
            $this->transaction->rollback();
            $this->logger->error('bulkAdd validation error: ' . $e->getMessage());
            return [
                'ok' => false,
                'message' => $e->lunchBentoMessage ?? self::SAVE_ERROR_MESSAGE,
            ];
        } catch (\RuntimeException $e) {
            $this->transaction->rollback();
            if ($e->getMessage() === 'optimistic_conflict') {
                return [
                    'ok' => false,
                    'message' => '他の操作と競合しました。画面を再読み込みして再実行してください。',
                ];
            }
            $this->logger->error('bulkAdd runtime error: ' . $e->getMessage());
            return [
                'ok' => false,
                'message' => self::SAVE_ERROR_MESSAGE,
            ];
        } catch (\Throwable $e) {
            $this->transaction->rollback();
            $this->logger->error('bulkAdd error: ' . $e->getMessage());
            return [
                'ok' => false,
                'message' => self::SAVE_ERROR_MESSAGE,
            ];
        }

        return null;
    }

    /**
     * 予約が変わった日付・利用者のキャッシュ（食数・当日の報告済み印・レポート）を捨てる。
     *
     * @param array<string, mixed> $plan
     */
    private function forgetCaches(array $plan, int $userId): void
    {
        $userIdsByDate = [];
        // 個人: 選んだ日付の自分の予約
        foreach ($plan['selectedDates'] as $date) {
            $userIdsByDate[(string)$date] = [$userId];
        }
        // 部屋単位（day_users）: 日付ごとに送られた利用者
        foreach ($plan['dayUsers'] as $date => $usersByDate) {
            $userIdsByDate[(string)$date] = is_array($usersByDate) ? array_map('intval', array_keys($usersByDate)) : [];
        }
        // 部屋単位（古い画面の users）: 選んだ日付すべての、送られた利用者
        if ($plan['dayUsers'] === [] && $plan['users'] !== []) {
            $userIds = array_map('intval', array_keys($plan['users']));
            foreach (array_keys(array_filter($plan['dates'])) as $date) {
                $userIdsByDate[(string)$date] = $userIds;
            }
        }
        if ($userIdsByDate !== []) {
            $this->cache->forgetDates($userIdsByDate);
        }
    }
}
