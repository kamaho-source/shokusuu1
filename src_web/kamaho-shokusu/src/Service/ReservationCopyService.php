<?php
declare(strict_types=1);

namespace App\Service;

use Cake\I18n\FrozenDate;
use Cake\Log\Log;
use Cake\ORM\Table;
use Cake\ORM\TableRegistry;

class ReservationCopyService
{
    /** @var \App\Model\Table\TIndividualReservationInfoTable */
    private Table $TIndividualReservationInfo;
    /** @var \App\Model\Table\MUserInfoTable */
    private Table $MUserInfo;

    public function __construct()
    {
        $this->TIndividualReservationInfo = TableRegistry::getTableLocator()->get('TIndividualReservationInfo');
        $this->MUserInfo                  = TableRegistry::getTableLocator()->get('MUserInfo');
    }

    public function copyWeek(
        FrozenDate $srcMonday,
        FrozenDate $dstMonday,
        ?int $roomId,
        bool $overwrite,
                   $actor,
        bool $onlyChildren
    ): array {
        // 差分日数で同じ曜日にマップ
        $offsetDays = (int)$srcMonday->diff($dstMonday)->days * ($srcMonday <= $dstMonday ? 1 : -1);
        $srcStart   = $srcMonday;
        $srcEnd     = $srcMonday->addDays(6);

        return $this->copyRangeByOffset($srcStart, $srcEnd, $offsetDays, $roomId, $onlyChildren, $actor);
    }

    public function copyMonth(
        FrozenDate $srcMonthFirst,
        FrozenDate $dstMonthFirst,
        ?int $roomId,
        bool $overwrite,
                   $actor,
        
        bool $onlyChildren
    ): array {
        // 月内の日付を同じ「日」にマップ（存在しない日はスキップ）
        $srcStart = new FrozenDate($srcMonthFirst->format('Y-m-01'));
        $srcEnd   = new FrozenDate($srcStart->format('Y-m-t')); // 月末
        return $this->copyMonthSameDay($srcStart, $dstMonthFirst, $roomId, $onlyChildren, $actor);
    }

    private function copyRangeByOffset(
        FrozenDate $srcStart,
        FrozenDate $srcEnd,
        int $offsetDays,
        ?int $roomId,
        bool $onlyChildren,
        $actor = null
    ): array {
        $childIds = $onlyChildren ? $this->getChildUserIds() : null;

        Log::debug('[copyRangeByOffset] srcStart=' . $srcStart->format('Y-m-d') . ', srcEnd=' . $srcEnd->format('Y-m-d') . ', offsetDays=' . $offsetDays . ', roomId=' . ($roomId ?? 'null') . ', onlyChildren=' . ($onlyChildren ? 'true' : 'false'));
        
        if ($childIds !== null) {
            Log::debug('[copyRangeByOffset] childIds count: ' . count($childIds));
        }

        $conditions = [
            'd_reservation_date >=' => $srcStart->format('Y-m-d'),
            'd_reservation_date <=' => $srcEnd->format('Y-m-d'),
        ];
        if ($roomId !== null) {
            $conditions['i_id_room'] = $roomId;
        }
        if ($childIds !== null) {
            if (empty($childIds)) {
                Log::debug('[copyRangeByOffset] 子供がいないため終了');
                return ['total' => 0, 'copied' => 0, 'skipped' => 0];
            }
            $conditions['i_id_user IN'] = $childIds;
        }

        $rows = $this->TIndividualReservationInfo->find()
            ->select([
                'i_id_user',
                'i_id_room',
                'i_reservation_type',
                'd_reservation_date',
                'eat_flag',
                'i_change_flag',
            ])
            ->where($conditions)
            ->enableHydration(false)
            ->toArray();

        $total = count($rows);
        Log::debug('[copyRangeByOffset] コピー元データ件数: ' . $total);

        if (empty($rows)) {
            Log::debug('[copyRangeByOffset] コピー元データがないため終了');
            return ['total' => 0, 'copied' => 0, 'skipped' => 0];
        }

        $affected = 0;
        $skipped = 0;
        $conn = $this->TIndividualReservationInfo->getConnection();
        $conn->begin();
        try {
            foreach ($rows as $r) {
                $srcDate = new FrozenDate($r['d_reservation_date']);
                $dstDate = $srcDate->addDays($offsetDays);

                // 「既存は上書きしない」をサーバで強制
                $exists = $this->TIndividualReservationInfo->exists([
                    'i_id_user'           => (int)$r['i_id_user'],
                    'i_id_room'           => (int)$r['i_id_room'],
                    'd_reservation_date'  => $dstDate->format('Y-m-d'),
                    'i_reservation_type'  => (int)$r['i_reservation_type'],
                ]);
                if ($exists) {
                    $skipped++;
                    continue; // スキップ
                }

                $data = [
                    'i_id_user'           => (int)$r['i_id_user'],
                    'i_id_room'           => (int)$r['i_id_room'],
                    'd_reservation_date'  => $dstDate->format('Y-m-d'),
                    'i_reservation_type'  => (int)$r['i_reservation_type'],
                    // フラグは元データを踏襲（将来要件に応じて調整可）
                    'eat_flag'            => (int)($r['eat_flag'] ?? 1),
                    'i_change_flag'       => (int)($r['i_change_flag'] ?? 1),
                    'dt_create'           => new \Cake\I18n\FrozenTime(),
                ];
                if ($actor !== null && method_exists($actor, 'get')) {
                    $userName = $actor->get('c_user_name');
                    if ($userName) {
                        $data['c_create_user'] = (string)$userName;
                    }
                }
                $entity = $this->TIndividualReservationInfo->newEntity($data);

                if ($this->TIndividualReservationInfo->save($entity)) {
                    $affected++;
                } else {
                    Log::error('[copyRangeByOffset] 保存失敗: ' . json_encode($entity->getErrors()));
                }
            }
            $conn->commit();
            Log::debug('[copyRangeByOffset] 完了: total=' . $total . ', copied=' . $affected . ', skipped=' . $skipped);
        } catch (\Throwable $e) {
            $conn->rollback();
            Log::error('ReservationCopyService(copyRangeByOffset) failed: ' . $e->getMessage());
            throw $e;
        }

        return ['total' => $total, 'copied' => $affected, 'skipped' => $skipped];
    }

