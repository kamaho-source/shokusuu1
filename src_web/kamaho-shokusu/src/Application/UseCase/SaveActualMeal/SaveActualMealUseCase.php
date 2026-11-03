<?php
declare(strict_types=1);

namespace App\Application\UseCase\SaveActualMeal;

use App\Application\Port\AuditActor;
use App\Application\Port\AuditLogInterface;
use App\Application\Query\RoomQueryInterface;
use App\Domain\Exception\ConflictException;
use App\Domain\Repository\ActualMealRepositoryInterface;
use App\Domain\ValueObject\ApprovalStatus;
use Psr\Clock\ClockInterface;

/**
 * 実食の保存（i_change_flag と eat_flag を同じ値にする）。
 *
 * - 存在しない・削除済みの部屋には、管理者でも書き込めない
 * - 対象者が部屋に所属していなければ、既にある予約の更新だけできる（#821）
 * - 予約が無ければ新しく作り、あれば版数が合うときだけ更新する（楽観ロック）
 * - 承認済み（ブロック長・管理者）の予約は変えない
 * - 保存できたときだけ監査ログ（actual_meal_save）を残す。新しく作ったときは detail.created = true（#990）
 */
final class SaveActualMealUseCase
{
    /** 実食で扱う食事区分（朝・昼・夜） */
    private const MEAL_TYPES = [1, 2, 3];

    public function __construct(
        private readonly ActualMealRepositoryInterface $meals,
        private readonly RoomQueryInterface $rooms,
        private readonly AuditLogInterface $auditLog,
        private readonly ClockInterface $clock,
    ) {}

    /**
     * 失敗は Output の status で返す（DB の例外は投げ直す）。
     */
    public function execute(SaveActualMealInput $input): SaveActualMealOutput
    {
        $key = $input->key;
        if (!$this->rooms->isRoomAvailable($key->roomId)) {
            return SaveActualMealOutput::forbidden('選択された部屋は利用できません。');
        }
        if (!$this->rooms->isActiveMember($key->userId, $key->roomId) && !$this->meals->exists($key)) {
            return SaveActualMealOutput::forbidden('対象ユーザーは指定された部屋に所属していません。');
        }
        if (!in_array($key->mealType, self::MEAL_TYPES, true)) {
            return SaveActualMealOutput::rejected('無効な食事タイプです。');
        }

        $status = $this->meals->findApprovalStatus($key);
        if (ApprovalStatus::isApproved($status)) {
            return SaveActualMealOutput::rejected('承認済みのデータは変更できません。');
        }

        $now  = $this->clock->now()->format('Y-m-d H:i:s');
        $flag = $input->checked ? 1 : 0;
        // 監査ログの操作者IDは、以前から対象者のID（操作した人ではない）
        $actor  = new AuditActor($input->actorName, $key->userId, $input->actorLoginId, '');
        $target = "{$key->userId}:{$key->date}:{$key->roomId}:{$key->mealType}";

        if ($status === null) {
            try {
                if (!$this->meals->create($key, $flag, $input->actorName, $now)) {
                    return SaveActualMealOutput::rejected('保存に失敗しました。');
                }
            } catch (ConflictException) {
                return SaveActualMealOutput::rejected('データの状態が変わりました。画面を再読み込みしてください。');
            }

            // 更新と同じ形で残す。新規作成であることは detail.created で区別する
            $this->auditLog->record('actual_meal', 'actual_meal_save', $actor, 't_individual_reservation_info', $target, [
                'checked' => $input->checked, 'meal_type' => $key->mealType, 'created' => true,
            ], true);

            return SaveActualMealOutput::saved(1);
        }

        if ($this->meals->updateFlag($key, $flag, $input->expectedVersion, $input->actorName, $now) !== 1) {
            return SaveActualMealOutput::rejected('他のユーザーによって更新されています。画面を再読み込みしてください。');
        }

        $this->auditLog->record('actual_meal', 'actual_meal_save', $actor, 't_individual_reservation_info', $target, [
            'checked' => $input->checked, 'meal_type' => $key->mealType,
        ], true);

        return SaveActualMealOutput::saved($input->expectedVersion + 1);
    }
}
