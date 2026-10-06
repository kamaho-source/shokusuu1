<?php
declare(strict_types=1);

/**
 * CakePHP(tm) : Rapid Development Framework (https://cakephp.org)
 * Copyright (c) Cake Software Foundation, Inc. (https://cakefoundation.org)
 *
 * Licensed under The MIT License
 * For full copyright and license information, please see the LICENSE.txt
 * Redistributions of files must retain the above copyright notice
 *
 * @copyright     Copyright (c) Cake Software Foundation, Inc. (https://cakefoundation.org)
 * @link          https://cakephp.org CakePHP(tm) Project
 * @since         1.2.0
 * @license       https://opensource.org/licenses/mit-license.php MIT License
 */
namespace App\Test\TestCase\Controller;

use Cake\Core\Configure;
use Cake\TestSuite\Constraint\Response\StatusCode;
use Cake\TestSuite\IntegrationTestTrait;
use Cake\TestSuite\TestCase;

/**
 * PagesControllerTest class
 *
 * @uses \App\Controller\PagesController
 */
class PagesControllerTest extends TestCase
{
    use IntegrationTestTrait;

    protected array $fixtures = [
        'app.MNotice',
        'app.MUserInfo',
        'app.TIndividualReservationInfo',
    ];

    /**
     * testDisplay method
     *
     * @return void
     */
    public function testDisplay()
    {
        Configure::write('debug', true);
        // /pages/home は / (ダッシュボード) へリダイレクトされる
        $this->get('/pages/home');
        $this->assertRedirect('/');

        // ダッシュボード本体: 未ログイン時はログイン促進メッセージが表示される
        $this->get('/');
        $this->assertResponseOk();
        $this->assertResponseContains('ログインが必要です');
        $this->assertResponseContains('ログイン');
    }

    /**
     * Test that missing template renders 404 page in production
     *
     * @return void
     */
    public function testMissingTemplate()
    {
        Configure::write('debug', false);
        $this->get('/pages/not_existing');

        $this->assertResponseError();
        $this->assertResponseContains('見つかりません');
    }

    /**
     * Test that missing template in debug mode renders missing_template error page
     *
     * @return void
     */
    public function testMissingTemplateInDebug()
    {
        Configure::write('debug', true);
        $this->get('/pages/not_existing');

        $this->assertResponseFailure();
        $this->assertResponseContains('Missing Template');
        $this->assertResponseContains('stack-frames');
        $this->assertResponseContains('not_existing.php');
    }

    /**
     * Test directory traversal protection
     *
     * @return void
     */
    public function testDirectoryTraversalProtection()
    {
        Configure::write('debug', false);
        $this->get('/pages/../Layout/ajax');
        $this->assertResponseCode(403);
        $this->assertResponseContains('アクセス権限がありません');
    }

    /**
     * Test that CSRF protection is applied to page rendering.
     *
     * @return void
     */
    public function testCsrfAppliedError()
    {
        Configure::write('debug', false);
        $this->post('/pages/home', ['hello' => 'world']);

        $this->assertResponseCode(403);
        $this->assertResponseContains('アクセス権限がありません');
    }

    /**
     * Test that CSRF protection is applied to page rendering.
     *
     * @return void
     */
    public function testCsrfAppliedOk()
    {
        $this->enableCsrfToken();
        $this->post('/pages/home', ['hello' => 'world']);

        $this->assertThat(403, $this->logicalNot(new StatusCode($this->_response)));
        $this->assertResponseNotContains('CSRF');
    }

    /**
     * ダッシュボードが承認オフ(既定)でログイン済みでも200で描画される。
     * （承認件数クエリをスキップする分岐が壊れていないことの担保）
     */
    public function testDashboard_loggedIn_approvalOff_returnsOk(): void
    {
        $original = Configure::read('Features.approval');
        Configure::write('Features.approval', false);
        try {
            $this->loginAsAdmin();
            $this->get('/');
            $this->assertResponseOk();
            $this->assertResponseNotContains('承認履歴');
        } finally {
            Configure::write('Features.approval', $original);
        }
    }

    /**
     * ダッシュボードが承認オン時もログイン済みで200で描画される。
     * （承認件数クエリを実行する分岐が壊れていないことの担保）
     */
    public function testDashboard_loggedIn_approvalOn_returnsOk(): void
    {
        $original = Configure::read('Features.approval');
        Configure::write('Features.approval', true);
        try {
            $this->loginAsAdmin();
            $this->get('/');
            $this->assertResponseOk();
        } finally {
            Configure::write('Features.approval', $original);
        }
    }

    private function loginAsAdmin(): void
    {
        $this->session([
            'Auth' => [
                'i_id_user'       => 1,
                'c_login_account' => 'admin_user',
                'c_user_name'     => 'テスト管理者',
                'i_admin'         => 1,
                'i_user_level'    => 0,
                'i_id_room'       => 1,
            ],
        ]);
    }
}
