<?php
declare(strict_types=1);

namespace App\Application\UseCase\CopyReservations;

use App\Application\Port\TransactionManagerInterface;
use App\Domain\Entity\MealReservation;
use App\Domain\Repository\ReservationRepositoryInterface;
use App\Domain\Repository\RoomMembershipRepositoryInterface;
use App\Domain\Repository\UserAccountRepositoryInterface;
use App\Domain\Service\ReservationDateRule;
use App\Domain\ValueObject\MealType;
use Cake\Chronos\ChronosDate;
use Psr\Clock\ClockInterface;

/**
 * 予約の週コピー・月コピーと、その件数のプレビュー。誰がコピーしてよいか（認可）は呼び出し側で確かめる。
 *
 * - 週コピーは同じ曜日へ、月コピーは同じ「日」へ写す（コピー先の月に無い日は無効な日付として数える）。
 * - 今日より前の日へは写さない。コピー元で食べない行（取消済み）は写さない。
 * - コピー先で二重予約・昼食と弁当の同時予約になる行、すでに食べる行・承認済みの行はスキップする（プレビューと同じ判定）。
 * - コピー先に食べない（未承認の）行があれば食べるにし、無ければ作る。直前期間は当初予定（eat_flag）を 0 にする。
 * - 全体を1つのトランザクションで行う。保存できない行があれば全部取り消して投げ直す。
 */
final class CopyReservationsUseCase
{
    public function __construct(
        private readonly ReservationRepositoryInterface $reservations,
        private readonly RoomMembershipRepositoryInterface $rooms,
        private readonly UserAccountRepositoryInterface $users,
        private readonly TransactionManagerInterface $transaction,
        private readonly ClockInterface $clock,
    ) {}

    /**
     * 送られた値を確かめて、週は月曜日、月は1日にそろえる。
     *
     * @param array<array-key, mixed> $data mode・source（source_start）・target（target_start）・room_id・only_children
     * @return array{ok: false, status: int, message: string}|array{ok: true, mode: string, src: ChronosDate, dst: ChronosDate, roomId: int|null, onlyChildren: bool}
     */
    public function normalizeCopyParams(array $data): array
    {
        $mode = strtolower((string)($data['mode'] ?? ''));
        $sourceStr = (string)($data['source'] ?? $data['source_start'] ?? '');
        $targetStr = (string)($data['target'] ?? $data['target_start'] ?? '');
        $roomIdRaw = $data['room_id'] ?? null;
        $roomId = $roomIdRaw !== null && $roomIdRaw !== '' && $roomIdRaw !== '0' ? (int)$roomIdRaw : null;

        if (!in_array($mode, ['week', 'month'], true)) {
            return ['ok' => false, 'status' => 422, 'message' => 'mode は "week" か "month" を指定してください。'];
        }
        if ($sourceStr === '' || $targetStr === '') {
            return ['ok' => false, 'status' => 422, 'message' => 'source / target（または source_start / target_start）を YYYY-MM-DD 形式で指定してください。'];
        }
        try {
            $src = new ChronosDate($sourceStr);
            $dst = new ChronosDate($targetStr);
        } catch (\Throwable $e) {
            return ['ok' => false, 'status' => 422, 'message' => 'source / target は YYYY-MM-DD 形式で指定してください。'];
        }
        if ($mode === 'week') {
            $src = self::monday($src);
            $dst = self::monday($dst);
        } else {
            $src = $src->startOfMonth();
            $dst = $dst->startOfMonth();
        }

        return [
            'ok'           => true,
            'mode'         => $mode,
            'src'          => $src,
            'dst'          => $dst,
            'roomId'       => $roomId,
            'onlyChildren' => filter_var($data['only_children'] ?? false, FILTER_VALIDATE_BOOLEAN),
        ];
    }

    /**
     * コピーできる部屋（ログインしている人が所属している、削除されていない部屋。管理者も同じ。#821）。
     * 部屋の指定なしは所属部屋すべて。所属していない部屋の指定・所属部屋なしは null。
     *
     * @return list<int>|null
     */
    public function resolveTargetRoomIds(?int $requestedRoomId, int $loginUserId): ?array
    {
        $activeRoomIds = $loginUserId > 0 ? $this->rooms->activeRoomIds($loginUserId) : [];
        $roomIds = $activeRoomIds === [] ? [] : array_map('intval', array_keys($this->rooms->selectableRooms($activeRoomIds)));
        if ($requestedRoomId !== null) {
            return in_array($requestedRoomId, $roomIds, true) ? [$requestedRoomId] : null;
        }

        return $roomIds ?: null;
    }

