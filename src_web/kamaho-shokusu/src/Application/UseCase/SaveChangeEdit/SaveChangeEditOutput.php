<?php
declare(strict_types=1);

namespace App\Application\UseCase\SaveChangeEdit;

/**
 * 直前編集の保存の結果。
 */
final class SaveChangeEditOutput
{
    /**
     * @param list<string> $updated 更新した "利用者ID:食事"
     * @param list<string> $created 作った "利用者ID:食事"
     * @param list<string> $skipped 変えられなかった理由
     */
    public function __construct(
        public readonly array $updated,
        public readonly array $created,
        public readonly array $skipped,
    ) {}
}
