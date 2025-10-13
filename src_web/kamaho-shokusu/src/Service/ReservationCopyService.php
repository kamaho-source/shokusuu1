<?php
declare(strict_types=1);

namespace App\Service;

use Cake\I18n\FrozenDate;
use Cake\Datasource\ConnectionManager;

class ReservationCopyService
{
    /**
     * 週コピー: sourceStart(週の月曜) → targetStart(週の月曜)
     * PK: (i_id_user, d_reservation_date, i_id_room, i_reservation_type)
     */
    public function copyWeek(
        FrozenDate $sourceStart,
        FrozenDate $targetStart,
                   $roomId,
        bool $overwrite,
                   $user
    ): int {
        $srcFrom = $sourceStart;
        $srcTo   = $sourceStart->addDays(6);
        $dstFrom = $targetStart;

        $conn = ConnectionManager::get('default');
        $conn->begin();
        try {
            $affected = 0;

            $params = [
                'src_from' => $srcFrom->format('Y-m-d'),
                'src_to'   => $srcTo->format('Y-m-d'),
            ];
            $roomWhere = '';
            if ($roomId) { // 指定があればその部屋のみコピー
                $roomWhere = ' AND i_id_room = :room_id';
                $params['room_id'] = $roomId;
            }

            // 1食種=1行ベースの全行を取得
            $rows = $conn->execute(
                "SELECT i_id_user, d_reservation_date, i_reservation_type, i_id_room,
                        eat_flag, i_change_flag
                   FROM t_individual_reservation_info
                  WHERE d_reservation_date BETWEEN :src_from AND :src_to {$roomWhere}",
                $params
            )->fetchAll('assoc');

            $now = date('Y-m-d H:i:s');
            $actor = $user ? ($user->get('c_login_id') ?? $user->get('i_id_user') ?? 'system') : 'system';

            foreach ($rows as $r) {
                // 元週の月曜からの差分をターゲット週に適用
                $diffDays = (new FrozenDate($r['d_reservation_date']))->diffInDays($srcFrom);
                $newDate  = $dstFrom->addDays($diffDays)->format('Y-m-d');

                $key = [
                    'd'   => $newDate,
                    'uid' => $r['i_id_user'],
                    'rid' => $r['i_id_room'],
                    'typ' => $r['i_reservation_type'],
                ];

                // 既存行確認（i_reservation_type を含める）
                $exists = $conn->execute(
                    "SELECT eat_flag, i_change_flag
                       FROM t_individual_reservation_info
                      WHERE d_reservation_date = :d
                        AND i_id_user          = :uid
                        AND i_id_room          = :rid
                        AND i_reservation_type = :typ",
                    $key
                )->fetch('assoc');

                if ($exists) {
                    if (!$overwrite) {
                        // 上書きしない場合、スキップ
                        continue;
                    }
                    // 値に変化が無ければ更新しない
                    $same = ((int)$exists['eat_flag'] === (int)$r['eat_flag'])
                        && ((int)$exists['i_change_flag'] === (int)$r['i_change_flag']);
                    if ($same) {
                        continue;
                    }
                    $conn->execute(
                        "UPDATE t_individual_reservation_info
                            SET eat_flag = :eat,
                                i_change_flag = :chg,
                                dt_update = :upd_at,
                                c_update_user = :upd_by
                          WHERE d_reservation_date = :d
                            AND i_id_user          = :uid
                            AND i_id_room          = :rid
                            AND i_reservation_type = :typ",
                        [
                            'eat'    => $r['eat_flag'],
                            'chg'    => $r['i_change_flag'],
                            'upd_at' => $now,
                            'upd_by' => (string)$actor,
                        ] + $key
                    );
                    $affected++;
                } else {
                    // 新規 INSERT
                    $conn->execute(
                        "INSERT INTO t_individual_reservation_info
                           (i_id_user, d_reservation_date, i_reservation_type, i_id_room,
                            eat_flag, i_change_flag, dt_create, c_create_user)
                         VALUES
                           (:uid, :d, :typ, :rid, :eat, :chg, :crt_at, :crt_by)",
                        [
                            'uid'    => $r['i_id_user'],
                            'd'      => $newDate,
                            'typ'    => $r['i_reservation_type'],
                            'rid'    => $r['i_id_room'],
                            'eat'    => $r['eat_flag'],
                            'chg'    => $r['i_change_flag'],
                            'crt_at' => $now,
                            'crt_by' => (string)$actor,
                        ]
                    );
                    $affected++;
                }
            }

            $conn->commit();
            return $affected;
        } catch (\Throwable $e) {
            $conn->rollback();
            throw $e;
        }
    }

