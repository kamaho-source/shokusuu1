<?php
declare(strict_types=1);

namespace App\Application\UseCase\DirectRegisterMeals;

use App\Domain\Exception\ConflictException;
use App\Domain\Exception\DomainException;
use App\Service\ReservationWriteService;

/**
 * カレンダー日付クリックによる複数食事の一括直接登録ユースケース。
 *
 * モーダルを介さず dateClick イベントから呼ばれる専用フロー。
 * 各食事の登録を ReservationWriteService::processToggle() に委譲し、
 * 成功分・スキップ分を分けて返す。
 */
final class DirectRegisterMealsUseCase
{
    public function __construct(
        private readonly ReservationWriteService $writeService,
    ) {}

    /**
     * @throws DomainException 権限不足・DB障害など致命的なエラー時
     */
    public function execute(DirectRegisterMealsInput $input): DirectRegisterMealsOutput
    {
        $registered = [];
        $skipped    = [];

        foreach ($input->mealIndices as $meal) {
            $payload = [
                'date'   => $input->date,
                'meal'   => $meal,
                'value'  => 1,
                'userId' => $input->targetUserId,
            ];

            try {
                $this->writeService->processToggle(
                    roomId:        $input->roomId,
                    payload:       $payload,
                    loginUserId:   $input->loginUserId,
                    loginUserName: $input->loginUserName,
                );
                $registered[] = $meal;
            } catch (DomainException $e) {
                if (!$this->isSkippable($e)) {
                    throw $e;
                }
                $skipped[] = $meal;
            }
        }

        return new DirectRegisterMealsOutput(
            registered: $registered,
            skipped:    $skipped,
        );
    }

    /**
     * 一括直接登録で個別食事をスキップしてよい例外か判定する。
     *
     * 重複登録・昼食/弁当競合のみスキップ対象。権限不足・DB障害・楽観ロック競合は再スロー。
     */
    private function isSkippable(DomainException $e): bool
    {
        if (!$e instanceof ConflictException) {
            return false;
        }

        $message = $e->getMessage();

        return str_contains($message, '昼食と弁当')
            || str_contains($message, '既に登録');
    }
}
