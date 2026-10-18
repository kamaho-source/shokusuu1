<?php
declare(strict_types=1);

namespace App\Exception;

/**
 * 同じ利用者・同じ日・同じ食事を、複数の部屋で同時に有効にしようとした場合の例外。
 *
 * 食数が部屋ごとに二重に数えられるのを防ぐため、予約テーブルへの保存・更新の時点でスローされる。
 * 呼び出し元は 409（競合）として扱い、利用者へ再読み込みをうながすべき。
 */
final class DuplicateMealReservationException extends \RuntimeException
{
    public function __construct(string $message = '同じ日の同じ食事が、別の部屋ですでに予約されています。画面を再読み込みしてください。')
    {
        parent::__construct($message);
    }
}