    /**
     * 月コピー: sourceStart(月初) → targetStart(月初)
     * その月の同じ“日”にコピーします。
     */
    public function copyMonth(
        FrozenDate $sourceStart,
        FrozenDate $targetStart,
                   $roomId,
        bool $overwrite,
                   $user
    ): int {
        $from = new FrozenDate($sourceStart->format('Y-m-01'));
        $to   = $sourceStart->endOfMonth();

        $conn = ConnectionManager::get('default');
        $conn->begin();
        try {
            $total = 0;

            $params = [
                'src_from' => $from->format('Y-m-d'),
                'src_to'   => $to->format('Y-m-d'),
            ];
            $roomWhere = '';
            if ($roomId) {
                $roomWhere = ' AND i_id_room = :room_id';
                $params['room_id'] = $roomId;
            }

            $rows = $conn->execute(
                "SELECT i_id_user, d_reservation_date, i_reservation_type, i_id_room,
                        eat_flag, i_change_flag
                   FROM t_individual_reservation_info
                  WHERE d_reservation_date BETWEEN :src_from AND :src_to {$roomWhere}",
                $params
            )->fetchAll('assoc');

            $now = date('Y-m-d H:i:s');
            $actor = $user ? ($user->get('c_login_id') ?? $user->get('i_id_user') ?? 'system') : 'system';

            foreach ($rows as $r) {
                $srcDate = new FrozenDate($r['d_reservation_date']);
                $day     = (int)$srcDate->format('d');
                $newDate = (new FrozenDate($targetStart->format('Y-m-01')))->addDays($day - 1)->format('Y-m-d');

                $key = [
                    'd'   => $newDate,
                    'uid' => $r['i_id_user'],
                    'rid' => $r['i_id_room'],
                    'typ' => $r['i_reservation_type'],
                ];

                $exists = $conn->execute(
                    "SELECT eat_flag, i_change_flag
                       FROM t_individual_reservation_info
                      WHERE d_reservation_date = :d
                        AND i_id_user          = :uid
                        AND i_id_room          = :rid
                        AND i_reservation_type = :typ",
                    $key
                )->fetch('assoc');

                if ($exists) {
                    if (!$overwrite) {
                        continue;
                    }
                    $same = ((int)$exists['eat_flag'] === (int)$r['eat_flag'])
                        && ((int)$exists['i_change_flag'] === (int)$r['i_change_flag']);
                    if ($same) {
                        continue;
                    }
                    $conn->execute(
                        "UPDATE t_individual_reservation_info
                            SET eat_flag = :eat,
                                i_change_flag = :chg,
                                dt_update = :upd_at,
                                c_update_user = :upd_by
                          WHERE d_reservation_date = :d
                            AND i_id_user          = :uid
                            AND i_id_room          = :rid
                            AND i_reservation_type = :typ",
                        [
                            'eat'    => $r['eat_flag'],
                            'chg'    => $r['i_change_flag'],
                            'upd_at' => $now,
                            'upd_by' => (string)$actor,
                        ] + $key
                    );
                    $total++;
                } else {
                    $conn->execute(
                        "INSERT INTO t_individual_reservation_info
                           (i_id_user, d_reservation_date, i_reservation_type, i_id_room,
                            eat_flag, i_change_flag, dt_create, c_create_user)
                         VALUES
                           (:uid, :d, :typ, :rid, :eat, :chg, :crt_at, :crt_by)",
                        [
                            'uid'    => $r['i_id_user'],
                            'd'      => $newDate,
                            'typ'    => $r['i_reservation_type'],
                            'rid'    => $r['i_id_room'],
                            'eat'    => $r['eat_flag'],
                            'chg'    => $r['i_change_flag'],
                            'crt_at' => $now,
                            'crt_by' => (string)$actor,
                        ]
                    );
                    $total++;
                }
            }

            $conn->commit();
            return $total;
        } catch (\Throwable $e) {
            $conn->rollback();
            throw $e;
        }
    }
}
