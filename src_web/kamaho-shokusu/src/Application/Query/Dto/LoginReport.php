<?php
declare(strict_types=1);

namespace App\Application\Query\Dto;

/**
 * ログイン情報（日ごとの成功・失敗の数と、ログインの記録。記録は新しい順）。
 */
final class LoginReport
{
    /**
     * @param list<array{date: string, success: int, failed: int}> $daily
     * @param list<array{dt: string, user_name: string, login_id: string, result: int, ip: string}> $logs
     */
    public function __construct(
        public readonly array $daily,
        public readonly array $logs,
    ) {}
}
