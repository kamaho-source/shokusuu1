<?php
declare(strict_types=1);

namespace App\Service;

use Cake\ORM\TableRegistry;
use Cake\Log\Log;

class RoomAccessService
{
    /**
     * @return array<int>
     */
    public function getUserRoomIds(int $userId): array
    {
        if ($userId <= 0) {
            return [];
        }

        try {
            $table = TableRegistry::getTableLocator()->get('MUserGroup');
            $rows = $table->find()
                ->select(['i_id_room'])
                ->where(['i_id_user' => $userId, 'active_flag' => 0])
                ->all();

            $roomIds = [];
            foreach ($rows as $row) {
                $roomId = (int)$row->i_id_room;
                if ($roomId > 0) {
                    $roomIds[] = $roomId;
                }
            }

            return $roomIds;
        } catch (\Throwable $e) {
            Log::error('RoomAccessService#getUserRoomIds failed: ' . $e->getMessage());
            return [];
        }
    }
}
