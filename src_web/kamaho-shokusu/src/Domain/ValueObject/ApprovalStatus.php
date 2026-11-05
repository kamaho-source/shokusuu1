<?php
declare(strict_types=1);

namespace App\Domain\ValueObject;

/**
 * 予約の承認状態（t_individual_reservation_info.i_approval_status。t_approval_log は 1〜3 だけ）。
 *
 * 状態の数字・承認済みの判定・どの状態から変えられるか・表示名はここだけで決める。
 */
final class ApprovalStatus
{
    /** 未承認 */
    public const PENDING = 0;
    /** ブロック長承認済 */
    public const BLOCK_LEADER = 1;
    /** 管理者承認済（最終） */
    public const ADMIN = 2;
    /** 差し戻し */
    public const REJECTED = 3;

    /** 承認済み（ブロック長・管理者）。この状態の予約は内容を変えない。 */
    public const APPROVED = [self::BLOCK_LEADER, self::ADMIN];

    /** 状態の表示名 */
    public const LABELS = [
        self::PENDING      => '未承認',
        self::BLOCK_LEADER => 'ブロック長承認済',
        self::ADMIN        => '管理者承認済',
        self::REJECTED     => '差し戻し',
    ];

    /** 変える先の状態 => 変えてよい元の状態 */
    private const ALLOWED_FROM = [
        self::BLOCK_LEADER => [self::PENDING],
        // 管理者は緊急時にブロック長の承認を経ずに最終承認できる
        self::ADMIN        => [self::PENDING, self::BLOCK_LEADER],
        // 最終承認済みは差し戻せない
        self::REJECTED     => [self::PENDING, self::BLOCK_LEADER],
    ];

    /** 承認済みか（NULL は未承認）。 */
    public static function isApproved(?int $status): bool
    {
        return in_array($status, self::APPROVED, true);
    }

    /**
     * $to へ変えてよい元の状態。承認の操作で変える先でなければ空。
     *
     * @return list<int>
     */
    public static function allowedFrom(int $to): array
    {
        return self::ALLOWED_FROM[$to] ?? [];
    }

    /** $from から $to へ変えてよいか。 */
    public static function canTransition(int $from, int $to): bool
    {
        return in_array($from, self::allowedFrom($to), true);
    }

    /** 表示名。知らない値は null。 */
    public static function label(int $status): ?string
    {
        return self::LABELS[$status] ?? null;
    }

    private function __construct() {}
}
