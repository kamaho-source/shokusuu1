<?php
declare(strict_types=1);

namespace App\Domain\ValueObject;

/**
 * サブスクリプションプランコード。
 *
 * プランごとの利用可能機能・利用者数上限を一元管理する。
 * plan_code は tenants.plan_code に格納される文字列値と対応する。
 *
 * 機能マトリクス:
 *              | starter | light | standard | premium
 * -------------|---------|-------|----------|---------
 * 入居者上限    |   30    |  80   |   200    | 無制限
 * 週間一括予約  |    ✗    |   ✓   |    ✓     |   ✓
 * 月間一括予約  |    ✗    |   ✗   |    ✓     |   ✓
 * Excelエクスポート|  ✗    |   ✓   |    ✓     |   ✓
 * AIアシスタント|    ✗    |   ✗   |    ✓     |   ✓
 * 統計AI       |    ✗    |   ✗   |    ✗     |   ✓
 */
enum PlanCode: string
{
    case Starter  = 'starter';
    case Light    = 'light';
    case Standard = 'standard';
    case Premium  = 'premium';

    /**
     * テナントの plan_code / status から適用すべきプランを返す。
     *
     * trial ステータスまたは plan_code 未設定の場合は starter として扱う。
     * システム管理者（sysadmin）は premium として扱う。
     */
    public static function fromTenant(?string $planCode, string $status): self
    {
        if ($status === 'trial' || $planCode === null || $planCode === '') {
            return self::Starter;
        }
        return self::tryFrom($planCode) ?? self::Starter;
    }

    public function label(): string
    {
        return match($this) {
            self::Starter  => 'スターター',
            self::Light    => 'ライト',
            self::Standard => 'スタンダード',
            self::Premium  => 'プレミアム',
        };
    }

    /** 入居者の最大登録数。-1 は無制限。 */
    public function maxResidents(): int
    {
        return match($this) {
            self::Starter  => 30,
            self::Light    => 80,
            self::Standard => 200,
            self::Premium  => -1,
        };
    }

    public function allowsWeeklyBulk(): bool
    {
        return match($this) {
            self::Starter             => false,
            self::Light, self::Standard, self::Premium => true,
        };
    }

    public function allowsMonthlyBulk(): bool
    {
        return match($this) {
            self::Starter, self::Light => false,
            self::Standard, self::Premium => true,
        };
    }

    public function allowsExcelExport(): bool
    {
        return match($this) {
            self::Starter             => false,
            self::Light, self::Standard, self::Premium => true,
        };
    }

    public function allowsAiAssistant(): bool
    {
        return match($this) {
            self::Starter, self::Light => false,
            self::Standard, self::Premium => true,
        };
    }

    public function allowsStatsAi(): bool
    {
        return $this === self::Premium;
    }

    /** プランのバッジ色（Bootstrap クラス）。 */
    public function badgeClass(): string
    {
        return match($this) {
            self::Starter  => 'bg-secondary',
            self::Light    => 'bg-info text-dark',
            self::Standard => 'bg-primary',
            self::Premium  => 'bg-warning text-dark',
        };
    }
}
