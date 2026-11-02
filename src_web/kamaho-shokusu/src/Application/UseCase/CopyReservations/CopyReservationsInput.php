<?php
declare(strict_types=1);

namespace App\Application\UseCase\CopyReservations;

use App\Domain\Exception\InvalidInputException;
use Cake\Chronos\ChronosDate;

/**
 * 予約コピー（とそのプレビュー）の入力。週は月曜日、月は1日にそろえてある。
 */
final class CopyReservationsInput
{
    public const WEEK = 'week';
    public const MONTH = 'month';

    /**
     * @param string $mode self::WEEK または self::MONTH
     * @param int|null $roomId 部屋の指定（null は所属部屋すべて）
     * @param string|null $actorName 操作者（空なら 'system'）
     */
    public function __construct(
        public readonly string $mode,
        public readonly ChronosDate $src,
        public readonly ChronosDate $dst,
        public readonly ?int $roomId,
        public readonly bool $onlyChildren,
        public readonly int $loginUserId,
        public readonly ?string $actorName,
    ) {}

    /**
     * 送られた値を確かめて、週は月曜日、月は1日にそろえる。
     *
     * @param array<array-key, mixed> $data mode・source（source_start）・target（target_start）・room_id・only_children。
     *   リクエストの生データのため型を決められない（ここで確かめて型をそろえる）
     * @throws InvalidInputException mode・日付の誤り（422）
     */
    public static function fromRequest(array $data, int $loginUserId, ?string $actorName): self
    {
        $mode = strtolower((string)($data['mode'] ?? ''));
        $sourceStr = (string)($data['source'] ?? $data['source_start'] ?? '');
        $targetStr = (string)($data['target'] ?? $data['target_start'] ?? '');
        $roomIdRaw = $data['room_id'] ?? null;
        $roomId = $roomIdRaw !== null && $roomIdRaw !== '' && $roomIdRaw !== '0' ? (int)$roomIdRaw : null;

        if (!in_array($mode, [self::WEEK, self::MONTH], true)) {
            throw new InvalidInputException('mode は "week" か "month" を指定してください。');
        }
        if ($sourceStr === '' || $targetStr === '') {
            throw new InvalidInputException('source / target（または source_start / target_start）を YYYY-MM-DD 形式で指定してください。');
        }
        try {
            $src = new ChronosDate($sourceStr);
            $dst = new ChronosDate($targetStr);
        } catch (\Throwable $e) {
            throw new InvalidInputException('source / target は YYYY-MM-DD 形式で指定してください。');
        }
        $onlyChildren = filter_var($data['only_children'] ?? false, FILTER_VALIDATE_BOOLEAN);
        if ($mode === self::WEEK) {
            return new self($mode, self::monday($src), self::monday($dst), $roomId, $onlyChildren, $loginUserId, $actorName);
        }

        return new self($mode, $src->startOfMonth(), $dst->startOfMonth(), $roomId, $onlyChildren, $loginUserId, $actorName);
    }

    public function isWeek(): bool
    {
        return $this->mode === self::WEEK;
    }

    private static function monday(ChronosDate $d): ChronosDate
    {
        $n = (int)$d->format('N');

        return $n > 1 ? $d->subDays($n - 1) : $d;
    }
}
