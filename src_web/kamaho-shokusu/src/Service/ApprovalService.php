<?php
declare(strict_types=1);

namespace App\Service;

use Cake\I18n\DateTime;
use Cake\ORM\TableRegistry;

/**
 * 承認フローサービス
 *
 * 承認ステータス定義:
 *   t_individual_reservation_info.i_approval_status
 *     0 = 未承認
 *     1 = ブロック長承認済
 *     2 = 管理者承認済（最終）
 *     3 = 差し戻し
 *
 *   t_approval_log.i_approval_status
 *     1 = ブロック長承認
 *     2 = 管理者承認（最終）
 *     3 = 差し戻し
 */
class ApprovalService
{
    // 承認ステータス定数
    public const STATUS_PENDING          = 0;
    public const STATUS_BLOCK_LEADER     = 1;
    public const STATUS_ADMIN            = 2;
    public const STATUS_REJECTED         = 3;

    private RoomAccessService $roomAccessService;
    private NotificationService $notificationService;

    public function __construct(
        ?RoomAccessService $roomAccessService = null,
        ?NotificationService $notificationService = null
    )
    {
        $this->roomAccessService = $roomAccessService ?? new RoomAccessService();
        $this->notificationService = $notificationService ?? new NotificationService();
    }

    /**
     * ブロック長用：担当ブロックの承認一覧を取得
     *
     * @param int        $userId         ブロック長のユーザーID
     * @param int|null   $filterRoomId   ブロック絞り込み（null = 全担当ブロック）
     * @param string|null $dateFrom       日付範囲 開始 (YYYY-MM-DD)
     * @param string|null $dateTo         日付範囲 終了 (YYYY-MM-DD)
     * @param int|null   $filterStatus   承認ステータス絞り込み（null = 全て）
     * @return array
     */
    public function getBlockLeaderList(
        int $userId,
        ?int $filterRoomId = null,
        ?string $dateFrom = null,
        ?string $dateTo = null,
        ?int $filterStatus = null
    ): array {
        $roomIds = $this->roomAccessService->getUserRoomIds($userId);
        if (empty($roomIds)) {
            return [];
        }

        if ($filterRoomId !== null && in_array($filterRoomId, $roomIds, true)) {
            $roomIds = [$filterRoomId];
        }

        $table = TableRegistry::getTableLocator()->get('TIndividualReservationInfo');
        $query = $table->find()
            ->contain(['MUserInfo', 'MRoomInfo'])
            ->where(['TIndividualReservationInfo.i_id_room IN' => $roomIds]);

        if ($dateFrom !== null) {
            $query->where(['TIndividualReservationInfo.d_reservation_date >=' => $dateFrom]);
        }
        if ($dateTo !== null) {
            $query->where(['TIndividualReservationInfo.d_reservation_date <=' => $dateTo]);
        }
        if ($filterStatus !== null) {
            $query->where(['TIndividualReservationInfo.i_approval_status' => $filterStatus]);
        } else {
            // デフォルト: 未承認のみ
            $query->where(['TIndividualReservationInfo.i_approval_status' => self::STATUS_PENDING]);
        }

        // 大人ユーザーのみ表示（職員 または i_user_level=7 の大人）
        // かつ、管理者・ブロック長本人の予約は除外（自己承認防止）
        $query->where([
            'OR' => [
                [
                    'MUserInfo.i_id_staff IS NOT' => null,
                    'MUserInfo.i_id_staff !='     => '',
                ],
                ['MUserInfo.i_user_level' => 7],
            ],
        ]);
        $query->where([
            'OR' => [
                ['MUserInfo.i_admin IS' => null],
                ['MUserInfo.i_admin'    => 0],
            ],
        ]);

        $query->order([
            'TIndividualReservationInfo.d_reservation_date' => 'ASC',
            'MRoomInfo.i_disp_no'                          => 'ASC',
            'MUserInfo.i_disp_no'                          => 'ASC',
            'TIndividualReservationInfo.i_reservation_type' => 'ASC',
        ]);

        return $query->all()->toArray();
    }

