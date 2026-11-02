<?php
declare(strict_types=1);

namespace App\Application\UseCase\CreateNotice;

/**
 * お知らせの登録の結果。
 */
final class CreateNoticeOutput
{
    /**
     * @param int $id 登録したお知らせの ID
     */
    public function __construct(
        public readonly int $id,
    ) {}
}
