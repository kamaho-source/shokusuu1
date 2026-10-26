<?php
declare(strict_types=1);

namespace App\Test\TestCase\Policy;

use App\Policy\AiAssistantPolicy;
use Cake\TestSuite\TestCase;

/**
 * AiAssistantPolicy テスト。ログイン済みなら全アクションを許可する。
 */
class AiAssistantPolicyTest extends TestCase
{
    public function testAllActions_allowedForLoggedInUser(): void
    {
        $policy = new AiAssistantPolicy();
        $user = new PolicyTestIdentity(['i_admin' => 0]);

        $this->assertTrue($policy->canAsk($user, null));
        $this->assertTrue($policy->canAskStream($user, null));
        $this->assertTrue($policy->canSuggestions($user, null));
        $this->assertTrue($policy->canFeedback($user, null));
    }
}