    /**
     * 管理者用：全ブロックの承認一覧を取得
     *
     * @param int|null   $filterRoomId   ブロック絞り込み（null = 全ブロック）
     * @param string|null $dateFrom
     * @param string|null $dateTo
     * @param int|null   $filterStatus   null = 全ステータス
     * @return array
     */
    public function getAdminList(
        ?int $filterRoomId = null,
        ?string $dateFrom = null,
        ?string $dateTo = null,
        ?int $filterStatus = null
    ): array {
        $table = TableRegistry::getTableLocator()->get('TIndividualReservationInfo');
        $query = $table->find()->contain(['MUserInfo', 'MRoomInfo']);

        if ($filterRoomId !== null) {
            $query->where(['TIndividualReservationInfo.i_id_room' => $filterRoomId]);
        }
        if ($dateFrom !== null) {
            $query->where(['TIndividualReservationInfo.d_reservation_date >=' => $dateFrom]);
        }
        if ($dateTo !== null) {
            $query->where(['TIndividualReservationInfo.d_reservation_date <=' => $dateTo]);
        }
        if ($filterStatus !== null) {
            $query->where(['TIndividualReservationInfo.i_approval_status' => $filterStatus]);
        }

        // 大人ユーザーのみ表示（職員 または i_user_level=7 の大人）
        // かつ、管理者・ブロック長本人の予約は除外（自己承認防止）
        $query->where([
            'OR' => [
                [
                    'MUserInfo.i_id_staff IS NOT' => null,
                    'MUserInfo.i_id_staff !='     => '',
                ],
                ['MUserInfo.i_user_level' => 7],
            ],
        ]);
        $query->where([
            'OR' => [
                ['MUserInfo.i_admin IS' => null],
                ['MUserInfo.i_admin'    => 0],
            ],
        ]);

        $query->order([
            'TIndividualReservationInfo.d_reservation_date' => 'ASC',
            'MRoomInfo.i_disp_no'                          => 'ASC',
            'MUserInfo.i_disp_no'                          => 'ASC',
            'TIndividualReservationInfo.i_reservation_type' => 'ASC',
        ]);

        return $query->all()->toArray();
    }

    /**
     * 管理者用：日付別・ブロック別・食種別の集計サマリを取得
     *
     * @param string|null $dateFrom
     * @param string|null $dateTo
     * @return array  [ ['reservation_date' => 'YYYY-MM-DD', 'room_name' => '...', 'breakfast' => n, ...], ... ]
     */
    public function getAdminSummary(?string $dateFrom = null, ?string $dateTo = null): array
    {
        $table = TableRegistry::getTableLocator()->get('TIndividualReservationInfo');
        $query = $table->find()
            ->contain(['MRoomInfo'])
            ->where(['TIndividualReservationInfo.i_approval_status' => self::STATUS_ADMIN]);

        if ($dateFrom !== null) {
            $query->where(['TIndividualReservationInfo.d_reservation_date >=' => $dateFrom]);
        }
        if ($dateTo !== null) {
            $query->where(['TIndividualReservationInfo.d_reservation_date <=' => $dateTo]);
        }

        $rows = $query->all()->toArray();

        $summary = [];
        foreach ($rows as $row) {
            $reservationDate = $row->d_reservation_date instanceof \DateTimeInterface
                ? $row->d_reservation_date->format('Y-m-d')
                : (string)$row->d_reservation_date;
            $roomName = $row->m_room_info->c_room_name ?? '不明';
            $groupKey = $reservationDate . '|' . $roomName;
            if (!isset($summary[$groupKey])) {
                $summary[$groupKey] = [
                    'reservation_date' => $reservationDate,
                    'room_name' => $roomName,
                    'breakfast' => 0,
                    'lunch' => 0,
                    'dinner' => 0,
                    'bento' => 0,
                ];
            }
            if ((int)$row->eat_flag !== 1) {
                continue;
            }
            switch ((int)$row->i_reservation_type) {
                case 1: $summary[$groupKey]['breakfast']++; break;
                case 2: $summary[$groupKey]['lunch']++;     break;
                case 3: $summary[$groupKey]['dinner']++;    break;
                case 4: $summary[$groupKey]['bento']++;     break;
            }
        }

        uasort($summary, static function (array $a, array $b): int {
            if ($a['reservation_date'] === $b['reservation_date']) {
                return strcmp((string)$a['room_name'], (string)$b['room_name']);
            }

            return strcmp((string)$a['reservation_date'], (string)$b['reservation_date']);
        });

        return array_values($summary);
    }

