<?php
declare(strict_types=1);

namespace App\Application\Query\Dto;

/**
 * 変更の経緯の1件。
 */
final class HistoryLogEntry
{
    public const RESERVE = 'reserve';
    public const CANCEL = 'cancel';

    /**
     * @param string $at 操作した日時（Y-m-d H:i）
     * @param string $date 対象日（Y-m-d）
     * @param int $meal 食事
     * @param string $kind RESERVE（予約）か CANCEL（取消）
     * @param string $actor 操作者（本人・代理・管理者。名前を出すときは「区分（名前）」）
     */
    public function __construct(
        public readonly string $at,
        public readonly string $date,
        public readonly int $meal,
        public readonly string $kind,
        public readonly string $actor,
    ) {}
}