    /**
     * 週コピー（同じ曜日へ）。
     *
     * @param list<int>|int|null $roomId
     * @param string|null $actorName 操作者（空なら 'system'）
     * @return array{total: int, copied: int, skipped: int, invalid_date?: int}
     * @throws \Throwable 保存の失敗（取り消してから投げ直す）
     */
    public function copyWeek(ChronosDate $srcMonday, ChronosDate $dstMonday, int|array|null $roomId, ?string $actorName, bool $onlyChildren): array
    {
        $offset = self::dayOffset($srcMonday, $dstMonday);

        return $this->copy($srcMonday, $srcMonday->addDays(6), fn(ChronosDate $d) => $d->addDays($offset), $roomId, $actorName, $onlyChildren, false);
    }

    /**
     * 月コピー（同じ「日」へ。コピー先の月に無い日は無効な日付）。
     *
     * @param list<int>|int|null $roomId
     * @param string|null $actorName 操作者（空なら 'system'）
     * @return array{total: int, copied: int, skipped: int, invalid_date: int}
     * @throws \Throwable 保存の失敗（取り消してから投げ直す）
     */
    public function copyMonth(ChronosDate $srcMonthFirst, ChronosDate $dstMonthFirst, int|array|null $roomId, ?string $actorName, bool $onlyChildren): array
    {
        return $this->copy($srcMonthFirst->startOfMonth(), $srcMonthFirst->endOfMonth(), fn(ChronosDate $d) => self::sameDay($d, $dstMonthFirst), $roomId, $actorName, $onlyChildren, true);
    }

    /**
     * 週コピーの件数（今日より前の日は will_skip に数える）。
     *
     * @param list<int>|int|null $roomId
     * @return array{total: int, will_copy: int, will_skip: int}
     */
    public function previewWeek(ChronosDate $srcMonday, ChronosDate $dstMonday, int|array|null $roomId, bool $onlyChildren): array
    {
        $offset = self::dayOffset($srcMonday, $dstMonday);
        $rows = $this->sourceRows($srcMonday, $srcMonday->addDays(6), $roomId, $onlyChildren);
        if ($rows === null) {
            return ['total' => 0, 'will_copy' => 0, 'will_skip' => 0];
        }
        $today = $this->today();
        $willCopy = $willSkip = 0;
        foreach ($rows as $r) {
            $dst = (new ChronosDate($r->date))->addDays($offset);
            if ($dst->lessThan($today) || $this->shouldSkip($r, $dst->format('Y-m-d'), $today)) {
                $willSkip++;
                continue;
            }
            $willCopy++;
        }

        return ['total' => count($rows), 'will_copy' => $willCopy, 'will_skip' => $willSkip];
    }

    /**
     * 月コピーの件数（コピー先の月に無い日・今日より前の日は invalid_date に数える）。
     *
     * @param list<int>|int|null $roomId
     * @return array{total: int, will_copy: int, will_skip: int, invalid_date: int}
     */
    public function previewMonth(ChronosDate $srcMonthFirst, ChronosDate $dstMonthFirst, int|array|null $roomId, bool $onlyChildren): array
    {
        $rows = $this->sourceRows($srcMonthFirst->startOfMonth(), $srcMonthFirst->endOfMonth(), $roomId, $onlyChildren);
        if ($rows === null) {
            return ['total' => 0, 'will_copy' => 0, 'will_skip' => 0, 'invalid_date' => 0];
        }
        $today = $this->today();
        $willCopy = $willSkip = $invalid = 0;
        foreach ($rows as $r) {
            $dst = self::sameDay(new ChronosDate($r->date), $dstMonthFirst);
            if ($dst === null || $dst->lessThan($today)) {
                $invalid++;
                continue;
            }
            if ($this->shouldSkip($r, $dst->format('Y-m-d'), $today)) {
                $willSkip++;
                continue;
            }
            $willCopy++;
        }

        return ['total' => count($rows), 'will_copy' => $willCopy, 'will_skip' => $willSkip, 'invalid_date' => $invalid];
    }

