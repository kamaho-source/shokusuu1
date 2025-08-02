<?php

declare(strict_types=1);

namespace App\Model\Domain;

use RuntimeException;



final class LastMinuteChangeService
{
    public const STAFF = 0;
    public const CHILD = 1;

    public function assertCanChange(int $userLevel, int $eatFlag, int $changeFlag): void
    {
        //職員は直前追加は可能だか直前編集で食べないに変更不可
        if ($userLevel === self::STAFF && $eatFlag === 1 && $changeFlag === 0) {
            throw new RuntimeException('職員は直前編集で食べないに変更できません。');
        }

    }

}