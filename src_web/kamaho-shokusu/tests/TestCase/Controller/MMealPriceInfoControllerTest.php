<?php
declare(strict_types=1);

namespace App\Test\TestCase\Controller;

use App\Controller\MMealPriceInfoController;
use Cake\TestSuite\IntegrationTestTrait;
use Cake\TestSuite\TestCase;

/**
 * App\Controller\MMealPriceInfoController Test Case
 *
 * @uses \App\Controller\MMealPriceInfoController
 */
class MMealPriceInfoControllerTest extends TestCase
{
    use IntegrationTestTrait;

    /**
     * Fixtures
     *
     * @var list<string>
     */
    protected array $fixtures = [
        'app.MMealPriceInfo',
    ];

    /**
     * Test index method
     *
     * @return void
     * @uses \App\Controller\MMealPriceInfoController::index()
     */
    public function testIndex(): void
    {
        $this->setAuthenticatedSession();
        $this->get('/m-meal-price-info');
        $this->assertResponseOk();
    }

    /**
     * Test view method
     *
     * @return void
     * @uses \App\Controller\MMealPriceInfoController::view()
     */
    public function testView(): void
    {
        $this->setAuthenticatedSession();
        $this->get('/m-meal-price-info/view/1');
        $this->assertResponseOk();
    }

    /**
     * Test add method
     *
     * @return void
     * @uses \App\Controller\MMealPriceInfoController::add()
     */
    public function testAdd(): void
    {
        $this->setAuthenticatedSession();
        $this->enableCsrfToken();
        $this->post('/m-meal-price-info/add', [
            'i_fiscal_year' => 2025,
            'i_morning_price' => 320,
            'i_lunch_price' => 520,
            'i_dinner_price' => 620,
            'i_bento_price' => 470,
        ]);
        $this->assertResponseSuccess();
        $this->assertRedirect(['action' => 'index']);
    }

    /**
     * Test edit method
     *
     * @return void
     * @uses \App\Controller\MMealPriceInfoController::edit()
     */
    public function testEdit(): void
    {
        $this->setAuthenticatedSession();
        $this->enableCsrfToken();
        $this->post('/m-meal-price-info/edit/1', [
            'i_morning_price' => 350,
        ]);
        $this->assertResponseSuccess();
        $this->assertRedirect(['action' => 'index']);
    }

    /**
     * Test delete method
     *
     * @return void
     * @uses \App\Controller\MMealPriceInfoController::delete()
     */
    public function testDelete(): void
    {
        $this->setAuthenticatedSession();
        $this->enableCsrfToken();
        $this->post('/m-meal-price-info/delete/1');
        $this->assertResponseSuccess();
        $this->assertRedirect(['action' => 'index']);
    }

    private function setAuthenticatedSession(): void
    {
        $this->session([
            'Auth' => [
                'i_id_user' => 1,
                'c_user_name' => 'テストユーザー',
                'i_admin' => 1,
                'i_user_level' => 0,
                'i_id_room' => 1,
            ],
        ]);
    }
}
