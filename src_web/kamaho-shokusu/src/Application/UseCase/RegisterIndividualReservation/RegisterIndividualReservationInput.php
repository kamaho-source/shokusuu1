<?php
declare(strict_types=1);

namespace App\Application\UseCase\RegisterIndividualReservation;

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
        /** 日付の検証で弾かれた理由（通ったなら null）。呼び出し側の画面ごとに基準が違うため、検証の結果を受け取る */
        public readonly ?string $dateError,
    ) {}
}
