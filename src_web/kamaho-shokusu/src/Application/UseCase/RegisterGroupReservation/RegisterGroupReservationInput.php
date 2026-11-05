<?php
declare(strict_types=1);

namespace App\Application\UseCase\RegisterGroupReservation;

use App\Application\Port\AuditActor;

/**
 * 部屋単位の予約（1部屋 × 1日 × 複数利用者）の Input DTO。
 *
 * リクエストの形式（JSON・users の有無）は、日付の検証との順番を保つため UseCase の中で検証する（ここでは受け取るだけ）。
 */
final class RegisterGroupReservationInput
{
    /**
     * @param array<array-key, mixed>|string $data  リクエスト（JSON 文字列または配列。i_id_room と users: 利用者ID => [食事区分 => 0/1]）
     * @param array<array-key, mixed>        $rooms 画面で扱う部屋（キーが部屋ID）
     */
    public function __construct(
        public readonly string $reservationDate,
        public readonly array|string $data,
        public readonly array $rooms,
        public readonly string $creatorName,
        /** 呼び出し側の基準で日付を検証したときの弾かれた理由（通ったなら null）。$checkNormalOrderDate のときは見ない */
        public readonly ?string $dateError = null,
        public readonly int $loginUserId = 0,
        public readonly bool $isAdmin = false,
        public readonly int $loginUserLevel = 0,
        public readonly bool $isBlockLeader = false,
        /** true なら通常予約の基準（予約日が空・形式・きょうから15日目以降）で UseCase が日付を検証する（予約の新規登録） */
        public readonly bool $checkNormalOrderDate = false,
        /** 監査ログに残す操作者。null なら残さない */
        public readonly ?AuditActor $auditActor = null,
    ) {}
}
