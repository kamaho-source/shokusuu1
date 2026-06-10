<?php
declare(strict_types=1);

namespace App\Exception;

/**
 * オプティミスティックロック競合例外
 *
 * 複数ユーザーが同じレコードを同時に更新しようとした場合にスローされる。
 * 呼び出し元はページ再読込を促すメッセージを返すべき。
 */
final class OptimisticLockConflictException extends \RuntimeException
{
    public function __construct(string $message = '他のユーザーが先に保存しました。ページを再読込して再試行してください。')
    {
        parent::__construct($message);
    }
}
