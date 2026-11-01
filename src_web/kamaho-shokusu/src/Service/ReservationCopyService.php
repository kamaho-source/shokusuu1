<?php
declare(strict_types=1);

namespace App\Service;

use App\Domain\ValueObject\MealType;
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
    private ?ReservationDatePolicy $datePolicy = null;

    public function __construct()
    {
        $this->TIndividualReservationInfo = TableRegistry::getTableLocator()->get('TIndividualReservationInfo');
        $this->MUserInfo                  = TableRegistry::getTableLocator()->get('MUserInfo');
    }

    /**
     * コピー対象の部屋IDを、ログインユーザーの有効な所属部屋に絞って返す（管理者も同じ。#821）。
     *
     * 部屋指定なし（画面の「所属全部屋」）は所属部屋すべて。所属外の部屋の指定・所属部屋なしは null。
     *
     * @return array<int>|null
     */
    public function resolveTargetRoomIds(?int $requestedRoomId, int $loginUserId, ?RoomAccessService $roomAccessService = null): ?array
    {
        $roomAccessService ??= new RoomAccessService();
        $roomTable = TableRegistry::getTableLocator()->get('MRoomInfo');
        $roomIds = array_keys($roomAccessService->getAccessibleRooms($roomTable, $loginUserId));
        if ($requestedRoomId !== null) {
            return in_array($requestedRoomId, $roomIds, true) ? [$requestedRoomId] : null;
        }

        return $roomIds ?: null;
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
        int|array|null $roomId,
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
        int|array|null $roomId,
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
        int|array|null $roomId,
        bool $onlyChildren,
        $actor = null
    ): array {
        $childIds = $onlyChildren ? $this->getChildUserIds() : null;
        $today    = Date::today('Asia/Tokyo');

        Log::debug('[copyRangeByOffset] srcStart=' . $srcStart->format('Y-m-d') . ', srcEnd=' . $srcEnd->format('Y-m-d') . ', offsetDays=' . $offsetDays . ', roomId=' . ($roomId === null ? 'null' : implode(',', (array)$roomId)) . ', onlyChildren=' . ($onlyChildren ? 'true' : 'false'));
        
        if ($childIds !== null) {
            Log::debug('[copyRangeByOffset] childIds count: ' . count($childIds));
        }

        $conditions = [
            'd_reservation_date >=' => $srcStart->format('Y-m-d'),
            'd_reservation_date <=' => $srcEnd->format('Y-m-d'),
        ];
        if ($roomId !== null) {
            $conditions['i_id_room IN'] = (array)$roomId;
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

                // コピー元で食べない行（取消済み）は写さない。コピー先で二重予約・昼食と弁当の同時予約になる行もスキップする
                // （プレビューと同じ判定。1件の衝突でコピー全体を止めない）
                $dstStr = $dstDate->format('Y-m-d');
                if (!$this->isSourceEffective($r)
                    || $this->willSkipExisting((int)$r['i_id_user'], (int)$r['i_id_room'], $dstStr, (int)$r['i_reservation_type'])
                ) {
                    $skipped++;
                    continue;
                }
                // フラグはコピー元をそのまま写さず、コピー先の日付に合わせて「食べる」で作る
                // （直前期間は当初予定 eat_flag を 0、それ以外は eat_flag と i_change_flag をそろえる）
                $flags = ['eat_flag' => TIndividualReservationInfoTable::isLastMinute($dstStr) ? 0 : 1, 'i_change_flag' => 1];

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
                        'd_reservation_date' => $dstStr,
                        'i_reservation_type' => (int)$r['i_reservation_type'],
                    ])
                    ->first();
                if ($existing) {
                    // willSkipExisting を通っているので、無効で未承認の行。食べるにする
                    // （直前期間は当初予定 eat_flag を残し、i_change_flag だけを立てる。toggle・直前編集と同じ）
                    $ok = $this->updateReservationRowWithVersion($existing, (TIndividualReservationInfoTable::isLastMinute($dstStr) ? ['i_change_flag' => 1] : $flags) + [
                        'c_update_user' => $actorName,
                        'dt_update'     => new \Cake\I18n\DateTime(),
                    ]);
                    if (!$ok) {
                        throw new \RuntimeException('optimistic_conflict');
                    }
                    $affected++;
                    continue;
                }

                $data = [
                    'i_id_user'           => (int)$r['i_id_user'],
                    'i_id_room'           => (int)$r['i_id_room'],
                    'd_reservation_date'  => $dstStr,
                    'i_reservation_type'  => (int)$r['i_reservation_type'],
                    'i_version'           => 1,
                    'dt_create'           => new \Cake\I18n\DateTime(),
                ] + $flags;
                $data['c_create_user'] = $actorName;
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
        int|array|null $roomId,
        bool $onlyChildren,
        $actor = null
    ): array {
        $childIds = $onlyChildren ? $this->getChildUserIds() : null;
        $today    = Date::today('Asia/Tokyo');

        // 月の初日と末日を確実に設定
        $srcStart = new Date($srcMonthFirst->format('Y-m-01'));
        $srcEnd   = new Date($srcMonthFirst->format('Y-m-t'));
        $dstStart = new Date($dstMonthFirst->format('Y-m-01'));

        Log::debug('[copyMonthSameDay] srcStart=' . $srcStart->format('Y-m-d') . ', srcEnd=' . $srcEnd->format('Y-m-d') . ', dstStart=' . $dstStart->format('Y-m-d') . ', roomId=' . ($roomId === null ? 'null' : implode(',', (array)$roomId)) . ', onlyChildren=' . ($onlyChildren ? 'true' : 'false'));

        $conditions = [
            'd_reservation_date >=' => $srcStart->format('Y-m-d'),
            'd_reservation_date <=' => $srcEnd->format('Y-m-d'),
        ];
        if ($roomId !== null) {
            $conditions['i_id_room IN'] = (array)$roomId;
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

                // コピー元で食べない行（取消済み）は写さない。コピー先で二重予約・昼食と弁当の同時予約になる行もスキップする
                // （プレビューと同じ判定。1件の衝突でコピー全体を止めない）
                $dstStr = $dstDate->format('Y-m-d');
                if (!$this->isSourceEffective($r)
                    || $this->willSkipExisting((int)$r['i_id_user'], (int)$r['i_id_room'], $dstStr, (int)$r['i_reservation_type'])
                ) {
                    $skipped++;
                    continue;
                }
                // フラグはコピー元をそのまま写さず、コピー先の日付に合わせて「食べる」で作る
                // （直前期間は当初予定 eat_flag を 0、それ以外は eat_flag と i_change_flag をそろえる）
                $flags = ['eat_flag' => TIndividualReservationInfoTable::isLastMinute($dstStr) ? 0 : 1, 'i_change_flag' => 1];

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
                        'd_reservation_date' => $dstStr,
                        'i_reservation_type' => (int)$r['i_reservation_type'],
                    ])
                    ->first();
                if ($existing) {
                    // willSkipExisting を通っているので、無効で未承認の行。食べるにする
                    // （直前期間は当初予定 eat_flag を残し、i_change_flag だけを立てる。toggle・直前編集と同じ）
                    $ok = $this->updateReservationRowWithVersion($existing, (TIndividualReservationInfoTable::isLastMinute($dstStr) ? ['i_change_flag' => 1] : $flags) + [
                        'c_update_user' => $actorName,
                        'dt_update'     => new \Cake\I18n\DateTime(),
                    ]);
                    if (!$ok) {
                        throw new \RuntimeException('optimistic_conflict');
                    }
                    $affected++;
                    continue;
                }

                $data = [
                    'i_id_user'           => (int)$r['i_id_user'],
                    'i_id_room'           => (int)$r['i_id_room'],
                    'd_reservation_date'  => $dstStr,
                    'i_reservation_type'  => (int)$r['i_reservation_type'],
                    'i_version'           => 1,
                    'dt_create'           => new \Cake\I18n\DateTime(),
                ] + $flags;
                $data['c_create_user'] = $actorName;
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
     * 承認済み保護つきの共通更新（TIndividualReservationInfoTable に集約）。
     *
     * @param array{eat_flag?: int, i_change_flag?: int, i_id_room?: int, c_update_user?: string, dt_update?: \Cake\I18n\DateTime} $updateFields
     * @return bool false = 楽観的ロック競合
     * @throws \App\Exception\ApprovedReservationException 承認済み行を更新しようとした場合
     * @throws \App\Exception\DuplicateMealReservationException 更新で有効になる行が、他の部屋の有効な予約と重なる場合
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
        int|array|null $roomId,
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
        int|array|null $roomId,
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
        int|array|null $roomId,
        bool $onlyChildren
    ): array {
        $childIds = $onlyChildren ? $this->getChildUserIds() : null;
        $today    = Date::today('Asia/Tokyo');

        $conditions = [
            'd_reservation_date >=' => $srcStart->format('Y-m-d'),
            'd_reservation_date <=' => $srcEnd->format('Y-m-d'),
        ];
        if ($roomId !== null) {
            $conditions['i_id_room IN'] = (array)$roomId;
        }
        if ($childIds !== null) {
            if (empty($childIds)) {
                return ['total' => 0, 'will_copy' => 0, 'will_skip' => 0];
            }
            $conditions['i_id_user IN'] = $childIds;
        }

        $rows = $this->TIndividualReservationInfo->find()
            ->select(['i_id_user', 'i_id_room', 'i_reservation_type', 'd_reservation_date', 'eat_flag', 'i_change_flag'])
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

            if (!$this->isSourceEffective($r)
                || $this->willSkipExisting((int)$r['i_id_user'], (int)$r['i_id_room'], $dstDate->format('Y-m-d'), (int)$r['i_reservation_type'])
            ) {
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
        int|array|null $roomId,
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
            $conditions['i_id_room IN'] = (array)$roomId;
        }
        if ($childIds !== null) {
            if (empty($childIds)) {
                return ['total' => 0, 'will_copy' => 0, 'will_skip' => 0, 'invalid_date' => 0];
            }
            $conditions['i_id_user IN'] = $childIds;
        }

        $rows = $this->TIndividualReservationInfo->find()
            ->select(['i_id_user', 'i_id_room', 'i_reservation_type', 'd_reservation_date', 'eat_flag', 'i_change_flag'])
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

            if (!$this->isSourceEffective($r)
                || $this->willSkipExisting((int)$r['i_id_user'], (int)$r['i_id_room'], $dstDate->format('Y-m-d'), (int)$r['i_reservation_type'])
            ) {
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
     * コピー元の行が食数として数えられる（食べる）か。取消済みの行は写さない。
     *
     * @param array{d_reservation_date: \Cake\I18n\Date|string, eat_flag?: int|string|null, i_change_flag?: int|string|null} $r
     */
    private function isSourceEffective(array $r): bool
    {
        $this->datePolicy ??= new ReservationDatePolicy();

        return $this->datePolicy->countedValue(new Date($r['d_reservation_date']), $r['eat_flag'] ?? null, $r['i_change_flag'] ?? null) === 1;
    }

    /**
     * コピー先の既存レコードがスキップ対象かどうかを返す（プレビューと実行で共通の判定）。
     *
     * スキップするのは以下のいずれか:
     *   - 別の部屋で同じ食事が有効（二重予約になる）
     *   - 昼食と弁当の相手がコピー先で有効（同時に予約できない）
     *   - 既に有効な予約が存在する
     *   - 承認済み（i_approval_status が 1/2）で変更できない
     * 上記以外（eat_flag = 0 の無効行）は上書きコピーの対象となる。
     */
    private function willSkipExisting(int $userId, int $roomId, string $date, int $mealType): bool
    {
        // 本処理と同じく、別の部屋で同じ食事を予約済みならスキップに数える
        if (array_diff($this->TIndividualReservationInfo->findEffectiveRoomIds($userId, $date, $mealType), [$roomId]) !== []) {
            return true;
        }
        // 昼食と弁当は同時に予約できない。コピー先で相手が（どの部屋でも）有効ならスキップに数える
        if (MealType::isExclusiveValue($mealType)
            && $this->TIndividualReservationInfo->findEffectiveRoomIds($userId, $date, MealType::counterpartValue($mealType)) !== []
        ) {
            return true;
        }

        $existing = $this->TIndividualReservationInfo->find()
            ->enableAutoFields(false)
            ->select(['d_reservation_date', 'eat_flag', 'i_change_flag', 'i_approval_status'])
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

        return TIndividualReservationInfoTable::isEffectiveRow($existing)
            || in_array((int)($existing->i_approval_status ?? 0), TIndividualReservationInfoTable::APPROVED_STATUSES, true);
    }
}
