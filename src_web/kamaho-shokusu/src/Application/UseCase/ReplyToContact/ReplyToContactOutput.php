<?php
declare(strict_types=1);

namespace App\Application\UseCase\ReplyToContact;

/**
 * 管理者の返信の結果。sent は「保存してメールも送れたか」（メールだけ失敗しても履歴は残る）。
 */
final class ReplyToContactOutput
{
    public function __construct(public readonly bool $sent) {}
}