    private function copyMonthSameDay(
        FrozenDate $srcMonthFirst,
        FrozenDate $dstMonthFirst,
        ?int $roomId,
        bool $onlyChildren,
        $actor = null
    ): array {
        $childIds = $onlyChildren ? $this->getChildUserIds() : null;

        // 月の初日と末日を確実に設定
        $srcStart = new FrozenDate($srcMonthFirst->format('Y-m-01'));
        $srcEnd   = new FrozenDate($srcMonthFirst->format('Y-m-t'));
        $dstStart = new FrozenDate($dstMonthFirst->format('Y-m-01'));

        Log::debug('[copyMonthSameDay] srcStart=' . $srcStart->format('Y-m-d') . ', srcEnd=' . $srcEnd->format('Y-m-d') . ', dstStart=' . $dstStart->format('Y-m-d') . ', roomId=' . ($roomId ?? 'null') . ', onlyChildren=' . ($onlyChildren ? 'true' : 'false'));

        $conditions = [
            'd_reservation_date >=' => $srcStart->format('Y-m-d'),
            'd_reservation_date <=' => $srcEnd->format('Y-m-d'),
        ];
        if ($roomId !== null) {
            $conditions['i_id_room'] = $roomId;
        }
        if ($childIds !== null) {
            if (empty($childIds)) {
                Log::debug('[copyMonthSameDay] 子供がいないため終了');
                return ['total' => 0, 'copied' => 0, 'skipped' => 0, 'invalid_date' => 0];
            }
            $conditions['i_id_user IN'] = $childIds;
        }

        $rows = $this->TIndividualReservationInfo->find()
            ->select([
                'i_id_user',
                'i_id_room',
                'i_reservation_type',
                'd_reservation_date',
                'eat_flag',
                'i_change_flag',
            ])
            ->where($conditions)
            ->enableHydration(false)
            ->toArray();

        $total = count($rows);
        Log::debug('[copyMonthSameDay] コピー元データ件数: ' . $total);

        if (empty($rows)) {
            Log::debug('[copyMonthSameDay] コピー元データがないため終了');
            return ['total' => 0, 'copied' => 0, 'skipped' => 0, 'invalid_date' => 0];
        }

        $affected = 0;
        $skipped = 0;
        $invalidDate = 0;
        $conn = $this->TIndividualReservationInfo->getConnection();
        $conn->begin();
        try {
            foreach ($rows as $r) {
                $srcDate = new FrozenDate($r['d_reservation_date']);
                $day     = (int)$srcDate->format('d');

                // 変換先に同一日付が存在するか確認（例: 31日が無い月はスキップ）
                $dstStr = sprintf('%s-%02d', $dstMonthFirst->format('Y-m'), $day);
                try {
                    $dstDate = new FrozenDate($dstStr);
                    if ($dstDate->format('Y-m') !== $dstMonthFirst->format('Y-m')) {
                        $invalidDate++;
                        continue; // 月がずれた場合は無効
                    }
                } catch (\Throwable $e) {
                    $invalidDate++;
                    continue; // 不正日付はスキップ
                }

                // 既存は上書きしない（強制）
                $exists = $this->TIndividualReservationInfo->exists([
                    'i_id_user'           => (int)$r['i_id_user'],
                    'i_id_room'           => (int)$r['i_id_room'],
                    'd_reservation_date'  => $dstDate->format('Y-m-d'),
                    'i_reservation_type'  => (int)$r['i_reservation_type'],
                ]);
                if ($exists) {
                    $skipped++;
                    continue;
                }

                $data = [
                    'i_id_user'           => (int)$r['i_id_user'],
                    'i_id_room'           => (int)$r['i_id_room'],
                    'd_reservation_date'  => $dstDate->format('Y-m-d'),
                    'i_reservation_type'  => (int)$r['i_reservation_type'],
                    'eat_flag'            => (int)($r['eat_flag'] ?? 1),
                    'i_change_flag'       => (int)($r['i_change_flag'] ?? 1),
                    'dt_create'           => new \Cake\I18n\FrozenTime(),
                ];
                if ($actor !== null && method_exists($actor, 'get')) {
                    $userName = $actor->get('c_user_name');
                    if ($userName) {
                        $data['c_create_user'] = (string)$userName;
                    }
                }
                $entity = $this->TIndividualReservationInfo->newEntity($data);

                if ($this->TIndividualReservationInfo->save($entity)) {
                    $affected++;
                } else {
                    Log::error('[copyMonthSameDay] 保存失敗: ' . json_encode($entity->getErrors()));
                }
            }
            $conn->commit();
            Log::debug('[copyMonthSameDay] 完了: total=' . $total . ', copied=' . $affected . ', skipped=' . $skipped . ', invalidDate=' . $invalidDate);
        } catch (\Throwable $e) {
            $conn->rollback();
            Log::error('ReservationCopyService(copyMonthSameDay) failed: ' . $e->getMessage());
            throw $e;
        }

        return ['total' => $total, 'copied' => $affected, 'skipped' => $skipped, 'invalid_date' => $invalidDate];
    }

