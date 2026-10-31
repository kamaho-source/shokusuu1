<?php
declare(strict_types=1);

namespace App\Application\UseCase\ToggleMeal;

use App\Domain\Exception\InvalidInputException;

/**
 * 1件の食事予約の登録・取消（トグル）の Input DTO。
 *
 * 日付・食事・値の形式は、権限・所属の判定との順番を保つため UseCase の中で検証する（ここでは受け取るだけ）。
 */
final class ToggleMealInput
{
    public function __construct(
        public readonly int $roomId,
        public readonly string $date,
        public readonly ?int $meal,
        public readonly ?int $value,
        public readonly int $targetUserId,
        public readonly int $loginUserId,
        public readonly string $loginUserName,
        /** true なら、同じ部屋の昼食⇔弁当が登録済みのとき入れ替えずに止める */
        public readonly bool $noSwap = false,
        /** true なら、管理者・システム管理者は所属外の部屋も操作できる（エクセル食数予約のみ） */
        public readonly bool $adminAnyRoom = false,
        /** false なら、キャッシュの破棄を呼び出し側でまとめて行う */
        public readonly bool $invalidateCache = true,
    ) {}

    /**
     * 画面・API のリクエスト（date, meal, value, userId, noSwap）から作る。userId が無ければ操作者本人。
     *
     * @param array<string, mixed> $payload
     * @throws InvalidInputException リクエストが空のとき
     */
    public static function fromPayload(
        int $roomId,
        array $payload,
        int $loginUserId,
        string $loginUserName,
        bool $invalidateCache = true,
        bool $adminAnyRoom = false
    ): self {
        if ($payload === []) {
            throw new InvalidInputException('Empty request body.', 400);
        }

        return new self(
            roomId: $roomId,
            date: (string)($payload['date'] ?? ''),
            meal: isset($payload['meal']) ? (int)$payload['meal'] : null,
            value: isset($payload['value']) ? (int)$payload['value'] : null,
            targetUserId: isset($payload['userId']) ? (int)$payload['userId'] : $loginUserId,
            loginUserId: $loginUserId,
            loginUserName: $loginUserName,
            noSwap: !empty($payload['noSwap']),
            adminAnyRoom: $adminAnyRoom,
            invalidateCache: $invalidateCache,
        );
    }
}
