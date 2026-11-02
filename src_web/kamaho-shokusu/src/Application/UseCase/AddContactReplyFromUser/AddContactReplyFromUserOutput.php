<?php
declare(strict_types=1);

namespace App\Application\UseCase\AddContactReplyFromUser;

/**
 * 画面からの返信の結果。
 */
final class AddContactReplyFromUserOutput
{
    /**
     * @param bool $saved 保存できたか（本人の問い合わせでない・本文が空などは false）
     */
    public function __construct(
        public readonly bool $saved,
    ) {}
}