    /**
     * 子供ユーザーの ID を返す（i_user_level = 1 を子供とみなす）
     */
    private function getChildUserIds(): array
    {
        return $this->MUserInfo->find()
            ->select(['i_id_user'])
            ->where([
                'i_user_level' => 1,
                'i_del_flag' => 0,
            ])
            ->enableHydration(false)
            ->all()
            ->extract('i_id_user')
            ->toList();
    }

    /**
     * 週コピーの件数をプレビュー（実際にコピーせず件数だけカウント）
     */
    public function previewWeek(
        FrozenDate $srcMonday,
        FrozenDate $dstMonday,
        ?int $roomId,
        bool $onlyChildren
    ): array {
        $offsetDays = (int)$srcMonday->diff($dstMonday)->days * ($srcMonday <= $dstMonday ? 1 : -1);
        $srcStart   = $srcMonday;
        $srcEnd     = $srcMonday->addDays(6);

        return $this->previewRangeByOffset($srcStart, $srcEnd, $offsetDays, $roomId, $onlyChildren);
    }

    /**
     * 月コピーの件数をプレビュー（実際にコピーせず件数だけカウント）
     */
    public function previewMonth(
        FrozenDate $srcMonthFirst,
        FrozenDate $dstMonthFirst,
        ?int $roomId,
        bool $onlyChildren
    ): array {
        $srcStart = new FrozenDate($srcMonthFirst->format('Y-m-01'));
        $srcEnd   = new FrozenDate($srcStart->format('Y-m-t'));
        return $this->previewMonthSameDay($srcStart, $dstMonthFirst, $roomId, $onlyChildren);
    }

