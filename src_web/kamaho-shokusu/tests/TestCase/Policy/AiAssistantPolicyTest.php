<?php
declare(strict_types=1);

namespace App\Test\TestCase\Policy;

use App\Policy\AiAssistantPolicy;
use App\Policy\FeatureUsageSummaryPolicy;
use App\Policy\StatsAiPolicy;
use Cake\TestSuite\TestCase;

/**
 * AiAssistantPolicy / StatsAiPolicy / FeatureUsageSummaryPolicy テスト
 */
class AiAssistantPolicyTest extends TestCase
{
    public function testAiAssistant_allActionsAllowedForAnyLoggedInUser(): void
    {
        $policy = new AiAssistantPolicy();
        $user   = new PolicyTestIdentity(['i_admin' => 0]);
        $res    = new \stdClass();

        $this->assertTrue($policy->canAsk($user, $res));
        $this->assertTrue($policy->canAskStream($user, $res));
        $this->assertTrue($policy->canSuggestions($user, $res));
        $this->assertTrue($policy->canFeedback($user, $res));
    }

    /**
     * @return array<string, array{int, bool}>
     */
    public static function adminLevelProvider(): array
    {
        return [
            '一般' => [0, false],
            '管理者' => [1, false],
            'ブロック長' => [2, false],
            'システム管理者' => [3, true],
        ];
    }

    /**
     * @dataProvider adminLevelProvider
     */
    public function testStatsAi_onlySystemAdmin(int $admin, bool $expected): void
    {
        $policy = new StatsAiPolicy();
        $user   = new \Authorization\IdentityDecorator(
            $this->createMock(\Authorization\AuthorizationServiceInterface::class),
            new \Cake\ORM\Entity(['i_admin' => $admin])
        );

        $this->assertSame($expected, $policy->canIndex($user, new \stdClass()));
        $this->assertSame($expected, $policy->canAskStream($user, new \stdClass()));
    }

    /**
     * @dataProvider adminLevelProvider
     */
    public function testFeatureUsageSummary_onlySystemAdmin(int $admin, bool $expected): void
    {
        $this->assertSame(
            $expected,
            (new FeatureUsageSummaryPolicy())->canIndex(new PolicyTestIdentity(['i_admin' => $admin]), new \stdClass())
        );
    }

    public function testFeatureUsageSummary_nullUser_denied(): void
    {
        $this->assertFalse((new FeatureUsageSummaryPolicy())->canIndex(null, new \stdClass()));
    }

    public function testFeatureUsageSummary_missingAdminKey_denied(): void
    {
        $this->assertFalse((new FeatureUsageSummaryPolicy())->canIndex(new PolicyTestIdentity([]), new \stdClass()));
    }

    public function testFeatureUsageSummary_entityIdentity_usesGet(): void
    {
        $entity = new \Cake\ORM\Entity(['i_admin' => 3]);
        $user   = new \Authorization\IdentityDecorator($this->createMock(\Authorization\AuthorizationServiceInterface::class), $entity);

        $this->assertTrue((new FeatureUsageSummaryPolicy())->canIndex($user, new \stdClass()));
    }

    public function testFeatureUsageSummary_arrayAccessIdentity(): void
    {
        $user = new \Authorization\IdentityDecorator(
            $this->createMock(\Authorization\AuthorizationServiceInterface::class),
            new \ArrayObject(['i_admin' => 3])
        );

        $this->assertTrue((new FeatureUsageSummaryPolicy())->canIndex($user, new \stdClass()));
    }
}
