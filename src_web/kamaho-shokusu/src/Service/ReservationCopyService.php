<?php
declare(strict_types=1);

namespace App\Service;

use Cake\I18n\FrozenDate;
use Cake\Datasource\ConnectionManager;

class ReservationCopyService
{
    public function copyWeek(
        FrozenDate $sourceStart,
        FrozenDate $targetStart,
                   $roomId,
        bool $overwrite,
                   $user,
        bool $onlyChildren = false // 追加
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
            if ($roomId) {
                $roomWhere = ' AND iri.i_id_room = :room_id';
                $params['room_id'] = $roomId;
            }
            $childJoin = '';
            if ($onlyChildren) {
                $childJoin = ' INNER JOIN m_user_info mu ON iri.i_id_user = mu.i_id_user AND mu.i_user_level = 1 ';
            }

            $rows = $conn->execute(
                "SELECT iri.i_id_user, iri.d_reservation_date, iri.i_reservation_type, iri.i_id_room,
                        iri.eat_flag, iri.i_change_flag
                   FROM t_individual_reservation_info iri
                   {$childJoin}
                  WHERE iri.d_reservation_date BETWEEN :src_from AND :src_to {$roomWhere}",
                $params
            )->fetchAll('assoc');

            $now = date('Y-m-d H:i:s');
            $actor = $user ? ($user->get('c_login_id') ?? $user->get('i_id_user') ?? 'system') : 'system';

            foreach ($rows as $r) {
                $diffDays = (new FrozenDate($r['d_reservation_date']))->diffInDays($srcFrom);
                $newDate  = $dstFrom->addDays($diffDays)->format('Y-m-d');

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
                    if (!$overwrite) continue;
                    $same = ((int)$exists['eat_flag'] === (int)$r['eat_flag'])
                        && ((int)$exists['i_change_flag'] === (int)$r['i_change_flag']);
                    if ($same) continue;
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

    public function copyMonth(
        FrozenDate $sourceStart,
        FrozenDate $targetStart,
                   $roomId,
        bool $overwrite,
                   $user,
        bool $onlyChildren = false // 追加
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
                $roomWhere = ' AND iri.i_id_room = :room_id';
                $params['room_id'] = $roomId;
            }
            $childJoin = '';
            if ($onlyChildren) {
                $childJoin = ' INNER JOIN m_user_info mu ON iri.i_id_user = mu.i_id_user AND mu.i_user_level = 1 ';
            }

            $rows = $conn->execute(
                "SELECT iri.i_id_user, iri.d_reservation_date, iri.i_reservation_type, iri.i_id_room,
                        iri.eat_flag, iri.i_change_flag
                   FROM t_individual_reservation_info iri
                   {$childJoin}
                  WHERE iri.d_reservation_date BETWEEN :src_from AND :src_to {$roomWhere}",
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
                    if (!$overwrite) continue;
                    $same = ((int)$exists['eat_flag'] === (int)$r['eat_flag'])
                        && ((int)$exists['i_change_flag'] === (int)$r['i_change_flag']);
                    if ($same) continue;
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
