<?php
declare(strict_types=1);

namespace App\Application\UseCase\UpdateNotice;

use App\Domain\Entity\Notice;

/**
 * お知らせの更新の結果。失敗したときは、画面に出し直すお知らせ（入力の誤りなら今の内容、保存の失敗なら入力した内容）も返す。
 */
final class UpdateNoticeOutput
{
    public function __construct(
        public readonly bool $succeeded,
        public readonly ?string $error,
        public readonly Notice $notice,
    ) {}
}
