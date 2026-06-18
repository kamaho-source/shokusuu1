<?php
declare(strict_types=1);

namespace App\Test\TestCase\Policy;

use App\Controller\NotificationsController;
use App\Policy\NotificationPolicy;
use Cake\TestSuite\TestCase;

/**
 * NotificationPolicy テスト
 *
 * 認証済みユーザーは全員 canIndex / canMarkRead / canMarkAllRead を許可される。
 * 未認証（null）は拒否される。
 */
class NotificationPolicyTest extends TestCase
{
    private NotificationPolicy $policy;
    private NotificationsController $resource;

    protected function setUp(): void
    {
        parent::setUp();
        $this->policy   = new NotificationPolicy();
        $this->resource = $this->createMock(NotificationsController::class);
    }

    // ----------------------------------------------------------------
    // canIndex
    // ----------------------------------------------------------------

    /**
     * @dataProvider authenticatedUserProvider
     */
    public function testCanIndex_authenticated_allowed(int $adminLevel): void
    {
        $user = new PolicyTestIdentity(['i_admin' => $adminLevel]);
        $this->assertTrue($this->policy->canIndex($user, $this->resource));
    }

    public function testCanIndex_nullUser_denied(): void
    {
        $this->assertFalse($this->policy->canIndex(null, $this->resource));
    }

    // ----------------------------------------------------------------
    // canMarkRead
    // ----------------------------------------------------------------

    /**
     * @dataProvider authenticatedUserProvider
     */
    public function testCanMarkRead_authenticated_allowed(int $adminLevel): void
    {
        $user = new PolicyTestIdentity(['i_admin' => $adminLevel]);
        $this->assertTrue($this->policy->canMarkRead($user, $this->resource));
    }

    public function testCanMarkRead_nullUser_denied(): void
    {
        $this->assertFalse($this->policy->canMarkRead(null, $this->resource));
    }

    // ----------------------------------------------------------------
    // canMarkAllRead
    // ----------------------------------------------------------------

    /**
     * @dataProvider authenticatedUserProvider
     */
    public function testCanMarkAllRead_authenticated_allowed(int $adminLevel): void
    {
        $user = new PolicyTestIdentity(['i_admin' => $adminLevel]);
        $this->assertTrue($this->policy->canMarkAllRead($user, $this->resource));
    }

    public function testCanMarkAllRead_nullUser_denied(): void
    {
        $this->assertFalse($this->policy->canMarkAllRead(null, $this->resource));
    }

    // ----------------------------------------------------------------
    // DataProviders
    // ----------------------------------------------------------------

    public static function authenticatedUserProvider(): array
    {
        return [
            'general (0)'      => [0],
            'admin (1)'        => [1],
            'block_leader (2)' => [2],
            'system_admin (3)' => [3],
        ];
    }
}
