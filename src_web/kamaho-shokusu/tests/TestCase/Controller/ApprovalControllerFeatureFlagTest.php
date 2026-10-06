<?php
declare(strict_types=1);

namespace App\Test\TestCase\Controller;

use Cake\Core\Configure;
use Cake\TestSuite\IntegrationTestTrait;
use Cake\TestSuite\TestCase;

/**
 * ApprovalController 機能フラグ(Features.approval) ガードの統合テスト
 *
 * - 承認機能オフ: 承認系URLは 404（直接アクセスも塞ぐ）
 * - 承認機能オン: フラグでは塞がず、通常の認証フロー（未ログインはリダイレクト）へ進む
 */
class ApprovalControllerFeatureFlagTest extends TestCase
{
    use IntegrationTestTrait;

    private mixed $original = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->original = Configure::read('Features.approval');
    }

    protected function tearDown(): void
    {
        Configure::write('Features.approval', $this->original);
        parent::tearDown();
    }

    public function testApprovalOff_blockLeaderIndex_returns404(): void
    {
        Configure::write('Features.approval', false);
        $this->get('/Approval/blockLeaderIndex');
        $this->assertResponseCode(404);
    }

    public function testApprovalOff_adminIndex_returns404(): void
    {
        Configure::write('Features.approval', false);
        $this->get('/Approval/adminIndex');
        $this->assertResponseCode(404);
    }

    public function testApprovalOn_blockLeaderIndex_isNotBlockedByFlag(): void
    {
        Configure::write('Features.approval', true);
        $this->get('/Approval/blockLeaderIndex');
        // フラグでは塞がない。未ログインなので認証リダイレクト等になり、404 にはならない。
        $this->assertResponseCode(302);
    }
}
