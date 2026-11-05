<?php
declare(strict_types=1);

namespace App\Application\UseCase\RegisterIndividualReservation;

use App\Application\Port\AuditActor;

/**
 * 個人予約（1人 × 1日 × 食事ごとに1部屋）の Input DTO。
 *
 * リクエストの形式（JSON・meals の有無）は、日付の検証との順番を保つため UseCase の中で検証する（ここでは受け取るだけ）。
 */
final class RegisterIndividualReservationInput
{
    /**
     * @param array<array-key, mixed>|string $data  リクエスト（JSON 文字列または配列。meals: 食事区分 => [部屋ID => 0/1]）
     * @param array<array-key, mixed>        $rooms 画面で扱う部屋（キーが部屋ID）
     */
    public function __construct(
        public readonly string $reservationDate,
        public readonly array|string $data,
        public readonly array $rooms,
        public readonly int $userId,
        public readonly string $userName,
        /** 呼び出し側の基準で日付を検証したときの弾かれた理由（通ったなら null）。直前編集は過去日だけを弾く。$checkNormalOrderDate のときは見ない */
        public readonly ?string $dateError = null,
        /** true なら通常予約の基準（予約日が空・形式・きょうから15日目以降）で UseCase が日付を検証する（予約の新規登録） */
        public readonly bool $checkNormalOrderDate = false,
        /** 監査ログに残す操作者。null なら残さない（直前編集の個人の保存は残していない） */
        public readonly ?AuditActor $auditActor = null,
    ) {}
}
