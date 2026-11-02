<?php
declare(strict_types=1);

namespace App\Application\UseCase\CreateRoom;

/**
 * 部屋の作成の結果。
 */
final class CreateRoomOutput
{
    /**
     * @param array<string, array<string, string>> $fieldErrors 保存できなかったときの項目ごとの理由
     */
    public function __construct(
        public readonly bool $created,
        public readonly array $fieldErrors = [],
    ) {}
}
