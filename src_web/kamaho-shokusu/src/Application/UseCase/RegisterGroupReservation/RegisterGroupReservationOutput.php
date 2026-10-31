<?php
declare(strict_types=1);

namespace App\Application\UseCase\RegisterGroupReservation;

/**
 * 部屋単位の予約の Output DTO。
 */
final class RegisterGroupReservationOutput
{
    /**
     * @param array<string, mixed> $data skipped（スキップした予約）・date
     */
    public function __construct(
        public readonly string $message,
        public readonly array $data,
    ) {}

    /**
     * 従来の配列の形（ok, message, data, redirect）。
     *
     * @return array{ok: true, message: string, data: array<string, mixed>, redirect: string}
     */
    public function toArray(string $redirect): array
    {
        return ['ok' => true, 'message' => $this->message, 'data' => $this->data, 'redirect' => $redirect];
    }
}
