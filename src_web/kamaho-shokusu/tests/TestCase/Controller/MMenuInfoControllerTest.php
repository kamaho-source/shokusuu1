<?php
declare(strict_types=1);

namespace App\Test\TestCase\Controller;

use App\Controller\MMenuInfoController;
use Cake\TestSuite\IntegrationTestTrait;
use Cake\TestSuite\TestCase;

/**
 * App\Controller\MMenuInfoController Test Case
 *
 * @uses \App\Controller\MMenuInfoController
 */
class MMenuInfoControllerTest extends TestCase
{
    use IntegrationTestTrait;

    /**
     * Fixtures
     *
     * @var list<string>
     */
    protected array $fixtures = [
        'app.MMenuInfo',
    ];

    /**
     * Test index method
     *
     * @return void
     * @uses \App\Controller\MMenuInfoController::index()
     */
    public function testIndex(): void
    {
        $this->get('/m-menu-info');
        $this->assertRedirectContains('/MUserInfo/login');
    }

    /**
     * Test view method
     *
     * @return void
     * @uses \App\Controller\MMenuInfoController::view()
     */
    public function testView(): void
    {
        $this->get('/m-menu-info/view/1');
        $this->assertRedirectContains('/MUserInfo/login');
    }

    /**
     * Test add method
     *
     * @return void
     * @uses \App\Controller\MMenuInfoController::add()
     */
    public function testAdd(): void
    {
        $this->get('/m-menu-info/add');
        $this->assertRedirectContains('/MUserInfo/login');
    }

    /**
     * Test edit method
     *
     * @return void
     * @uses \App\Controller\MMenuInfoController::edit()
     */
    public function testEdit(): void
    {
        $this->get('/m-menu-info/edit/1');
        $this->assertRedirectContains('/MUserInfo/login');
    }

    /**
     * Test delete method
     *
     * @return void
     * @uses \App\Controller\MMenuInfoController::delete()
     */
    public function testDelete(): void
    {
        $this->enableCsrfToken();
        $this->post('/m-menu-info/delete/1');
        $this->assertRedirectContains('/MUserInfo/login');
    }
}
