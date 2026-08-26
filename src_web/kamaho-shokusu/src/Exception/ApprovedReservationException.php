<?php
declare(strict_types=1);

namespace App\Exception;

/**
 * 承認済み予約変更例外
 *
 * i_approval_status が 1（ブロック長承認）または 2（管理者承認済み）の
 * 予約レコードを変更しようとした場合にスローされる。
 * 呼び出し元はユーザーへ「承認済みのため変更できない」旨を返すべき。
 */
final class ApprovedReservationException extends \RuntimeException
{
    public function __construct(string $message = '承認済みの予約は変更できません。')
    {
        parent::__construct($message);
    }
}
