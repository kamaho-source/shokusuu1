<?php
declare(strict_types=1);

namespace App\Infrastructure\Notification;

use App\Application\Port\ApprovalNotifierInterface;
use App\Domain\ValueObject\ApprovalKey;
use App\Service\NotificationService;
use Cake\I18n\DateTime;

/**
 * ApprovalNotifierInterface の実装。既存の NotificationService（t_notification への書き込み）に渡す。
 */
final class CakeApprovalNotifier implements ApprovalNotifierInterface
{
    public function __construct(private readonly NotificationService $notifications)
    {
    }

    public function rejected(array $keys, int $approverId, ?string $reason, string $at): void
    {
        $this->notifications->createRejectionNotifications(
            array_map(static fn(ApprovalKey $key): array => $key->toArray(), $keys),
            $approverId,
            $reason,
            new DateTime($at)
        );
    }
}