    /**
     * 週コピーのプレビュー内部処理（オフセットベース）
     */
    private function previewRangeByOffset(
        FrozenDate $srcStart,
        FrozenDate $srcEnd,
        int $offsetDays,
        ?int $roomId,
        bool $onlyChildren
    ): array {
        $childIds = $onlyChildren ? $this->getChildUserIds() : null;

        $conditions = [
            'd_reservation_date >=' => $srcStart->format('Y-m-d'),
            'd_reservation_date <=' => $srcEnd->format('Y-m-d'),
        ];
        if ($roomId !== null) {
            $conditions['i_id_room'] = $roomId;
        }
        if ($childIds !== null) {
            if (empty($childIds)) {
                return ['total' => 0, 'will_copy' => 0, 'will_skip' => 0];
            }
            $conditions['i_id_user IN'] = $childIds;
        }

        $rows = $this->TIndividualReservationInfo->find()
            ->select(['i_id_user', 'i_id_room', 'i_reservation_type', 'd_reservation_date'])
            ->where($conditions)
            ->enableHydration(false)
            ->toArray();

        $total = count($rows);
        $willCopy = 0;
        $willSkip = 0;

        foreach ($rows as $r) {
            $srcDate = new FrozenDate($r['d_reservation_date']);
            $dstDate = $srcDate->addDays($offsetDays);

            $exists = $this->TIndividualReservationInfo->exists([
                'i_id_user'           => (int)$r['i_id_user'],
                'i_id_room'           => (int)$r['i_id_room'],
                'd_reservation_date'  => $dstDate->format('Y-m-d'),
                'i_reservation_type'  => (int)$r['i_reservation_type'],
            ]);

            if ($exists) {
                $willSkip++;
            } else {
                $willCopy++;
            }
        }

        return [
            'total' => $total,
            'will_copy' => $willCopy,
            'will_skip' => $willSkip,
        ];
    }

    /**
     * 月コピーのプレビュー内部処理（同じ日ベース）
     */
    private function previewMonthSameDay(
        FrozenDate $srcMonthFirst,
        FrozenDate $dstMonthFirst,
        ?int $roomId,
        bool $onlyChildren
    ): array {
        $childIds = $onlyChildren ? $this->getChildUserIds() : null;

        $srcStart = new FrozenDate($srcMonthFirst->format('Y-m-01'));
        $srcEnd   = new FrozenDate($srcMonthFirst->format('Y-m-t'));
        $dstStart = new FrozenDate($dstMonthFirst->format('Y-m-01'));

        $conditions = [
            'd_reservation_date >=' => $srcStart->format('Y-m-d'),
            'd_reservation_date <=' => $srcEnd->format('Y-m-d'),
        ];
        if ($roomId !== null) {
            $conditions['i_id_room'] = $roomId;
        }
        if ($childIds !== null) {
            if (empty($childIds)) {
                return ['total' => 0, 'will_copy' => 0, 'will_skip' => 0, 'invalid_date' => 0];
            }
            $conditions['i_id_user IN'] = $childIds;
        }

        $rows = $this->TIndividualReservationInfo->find()
            ->select(['i_id_user', 'i_id_room', 'i_reservation_type', 'd_reservation_date'])
            ->where($conditions)
            ->enableHydration(false)
            ->toArray();

        $total = count($rows);
        $willCopy = 0;
        $willSkip = 0;
        $invalidDate = 0;

        foreach ($rows as $r) {
            $srcDate = new FrozenDate($r['d_reservation_date']);
            $day = (int)$srcDate->format('d');
            
            $dstDateStr = $dstStart->format('Y-m-') . str_pad((string)$day, 2, '0', STR_PAD_LEFT);
            try {
                $dstDate = new FrozenDate($dstDateStr);
                if ((int)$dstDate->format('m') !== (int)$dstStart->format('m')) {
                    $invalidDate++;
                    continue;
                }
            } catch (\Throwable $e) {
                $invalidDate++;
                continue;
            }

            $exists = $this->TIndividualReservationInfo->exists([
                'i_id_user'           => (int)$r['i_id_user'],
                'i_id_room'           => (int)$r['i_id_room'],
                'd_reservation_date'  => $dstDate->format('Y-m-d'),
                'i_reservation_type'  => (int)$r['i_reservation_type'],
            ]);

            if ($exists) {
                $willSkip++;
            } else {
                $willCopy++;
            }
        }

        return [
            'total' => $total,
            'will_copy' => $willCopy,
            'will_skip' => $willSkip,
            'invalid_date' => $invalidDate,
        ];
    }
}