    /**
     * ブロック長向けの未承認件数を返す。
     */
    public function countBlockLeaderPending(int $userId, ?string $dateFrom = null, ?string $dateTo = null): int
    {
        $roomIds = $this->roomAccessService->getUserRoomIds($userId);
        if (empty($roomIds)) {
            return 0;
        }

        $table = TableRegistry::getTableLocator()->get('TIndividualReservationInfo');
        $query = $table->find()
            ->contain(['MUserInfo'])
            ->where([
                'TIndividualReservationInfo.i_id_room IN' => $roomIds,
                'TIndividualReservationInfo.i_approval_status' => self::STATUS_PENDING,
            ]);

        if ($dateFrom !== null) {
            $query->where(['TIndividualReservationInfo.d_reservation_date >=' => $dateFrom]);
        }
        if ($dateTo !== null) {
            $query->where(['TIndividualReservationInfo.d_reservation_date <=' => $dateTo]);
        }

        // 大人ユーザーのみ（getBlockLeaderList と同条件）
        $query->where([
            'OR' => [
                ['MUserInfo.i_id_staff IS NOT' => null, 'MUserInfo.i_id_staff !=' => ''],
                ['MUserInfo.i_user_level' => 7],
            ],
        ]);
        $query->where([
            'OR' => [
                ['MUserInfo.i_admin IS' => null],
                ['MUserInfo.i_admin'    => 0],
            ],
        ]);

        return $query->count();
    }

    /**
     * 管理者向けの最終承認待ち件数を返す。
     */
    public function countAdminPending(?string $dateFrom = null, ?string $dateTo = null): int
    {
        $table = TableRegistry::getTableLocator()->get('TIndividualReservationInfo');
        $query = $table->find()
            ->contain(['MUserInfo'])
            ->where([
                'TIndividualReservationInfo.i_approval_status' => self::STATUS_BLOCK_LEADER,
            ]);

        if ($dateFrom !== null) {
            $query->where(['TIndividualReservationInfo.d_reservation_date >=' => $dateFrom]);
        }
        if ($dateTo !== null) {
            $query->where(['TIndividualReservationInfo.d_reservation_date <=' => $dateTo]);
        }

        // 大人ユーザーのみ（getAdminList と同条件）
        $query->where([
            'OR' => [
                ['MUserInfo.i_id_staff IS NOT' => null, 'MUserInfo.i_id_staff !=' => ''],
                ['MUserInfo.i_user_level' => 7],
            ],
        ]);
        $query->where([
            'OR' => [
                ['MUserInfo.i_admin IS' => null],
                ['MUserInfo.i_admin'    => 0],
            ],
        ]);

        return $query->count();
    }

    /**
     * ブロック長による承認（ステータスを STATUS_BLOCK_LEADER に更新）
     *
     * @param array $keys  [['i_id_user'=>x, 'd_reservation_date'=>'...', 'i_id_room'=>x, 'i_reservation_type'=>x], ...]
     * @param int   $approverId
     * @param string $actor
     * @return bool
     */
    public function blockLeaderApprove(array $keys, int $approverId, string $actor): bool
    {
        return $this->updateApprovalStatus($keys, self::STATUS_BLOCK_LEADER, $approverId, $actor, null, [self::STATUS_PENDING]);
    }

    /**
     * 管理者による最終承認（ステータスを STATUS_ADMIN に更新）
     *
     * @param array  $keys
     * @param int    $approverId
     * @param string $actor
     * @return bool
     */
    public function adminApprove(array $keys, int $approverId, string $actor): bool
    {
        return $this->updateApprovalStatus($keys, self::STATUS_ADMIN, $approverId, $actor, null, [self::STATUS_BLOCK_LEADER]);
    }

    /**
     * 差し戻し（ブロック長・管理者共通）
     *
     * @param array       $keys
     * @param int         $approverId
     * @param string      $actor
     * @param string|null $reason
     * @return bool
     */
    public function reject(array $keys, int $approverId, string $actor, ?string $reason): bool
    {
        return $this->updateApprovalStatus($keys, self::STATUS_REJECTED, $approverId, $actor, $reason, [self::STATUS_PENDING, self::STATUS_BLOCK_LEADER]);
    }

