<?php
declare(strict_types=1);

namespace App\Application\UseCase\BulkAddReservations;

use App\Application\Port\ReservationDatePolicyInterface;
use App\Domain\Repository\RoomMembershipRepositoryInterface;

/**
 * 一括登録で選んだ日付・部屋の検証（個人・部屋単位で共通）。
 */
final class BulkAddRequestValidator
{
    public function __construct(
        private readonly ReservationDatePolicyInterface $datePolicy,
        private readonly RoomMembershipRepositoryInterface $rooms,
        /** 過去日を選んだときの文言（設定 App.messages.pastDateUnavailable） */
        private readonly string $pastDateMessage,
    ) {}

    /**
     * 選んだ日付の検証。通らなければ理由、通れば null。
     *
     * @param array<array-key, mixed> $dates
     * @param bool $enforceNormalRule true なら通常予約の日付ルール（15日目以降）も守らせる
     */
    public function dateError(array $dates, bool $enforceNormalRule): ?string
    {
        if (empty($dates)) {
            return '日付が選択されていません。';
        }

        foreach ($dates as $date) {
            if (!is_string($date) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
                return '無効な日付が含まれています。';
            }

            if ($this->datePolicy->isPastDate($date)) {
                return $this->pastDateMessage;
            }

            if ($enforceNormalRule) {
                $policyResult = $this->datePolicy->validateReservationDate($date);
                if ($policyResult !== true) {
                    return (string)$policyResult;
                }
            }
        }

        return null;
    }

    /**
     * 選んだ部屋の検証（存在し、削除されていないこと）。通らなければ理由、通れば null。
     *
     * @param list<int> $roomIds
     */
    public function roomError(array $roomIds): ?string
    {
        $roomIds = array_values(array_unique(array_filter(array_map('intval', $roomIds), static fn($v) => $v > 0)));
        if (empty($roomIds)) {
            return '部屋が選択されていません。';
        }

        foreach ($roomIds as $roomId) {
            if (!$this->rooms->isRoomAvailable($roomId)) {
                return '選択された部屋情報が不正です。画面を再読み込みして再実行してください。';
            }
        }

        return null;
    }
}
