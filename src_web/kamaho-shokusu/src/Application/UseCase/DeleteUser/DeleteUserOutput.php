<?php
declare(strict_types=1);

namespace App\Application\UseCase\DeleteUser;

/**
 * 利用者の削除の結果。
 */
final class DeleteUserOutput
{
    public function __construct(public readonly bool $deleted) {}
}
