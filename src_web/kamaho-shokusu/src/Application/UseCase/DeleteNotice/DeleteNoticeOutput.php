<?php
declare(strict_types=1);

namespace App\Application\UseCase\DeleteNotice;

/**
 * お知らせの削除の結果。
 */
final class DeleteNoticeOutput
{
    /**
     * @param bool $deleted 消せたか
     */
    public function __construct(
        public readonly bool $deleted,
    ) {}
}
