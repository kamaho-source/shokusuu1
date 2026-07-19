<?php
declare(strict_types=1);

namespace App\Application\Plan;

use App\Domain\ValueObject\PlanCode;
use Cake\Http\Response;

/**
 * 現在のテナントのプランに基づいて機能アクセスを制御するサービス。
 *
 * AppController::beforeFilter() で初期化され、コントローラーと
 * テンプレートの両方から参照される。
 *
 * システム管理者（sysadmin）にはプラン制限を適用しない（Premium 扱い）。
 */
final class PlanGuard
{
    public function __construct(
        private readonly PlanCode $plan,
        private readonly bool $isSysAdmin = false,
    ) {}

    public function plan(): PlanCode
    {
        return $this->plan;
    }

    public function allowsWeeklyBulk(): bool
    {
        return $this->isSysAdmin || $this->plan->allowsWeeklyBulk();
    }

    public function allowsMonthlyBulk(): bool
    {
        return $this->isSysAdmin || $this->plan->allowsMonthlyBulk();
    }

    public function allowsExcelExport(): bool
    {
        return $this->isSysAdmin || $this->plan->allowsExcelExport();
    }

    public function allowsAiAssistant(): bool
    {
        return $this->isSysAdmin || $this->plan->allowsAiAssistant();
    }

    public function allowsStatsAi(): bool
    {
        return $this->isSysAdmin || $this->plan->allowsStatsAi();
    }

    /**
     * 入居者の最大登録数。-1 は無制限。
     * システム管理者には制限を適用しない。
     */
    public function maxResidents(): int
    {
        return $this->isSysAdmin ? -1 : $this->plan->maxResidents();
    }

    /**
     * 入居者数が上限に達しているかを返す。
     *
     * @param int $currentCount 現在の入居者登録数
     */
    public function isResidentLimitReached(int $currentCount): bool
    {
        $max = $this->maxResidents();
        return $max !== -1 && $currentCount >= $max;
    }

    /**
     * 機能がブロックされている場合に Flash エラーを表示してリダイレクト先 URL を返す。
     * ブロックされていない場合は null を返す。
     *
     * コントローラーで以下のように使う:
     *   if ($dest = $this->planGuard->denyIfNot($allowed, $controller, '/')) {
     *       return $dest;
     *   }
     */
    public function upgradeRequiredMessage(): string
    {
        return sprintf(
            '現在のプラン（%s）ではこの機能は利用できません。プランのアップグレードをご検討ください。',
            $this->plan->label()
        );
    }
}
