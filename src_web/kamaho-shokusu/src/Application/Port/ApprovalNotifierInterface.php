<?php
declare(strict_types=1);

namespace App\Application\Port;

use App\Domain\ValueObject\ApprovalKey;

/**
 * 承認の通知（差し戻しを予約した本人へ知らせる。実装は Infrastructure）。
 */
interface ApprovalNotifierInterface
{
    /**
     * 差し戻した予約の本人へ通知を作る。差し戻しと同じトランザクションの中で呼ぶ。
     *
     * @param list<ApprovalKey> $keys 差し戻した予約
     * @throws \RuntimeException 通知を保存できないとき（差し戻しも取り消す）
     */
    public function rejected(array $keys, int $approverId, ?string $reason, string $at): void;
}