    /**
     * 最終承認済みレコードを t_reservation_info へ反映
     *
     * @param int|null    $roomId
     * @param string|null $date
     * @param string      $actor
     * @return int  更新された行数
     */
    public function reflectToReservation(?int $roomId, ?string $date, string $actor): int
    {
        $individualTable  = TableRegistry::getTableLocator()->get('TIndividualReservationInfo');
        $reservationTable = TableRegistry::getTableLocator()->get('TReservationInfo');
        $now = DateTime::now();

        $query = $individualTable->find()
            ->where(['i_approval_status' => self::STATUS_ADMIN]);

        if ($roomId !== null) {
            $query->where(['i_id_room' => $roomId]);
        }
        if ($date !== null) {
            $query->where(['d_reservation_date' => $date]);
        }

        $rows = $query->all()->toArray();
        if (empty($rows)) {
            return 0;
        }

        // ブロック × 日付 × 食種 ごとに集計
        $grouped = [];
        foreach ($rows as $row) {
            $key = $row->i_id_room . '_' . $row->d_reservation_date . '_' . $row->i_reservation_type;
            if (!isset($grouped[$key])) {
                $grouped[$key] = [
                    'i_id_room'          => $row->i_id_room,
                    'd_reservation_date' => $row->d_reservation_date,
                    'c_reservation_type' => $row->i_reservation_type,
                    'i_taberu_ninzuu'    => 0,
                    'i_tabenai_ninzuu'   => 0,
                ];
            }
            if ((int)$row->eat_flag === 1) {
                $grouped[$key]['i_taberu_ninzuu']++;
            } else {
                $grouped[$key]['i_tabenai_ninzuu']++;
            }
        }

        $updated = 0;
        foreach ($grouped as $data) {
            $existing = $reservationTable->find()->where([
                'd_reservation_date' => $data['d_reservation_date'],
                'i_id_room'          => $data['i_id_room'],
                'c_reservation_type' => $data['c_reservation_type'],
            ])->first();

            if ($existing) {
                $reservationTable->patchEntity($existing, [
                    'i_taberu_ninzuu'  => $data['i_taberu_ninzuu'],
                    'i_tabenai_ninzuu' => $data['i_tabenai_ninzuu'],
                    'dt_update'        => $now,
                    'c_update_user'    => $actor,
                ]);
                $reservationTable->save($existing);
            } else {
                $entity = $reservationTable->newEntity(array_merge($data, [
                    'dt_create'    => $now,
                    'c_create_user' => $actor,
                    'dt_update'    => $now,
                    'c_update_user' => $actor,
                ]));
                $reservationTable->save($entity);
            }
            $updated++;
        }

        return $updated;
    }

    // ------------------------------------------------------------------
    // private helpers
    // ------------------------------------------------------------------

    private function updateApprovalStatus(
        array $keys,
        int $newStatus,
        int $approverId,
        string $actor,
        ?string $reason,
        array $allowedFromStatuses
    ): bool {
        $individualTable = TableRegistry::getTableLocator()->get('TIndividualReservationInfo');
        $logTable        = TableRegistry::getTableLocator()->get('TApprovalLog');
        $now             = DateTime::now();

        return $individualTable->getConnection()->transactional(
            function () use ($keys, $newStatus, $approverId, $actor, $reason, $allowedFromStatuses, $individualTable, $logTable, $now): bool {
                foreach ($keys as $k) {
                    $affected = $individualTable->updateAll(
                        ['i_approval_status' => $newStatus, 'dt_update' => $now, 'c_update_user' => $actor],
                        [
                            'i_id_user'          => $k['i_id_user'],
                            'd_reservation_date' => $k['d_reservation_date'],
                            'i_id_room'          => $k['i_id_room'],
                            'i_reservation_type' => $k['i_reservation_type'],
                            'i_approval_status IN' => $allowedFromStatuses,
                        ]
                    );
                    if ($affected < 1) {
                        return false;
                    }

                    $log = $logTable->newEntity([
                        'i_id_user'          => $k['i_id_user'],
                        'd_reservation_date' => $k['d_reservation_date'],
                        'i_id_room'          => $k['i_id_room'],
                        'i_reservation_type' => $k['i_reservation_type'],
                        'i_approval_status'  => $newStatus,
                        'i_approver_id'      => $approverId,
                        'c_reject_reason'    => $reason,
                        'dt_create'          => $now,
                    ]);
                    $logTable->saveOrFail($log);
                }

                if ($newStatus === self::STATUS_REJECTED) {
                    $this->notificationService->createRejectionNotifications($keys, $approverId, $reason, $now);
                }

                return true;
            }
        );
    }
}
