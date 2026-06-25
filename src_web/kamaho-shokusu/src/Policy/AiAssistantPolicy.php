<?php
declare(strict_types=1);

namespace App\Policy;

use Authorization\IdentityInterface;

class AiAssistantPolicy
{
    /**
     * AIへの質問を許可するかどうか。ログインしていれば許可する。
     */
    public function canAsk(IdentityInterface $user, $resource): bool
    {
        return true;
    }
}