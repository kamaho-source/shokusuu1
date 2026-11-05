<?php
declare(strict_types=1);

namespace App\Application\UseCase\ReflectApprovedToReservation;

use App\Application\Port\TransactionManagerInterface;
use App\Application\UseCase\Approval\ApprovalWriter;

/**
 * 最終承認済みの予約を予約集計（t_reservation_info）へ反映する（管理者）。途中で失敗したら書いた分も取り消す。
 */
final class ReflectApprovedToReservationUseCase
{
    public function __construct(
        private readonly ApprovalWriter $writer,
        private readonly TransactionManagerInterface $transaction,
    ) {}

    /**
     * @throws \Throwable 保存に失敗したとき（取り消してから投げ直す）
     */
    public function execute(ReflectApprovedToReservationInput $input): ReflectApprovedToReservationOutput
    {
        $this->transaction->begin();
        try {
            $output = $this->writer->reflect($input->roomId, $input->dateFrom, $input->dateTo, $input->actorName);
            $this->transaction->commit();
        } catch (\Throwable $e) {
            $this->transaction->rollback();
            throw $e;
        }

        return $output;
    }
}
