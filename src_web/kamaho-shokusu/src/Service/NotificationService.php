<?php
declare(strict_types=1);

namespace App\Service;

use Cake\I18n\DateTime;
use Cake\ORM\TableRegistry;

class NotificationService
{
    public const string TYPE_APPROVAL_REJECTED = 'approval_rejected';

    public function createRejectionNotifications(
        array $keys,
        int $approverId,
        ?string $reason,
        ?DateTime $createdAt = null
    ): void {
        if (empty($keys)) {
            return;
        }

        $createdAt = $createdAt ?? DateTime::now();
        $notificationTable = TableRegistry::getTableLocator()->get('TNotification');
        $individualTable = TableRegistry::getTableLocator()->get('TIndividualReservationInfo');
        $userTable = TableRegistry::getTableLocator()->get('MUserInfo');

        $approver = $userTable->find()
            ->select(['i_id_user', 'c_user_name', 'c_login_account'])
            ->where(['i_id_user' => $approverId])
            ->first();
        $approverName = (string)($approver?->c_user_name ?? $approver?->c_login_account ?? $approverId);

        $rows = $individualTable->find()
            ->contain(['MUserInfo', 'MRoomInfo'])
            ->where(function ($exp) use ($keys) {
                $or = [];
                foreach ($keys as $key) {
                    $or[] = [
                        'TIndividualReservationInfo.i_id_user' => $key['i_id_user'] ?? null,
                        'TIndividualReservationInfo.d_reservation_date' => $key['d_reservation_date'] ?? null,
                        'TIndividualReservationInfo.i_id_room' => $key['i_id_room'] ?? null,
                        'TIndividualReservationInfo.i_reservation_type' => $key['i_reservation_type'] ?? null,
                    ];
                }

                return $exp->or($or);
            })
            ->all()
            ->toArray();

        if (empty($rows)) {
            return;
        }

        $grouped = [];
        foreach ($rows as $row) {
            $userId = (int)$row->i_id_user;
            $grouped[$userId]['user_name'] = (string)($row->m_user_info->c_user_name ?? '');
            $grouped[$userId]['items'][] = $this->formatItemSummary($row);
        }

        foreach ($grouped as $userId => $group) {
            $itemCount = count($group['items']);
            $summary = implode('、', array_slice($group['items'], 0, 3));
            if ($itemCount > 3) {
                $summary .= sprintf(' ほか%d件', $itemCount - 3);
            }

            $message = sprintf(
                '%s により予約 %d件 が差し戻されました。対象: %s',
                $approverName,
                $itemCount,
                $summary
            );
            if ($reason !== null && trim($reason) !== '') {
                $message .= ' / 理由: ' . trim($reason);
            }

            $notification = $notificationTable->newEntity([
                'i_id_user' => $userId,
                'c_notification_type' => self::TYPE_APPROVAL_REJECTED,
                'c_title' => '予約が差し戻されました',
                'c_message' => mb_strimwidth($message, 0, 255, '...'),
                'c_link' => '/TReservationInfo',
                'i_is_read' => 0,
                'dt_create' => $createdAt,
            ]);
            $notificationTable->saveOrFail($notification);
        }
    }

    public function getUnreadCount(int $userId): int
    {
        if ($userId <= 0) {
            return 0;
        }

        $notificationTable = TableRegistry::getTableLocator()->get('TNotification');

        return $notificationTable->find()
            ->where(['i_id_user' => $userId, 'i_is_read' => 0])
            ->count();
    }

    public function getRecentNotifications(int $userId, int $limit = 5): array
    {
        if ($userId <= 0) {
            return [];
        }

        $notificationTable = TableRegistry::getTableLocator()->get('TNotification');

        return $notificationTable->find()
            ->where(['i_id_user' => $userId])
            ->order(['dt_create' => 'DESC'])
            ->limit($limit)
            ->all()
            ->toArray();
    }

    public function getNotifications(int $userId, int $limit = 50): array
    {
        if ($userId <= 0) {
            return [];
        }

        $notificationTable = TableRegistry::getTableLocator()->get('TNotification');

        return $notificationTable->find()
            ->where(['i_id_user' => $userId])
            ->order(['dt_create' => 'DESC'])
            ->limit($limit)
            ->all()
            ->toArray();
    }

    public function markAsRead(int $userId, array $notificationIds): int
    {
        $notificationIds = array_values(array_unique(array_map('intval', $notificationIds)));
        if ($userId <= 0 || empty($notificationIds)) {
            return 0;
        }

        $notificationTable = TableRegistry::getTableLocator()->get('TNotification');

        return $notificationTable->updateAll(
            ['i_is_read' => 1, 'dt_read' => DateTime::now()],
            ['i_id_user' => $userId, 'i_id_notification IN' => $notificationIds]
        );
    }

    public function markAllAsRead(int $userId): int
    {
        if ($userId <= 0) {
            return 0;
        }

        $notificationTable = TableRegistry::getTableLocator()->get('TNotification');

        return $notificationTable->updateAll(
            ['i_is_read' => 1, 'dt_read' => DateTime::now()],
            ['i_id_user' => $userId, 'i_is_read' => 0]
        );
    }

    private function formatItemSummary(object $row): string
    {
        $mealLabels = [1 => '朝', 2 => '昼', 3 => '夕', 4 => '弁当'];
        $date = method_exists($row->d_reservation_date, 'format')
            ? $row->d_reservation_date->format('Y-m-d')
            : (string)$row->d_reservation_date;
        $roomName = (string)($row->m_room_info->c_room_name ?? '不明な部屋');
        $meal = $mealLabels[(int)$row->i_reservation_type] ?? '不明';

        return sprintf('%s %s %s', $date, $roomName, $meal);
    }
}