    /**
     * @param callable(ChronosDate): (ChronosDate|null) $targetDate コピー先の日（無効な日は null）
     * @param list<int>|int|null $roomId
     * @return array{total: int, copied: int, skipped: int, invalid_date?: int}
     */
    private function copy(ChronosDate $from, ChronosDate $to, callable $targetDate, int|array|null $roomId, ?string $actorName, bool $onlyChildren, bool $isMonth): array
    {
        $rows = $this->sourceRows($from, $to, $roomId, $onlyChildren);
        if ($rows === null) {
            // 従来どおり、週コピーで対象が無いときは invalid_date を返さない
            return ['total' => 0, 'copied' => 0, 'skipped' => 0] + ($isMonth ? ['invalid_date' => 0] : []);
        }
        $actor = $actorName !== null && $actorName !== '' ? $actorName : 'system';
        $today = $this->today();
        $copied = $skipped = $invalid = 0;

        $this->transaction->begin();
        try {
            foreach ($rows as $r) {
                $dst = $targetDate(new ChronosDate($r->date));
                if ($dst === null || $dst->lessThan($today)) {
                    $invalid++;
                    continue;
                }
                $dstStr = $dst->format('Y-m-d');
                if ($this->shouldSkip($r, $dstStr, $today)) {
                    $skipped++;
                    continue;
                }
                // フラグはコピー元をそのまま写さず、コピー先の日付に合わせて「食べる」で作る
                $lastMinute = ReservationDateRule::isLastMinuteWindow($dst, $today);
                $existing = $this->reservations->findOne($r->userId, $r->roomId, $dstStr, $r->mealType);
                if ($existing !== null) {
                    // shouldSkip を通っているので、食べない未承認の行。直前期間は当初予定を残し、i_change_flag だけを立てる
                    if (!$this->reservations->updateWithVersion($existing, 1, $lastMinute ? null : 1, $actor)) {
                        throw new \RuntimeException('optimistic_conflict');
                    }
                    $copied++;
                    continue;
                }
                $this->reservations->addMany([new MealReservation($r->userId, $dstStr, $r->roomId, $r->mealType, $lastMinute ? 0 : 1, 1)], $actor);
                $copied++;
            }
            $this->transaction->commit();
        } catch (\Throwable $e) {
            if ($this->transaction->inTransaction()) {
                $this->transaction->rollback();
            }
            throw $e;
        }

        return ['total' => count($rows), 'copied' => $copied, 'skipped' => $skipped, 'invalid_date' => $invalid];
    }

    /**
     * コピー元の行。児童だけに絞ったのに児童が居なければ null、行が無ければ null。
     *
     * @param list<int>|int|null $roomId
     * @return list<MealReservation>|null
     */
    private function sourceRows(ChronosDate $from, ChronosDate $to, int|array|null $roomId, bool $onlyChildren): ?array
    {
        $childIds = $onlyChildren ? $this->users->childUserIds() : null;
        if ($childIds === []) {
            return null;
        }
        $rows = $this->reservations->findInDateRange($from->format('Y-m-d'), $to->format('Y-m-d'), $roomId === null ? null : (array)$roomId, $childIds);

        return $rows === [] ? null : $rows;
    }

    /**
     * コピーしない行か（コピー元で食べない、コピー先で二重予約・昼食と弁当の同時予約になる、すでに食べる行・承認済みの行がある）。
     */
    private function shouldSkip(MealReservation $source, string $dstDate, ChronosDate $today): bool
    {
        if (!$source->isEffective($today)) {
            return true;
        }
        if (array_diff($this->reservations->effectiveRoomIds($source->userId, $dstDate, $source->mealType), [$source->roomId]) !== []) {
            return true;
        }
        if (MealType::isExclusiveValue($source->mealType)
            && $this->reservations->effectiveRoomIds($source->userId, $dstDate, (int)MealType::counterpartValue($source->mealType)) !== []
        ) {
            return true;
        }
        $existing = $this->reservations->findOne($source->userId, $source->roomId, $dstDate, $source->mealType);

        return $existing !== null && ($existing->isEffective($today) || $existing->isApproved());
    }

    private function today(): ChronosDate
    {
        return new ChronosDate($this->clock->now());
    }

    private static function monday(ChronosDate $d): ChronosDate
    {
        $n = (int)$d->format('N');

        return $n > 1 ? $d->subDays($n - 1) : $d;
    }

    private static function dayOffset(ChronosDate $from, ChronosDate $to): int
    {
        return (int)$from->diff($to)->days * ($from->lessThanOrEquals($to) ? 1 : -1);
    }

    /** 月の同じ「日」。その月に無い日（例：2月30日）は null。 */
    private static function sameDay(ChronosDate $source, ChronosDate $monthFirst): ?ChronosDate
    {
        $day = (int)$source->format('d');

        return $day > (int)$monthFirst->endOfMonth()->format('d') ? null : $monthFirst->startOfMonth()->addDays($day - 1);
    }
}
