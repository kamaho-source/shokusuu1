<?php
declare(strict_types=1);

namespace App\Service;

use App\Model\Table\TIndividualReservationInfoTable;
use Cake\I18n\Date;
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

    public function normalizeCopyParams(array $data): array
    {
        $mode = strtolower((string)($data['mode'] ?? ''));
        $sourceStr = (string)($data['source'] ?? $data['source_start'] ?? '');
        $targetStr = (string)($data['target'] ?? $data['target_start'] ?? '');
        $roomIdRaw = $data['room_id'] ?? null;

        $roomId = null;
        if ($roomIdRaw !== null && $roomIdRaw !== '' && $roomIdRaw !== '0') {
            $roomId = (int)$roomIdRaw;
        }

        $onlyChildren = filter_var($data['only_children'] ?? false, FILTER_VALIDATE_BOOLEAN);

        if (!in_array($mode, ['week', 'month'], true)) {
            return [
                'ok' => false,
                'status' => 422,
                'message' => 'mode は "week" か "month" を指定してください。',
            ];
        }
        if ($sourceStr === '' || $targetStr === '') {
            return [
                'ok' => false,
                'status' => 422,
                'message' => 'source / target（または source_start / target_start）を YYYY-MM-DD 形式で指定してください。',
            ];
        }

        try {
            $src = new Date($sourceStr);
            $dst = new Date($targetStr);
        } catch (\Throwable $e) {
            return [
                'ok' => false,
                'status' => 422,
                'message' => 'source / target は YYYY-MM-DD 形式で指定してください。',
            ];
        }

        $toMonday = function (Date $d): Date {
            $n = (int)$d->format('N');
            return $n > 1 ? $d->subDays($n - 1) : $d;
        };
        if ($mode === 'week') {
            $src = $toMonday($src);
            $dst = $toMonday($dst);
        } else {
            $src = new Date($src->format('Y-m-01'));
            $dst = new Date($dst->format('Y-m-01'));
        }

        return [
            'ok' => true,
            'mode' => $mode,
            'src' => $src,
            'dst' => $dst,
            'roomId' => $roomId,
            'onlyChildren' => (bool)$onlyChildren,
        ];
    }

    public function copyWeek(
        Date $srcMonday,
        Date $dstMonday,
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
        Date $srcMonthFirst,
        Date $dstMonthFirst,
        ?int $roomId,
        bool $overwrite,
                   $actor,
        
        bool $onlyChildren
    ): array {
        // 月内の日付を同じ「日」にマップ（存在しない日はスキップ）
        $srcStart = new Date($srcMonthFirst->format('Y-m-01'));
        $srcEnd   = new Date($srcStart->format('Y-m-t')); // 月末
        return $this->copyMonthSameDay($srcStart, $dstMonthFirst, $roomId, $onlyChildren, $actor);
    }

    private function copyRangeByOffset(
        Date $srcStart,
        Date $srcEnd,
        int $offsetDays,
        ?int $roomId,
        bool $onlyChildren,
        $actor = null
    ): array {
        $childIds = $onlyChildren ? $this->getChildUserIds() : null;
        $today    = Date::today('Asia/Tokyo');

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
        $invalidDate = 0;
        $actorName = ($actor !== null && method_exists($actor, 'get') && $actor->get('c_user_name'))
            ? (string)$actor->get('c_user_name')
            : 'system';
        $conn = $this->TIndividualReservationInfo->getConnection();
        $conn->begin();
        try {
            foreach ($rows as $r) {
                $srcDate = new Date($r['d_reservation_date']);
                $dstDate = $srcDate->addDays($offsetDays);

                if ($dstDate < $today) {
                    $invalidDate++;
                    continue;
                }

                $existing = $this->TIndividualReservationInfo->find()
                    ->enableAutoFields(false)
                    ->select([
                        'i_id_user',
                        'd_reservation_date',
                        'i_reservation_type',
                        'i_id_room',
                        'eat_flag',
                        'i_change_flag',
                        'i_approval_status',
                        'i_version',
                    ])
                    ->where([
                        'i_id_user'          => (int)$r['i_id_user'],
                        'i_id_room'          => (int)$r['i_id_room'],
                        'd_reservation_date' => $dstDate->format('Y-m-d'),
                        'i_reservation_type' => (int)$r['i_reservation_type'],
                    ])
                    ->first();
                if ($existing) {
                    // プレビュー（willSkipExisting）と同一のスキップ判定にそろえる
                    if ($this->isActiveRow($existing, $dstDate)
                        || in_array((int)($existing->i_approval_status ?? 0), TIndividualReservationInfoTable::APPROVED_STATUSES, true)) {
                        $skipped++;
                        continue;
                    }
                    $ok = $this->updateReservationRowWithVersion($existing, [
                        'c_update_user' => $actorName,
                        'dt_update'     => new \Cake\I18n\DateTime(),
                    ] + $this->copiedFlagsForExistingRow($r, $srcDate, $dstDate));
                    if (!$ok) {
                        throw new \RuntimeException('optimistic_conflict');
                    }
                    $affected++;
                    continue;
                }

                $data = [
                    'i_id_user'           => (int)$r['i_id_user'],
                    'i_id_room'           => (int)$r['i_id_room'],
                    'd_reservation_date'  => $dstDate->format('Y-m-d'),
                    'i_reservation_type'  => (int)$r['i_reservation_type'],
                    // フラグは元データを踏襲（将来要件に応じて調整可）
                    'i_version'           => 1,
                    'dt_create'           => new \Cake\I18n\DateTime(),
                ];
                $data['c_create_user'] = $actorName;
                $data += $this->copiedFlagsForNewRow($r, $srcDate, $dstDate);
                $entity = $this->TIndividualReservationInfo->newEntity($data);
                $this->TIndividualReservationInfo->saveOrFail($entity);
                $affected++;
            }
            $conn->commit();
            Log::debug('[copyRangeByOffset] 完了: total=' . $total . ', copied=' . $affected . ', skipped=' . $skipped . ', invalidDate=' . $invalidDate);
        } catch (\Throwable $e) {
            $conn->rollback();
            Log::error('ReservationCopyService(copyRangeByOffset) failed: ' . $e->getMessage());
            throw $e;
        }

        return ['total' => $total, 'copied' => $affected, 'skipped' => $skipped, 'invalid_date' => $invalidDate];
    }

    private function copyMonthSameDay(
        Date $srcMonthFirst,
        Date $dstMonthFirst,
        ?int $roomId,
        bool $onlyChildren,
        $actor = null
    ): array {
        $childIds = $onlyChildren ? $this->getChildUserIds() : null;
        $today    = Date::today('Asia/Tokyo');

        // 月の初日と末日を確実に設定
        $srcStart = new Date($srcMonthFirst->format('Y-m-01'));
        $srcEnd   = new Date($srcMonthFirst->format('Y-m-t'));
        $dstStart = new Date($dstMonthFirst->format('Y-m-01'));

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
        $actorName = ($actor !== null && method_exists($actor, 'get') && $actor->get('c_user_name'))
            ? (string)$actor->get('c_user_name')
            : 'system';
        $conn = $this->TIndividualReservationInfo->getConnection();
        $conn->begin();
        try {
            foreach ($rows as $r) {
                $srcDate = new Date($r['d_reservation_date']);
                $day     = (int)$srcDate->format('d');

                // 変換先に同一日付が存在するか確認（例: 31日が無い月はスキップ）
                $dstStr = sprintf('%s-%02d', $dstMonthFirst->format('Y-m'), $day);
                try {
                    $dstDate = new Date($dstStr);
                    if ($dstDate->format('Y-m') !== $dstMonthFirst->format('Y-m')) {
                        $invalidDate++;
                        continue; // 月がずれた場合は無効
                    }
                } catch (\Throwable $e) {
                    $invalidDate++;
                    continue; // 不正日付はスキップ
                }

                if ($dstDate < $today) {
                    $invalidDate++;
                    continue;
                }

                $existing = $this->TIndividualReservationInfo->find()
                    ->enableAutoFields(false)
                    ->select([
                        'i_id_user',
                        'd_reservation_date',
                        'i_reservation_type',
                        'i_id_room',
                        'eat_flag',
                        'i_change_flag',
                        'i_approval_status',
                        'i_version',
                    ])
                    ->where([
                        'i_id_user'          => (int)$r['i_id_user'],
                        'i_id_room'          => (int)$r['i_id_room'],
                        'd_reservation_date' => $dstDate->format('Y-m-d'),
                        'i_reservation_type' => (int)$r['i_reservation_type'],
                    ])
                    ->first();
                if ($existing) {
                    // プレビュー（willSkipExisting）と同一のスキップ判定にそろえる
                    if ($this->isActiveRow($existing, $dstDate)
                        || in_array((int)($existing->i_approval_status ?? 0), TIndividualReservationInfoTable::APPROVED_STATUSES, true)) {
                        $skipped++;
                        continue;
                    }
                    $ok = $this->updateReservationRowWithVersion($existing, [
                        'c_update_user' => $actorName,
                        'dt_update'     => new \Cake\I18n\DateTime(),
                    ] + $this->copiedFlagsForExistingRow($r, $srcDate, $dstDate));
                    if (!$ok) {
                        throw new \RuntimeException('optimistic_conflict');
                    }
                    $affected++;
                    continue;
                }

                $data = [
                    'i_id_user'           => (int)$r['i_id_user'],
                    'i_id_room'           => (int)$r['i_id_room'],
                    'd_reservation_date'  => $dstDate->format('Y-m-d'),
                    'i_reservation_type'  => (int)$r['i_reservation_type'],
                    'i_version'           => 1,
                    'dt_create'           => new \Cake\I18n\DateTime(),
                ];
                $data['c_create_user'] = $actorName;
                $data += $this->copiedFlagsForNewRow($r, $srcDate, $dstDate);
                $entity = $this->TIndividualReservationInfo->newEntity($data);
                $this->TIndividualReservationInfo->saveOrFail($entity);
                $affected++;
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
     * 予約1行が指定日付の食数として有効かを ReservationDatePolicy の基準で判定する。
     *
     * @param object|array $row eat_flag / i_change_flag を持つ行
     */
    private function isActiveRow(object|array $row, Date $date): bool
    {
        $eat    = is_array($row) ? ($row['eat_flag'] ?? null)      : ($row->eat_flag ?? null);
        $change = is_array($row) ? ($row['i_change_flag'] ?? null) : ($row->i_change_flag ?? null);

        return (new ReservationDatePolicy())->isActiveReservation(
            $eat === null ? null : (int)$eat,
            $change === null ? null : (int)$change,
            $date
        );
    }

    /**
     * コピー元行の「有効／無効」だけを引き継ぎ、コピー先日付の期間に合ったフラグを返す（新規行用）。
     *
     * 元データのフラグをそのまま複製すると、直前編集で作られた
     * 「eat_flag と i_change_flag が食い違う行」が通常予約期間へ運ばれ、
     * 食数予定表の数と発注用集計の数がずれる。
     *
     * @param array $srcRow コピー元行
     * @return array{eat_flag: int, i_change_flag: int}
     */
    private function copiedFlagsForNewRow(array $srcRow, Date $srcDate, Date $dstDate): array
    {
        $policy = new ReservationDatePolicy();
        $active = $this->isActiveRow($srcRow, $srcDate);

        // 直前期間の新規行は発注が無いため eat_flag=0 固定（toggleMeal() と同じ規則）
        return $policy->shouldUseChangeFlag($dstDate)
            ? ['eat_flag' => 0, 'i_change_flag' => $active ? 1 : 0]
            : ['eat_flag' => $active ? 1 : 0, 'i_change_flag' => $active ? 1 : 0];
    }

    /**
     * 既存行を上書きする際のフラグ。直前期間では発注済みの eat_flag を保持する。
     *
     * @param array $srcRow コピー元行
     * @return array<string, int>
     */
    private function copiedFlagsForExistingRow(array $srcRow, Date $srcDate, Date $dstDate): array
    {
        $policy = new ReservationDatePolicy();

        return $policy->flagUpdates(
            $this->isActiveRow($srcRow, $srcDate),
            $policy->shouldUseChangeFlag($dstDate)
        );
    }

    /**
     * 承認済み保護つきの共通更新（TIndividualReservationInfoTable に集約）。
     *
     * @param array{eat_flag?: int, i_change_flag?: int, i_id_room?: int, c_update_user?: string, dt_update?: \Cake\I18n\DateTime} $updateFields
     * @return bool false = 楽観的ロック競合
     * @throws \App\Exception\ApprovedReservationException 承認済み行を更新しようとした場合
     */
    private function updateReservationRowWithVersion(object $row, array $updateFields): bool
    {
        return $this->TIndividualReservationInfo->updateRowWithVersion($row, $updateFields);
    }

    /**
     * 週コピーの件数をプレビュー（実際にコピーせず件数だけカウント）
     */
    public function previewWeek(
        Date $srcMonday,
        Date $dstMonday,
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
        Date $srcMonthFirst,
        Date $dstMonthFirst,
        ?int $roomId,
        bool $onlyChildren
    ): array {
        $srcStart = new Date($srcMonthFirst->format('Y-m-01'));
        $srcEnd   = new Date($srcStart->format('Y-m-t'));
        return $this->previewMonthSameDay($srcStart, $dstMonthFirst, $roomId, $onlyChildren);
    }

    /**
     * 週コピーのプレビュー内部処理（オフセットベース）
     */
    private function previewRangeByOffset(
        Date $srcStart,
        Date $srcEnd,
        int $offsetDays,
        ?int $roomId,
        bool $onlyChildren
    ): array {
        $childIds = $onlyChildren ? $this->getChildUserIds() : null;
        $today    = Date::today('Asia/Tokyo');

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
            $srcDate = new Date($r['d_reservation_date']);
            $dstDate = $srcDate->addDays($offsetDays);

            if ($dstDate < $today) {
                $willSkip++;
                continue;
            }

            if ($this->willSkipExisting((int)$r['i_id_user'], (int)$r['i_id_room'], $dstDate->format('Y-m-d'), (int)$r['i_reservation_type'])) {
                $willSkip++;
                continue;
            }
            $willCopy++;
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
        Date $srcMonthFirst,
        Date $dstMonthFirst,
        ?int $roomId,
        bool $onlyChildren
    ): array {
        $childIds = $onlyChildren ? $this->getChildUserIds() : null;
        $today    = Date::today('Asia/Tokyo');

        $srcStart = new Date($srcMonthFirst->format('Y-m-01'));
        $srcEnd   = new Date($srcMonthFirst->format('Y-m-t'));
        $dstStart = new Date($dstMonthFirst->format('Y-m-01'));

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
            $srcDate = new Date($r['d_reservation_date']);
            $day = (int)$srcDate->format('d');

            $dstDateStr = $dstStart->format('Y-m-') . str_pad((string)$day, 2, '0', STR_PAD_LEFT);
            try {
                $dstDate = new Date($dstDateStr);
                if ((int)$dstDate->format('m') !== (int)$dstStart->format('m')) {
                    $invalidDate++;
                    continue;
                }
            } catch (\Throwable $e) {
                $invalidDate++;
                continue;
            }

            if ($dstDate < $today) {
                $invalidDate++;
                continue;
            }

            if ($this->willSkipExisting((int)$r['i_id_user'], (int)$r['i_id_room'], $dstDate->format('Y-m-d'), (int)$r['i_reservation_type'])) {
                $willSkip++;
                continue;
            }
            $willCopy++;
        }

        return [
            'total' => $total,
            'will_copy' => $willCopy,
            'will_skip' => $willSkip,
            'invalid_date' => $invalidDate,
        ];
    }

    /**
     * コピー先の既存レコードがスキップ対象かどうかを返す（プレビューと実行で共通の判定）。
     *
     * スキップするのは以下のいずれか:
     *   - 既に有効な予約（eat_flag = 1）が存在する
     *   - 承認済み（i_approval_status が 1/2）で変更できない
     * 上記以外（eat_flag = 0 の無効行）は上書きコピーの対象となる。
     */
    private function willSkipExisting(int $userId, int $roomId, string $date, int $mealType): bool
    {
        $existing = $this->TIndividualReservationInfo->find()
            ->enableAutoFields(false)
            ->select(['eat_flag', 'i_change_flag', 'i_approval_status'])
            ->where([
                'i_id_user'          => $userId,
                'i_id_room'          => $roomId,
                'd_reservation_date' => $date,
                'i_reservation_type' => $mealType,
            ])
            ->first();

        if ($existing === null) {
            return false;
        }

        return $this->isActiveRow($existing, new Date($date))
            || in_array((int)($existing->i_approval_status ?? 0), TIndividualReservationInfoTable::APPROVED_STATUSES, true);
    }
}
