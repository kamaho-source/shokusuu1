<?php
declare(strict_types=1);

namespace App\Application\UseCase\DirectRegisterMeals;

use App\Domain\Exception\ConflictException;
use App\Domain\Exception\DuplicateMealConflictException;
use App\Domain\Exception\DomainException;
use App\Application\UseCase\ToggleMeal\ToggleMealInput;
use App\Application\UseCase\ToggleMeal\ToggleMealUseCase;

/**
 * カレンダー日付クリックによる複数食事の一括直接登録ユースケース。
 *
 * モーダルを介さず dateClick イベントから呼ばれる専用フロー。
 * 各食事の登録を ToggleMealUseCase に委譲し、
 * 成功分・スキップ分・失敗分を分けて返す。食事ごとに確定するので、途中の食事が失敗しても先の食事は登録済みのまま返す。
 */
final class DirectRegisterMealsUseCase
{
    public function __construct(
        private readonly ToggleMealUseCase $toggleMeal,
    ) {}

    /**
     * @throws DomainException どの食事も登録・スキップできなかったとき（最初の食事の例外。権限不足・DB障害など）
     */
    public function execute(DirectRegisterMealsInput $input): DirectRegisterMealsOutput
    {
        $registered = [];
        $skipped    = [];
        $failed     = [];
        $firstError = null;

        foreach ($input->mealIndices as $meal) {
            try {
                $this->toggleMeal->execute(new ToggleMealInput(
                    roomId:        $input->roomId,
                    date:          $input->date,
                    meal:          $meal,
                    value:         1,
                    targetUserId:  $input->targetUserId,
                    loginUserId:   $input->loginUserId,
                    loginUserName: $input->loginUserName,
                    noSwap:        $input->skipLunchBentoSwap,
                ));
                $registered[] = $meal;
            } catch (DomainException $e) {
                if ($this->isSkippable($e)) {
                    $skipped[] = $meal;
                    continue;
                }
                $failed[]   = ['meal' => $meal, 'message' => $e->getMessage()];
                $firstError ??= $e;
            }
        }

        // 1件も登録・スキップできなければ、従来どおりエラーとして返す
        if ($firstError !== null && $registered === [] && $skipped === []) {
            throw $firstError;
        }

        return new DirectRegisterMealsOutput(
            registered: $registered,
            skipped:    $skipped,
            failed:     $failed,
        );
    }

    /**
     * 一括直接登録で個別食事をスキップしてよい例外か判定する。
     *
     * 重複登録・昼食/弁当競合のみスキップ対象。権限不足・DB障害・楽観ロック競合は再スロー。
     */
    private function isSkippable(DomainException $e): bool
    {
        // 別の部屋で同じ食事を予約済み（画面を開いた後に変わった場合など）は、この食事だけ飛ばす
        if ($e instanceof DuplicateMealConflictException) {
            return true;
        }
        if (!$e instanceof ConflictException) {
            return false;
        }

        $message = $e->getMessage();

        return str_contains($message, '昼食と弁当')
            || str_contains($message, '既に登録');
    }
}
