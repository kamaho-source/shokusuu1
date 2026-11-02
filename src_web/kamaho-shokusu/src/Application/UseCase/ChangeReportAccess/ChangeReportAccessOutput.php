<?php
declare(strict_types=1);

namespace App\Application\UseCase\ChangeReportAccess;

/**
 * レポート閲覧権の変更の結果。
 */
final class ChangeReportAccessOutput
{
    public function __construct(public readonly bool $saved) {}
}
