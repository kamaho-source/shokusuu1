<?php
declare(strict_types=1);

namespace App\Application\UseCase\DirectRegisterMeals;

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
     * @throws DomainException 全件失敗など致命的なエラー時
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
                // すでに登録済み / バリデーション違反はスキップ扱い
                $skipped[] = $meal;
            }
        }

        return new DirectRegisterMealsOutput(
            registered: $registered,
            skipped:    $skipped,
        );
    }
}
