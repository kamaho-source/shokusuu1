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

    /**
     * ストリーミングAI問い合わせを許可する。ログインしていれば許可する。
     */
    public function canAskStream(IdentityInterface $user, $resource): bool
    {
        return true;
    }

    /**
     * サジェスト質問一覧取得を許可する。ログインしていれば許可する。
     */
    public function canSuggestions(IdentityInterface $user, $resource): bool
    {
        return true;
    }

    /**
     * AI回答へのフィードバック記録を許可する。ログインしていれば許可する。
     */
    public function canFeedback(IdentityInterface $user, $resource): bool
    {
        return true;
    }
}