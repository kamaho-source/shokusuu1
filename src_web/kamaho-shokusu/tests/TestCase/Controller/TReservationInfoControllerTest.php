<?php
declare(strict_types=1);

namespace App\Test\TestCase\Controller;

use Cake\TestSuite\IntegrationTestTrait;
use Cake\TestSuite\TestCase;

/**
 * App\Controller\TReservationInfoController Test Case
 *
 * @uses \App\Controller\TReservationInfoController
 */
class TReservationInfoControllerTest extends TestCase
{
    use IntegrationTestTrait;

    /**
     * Fixtures
     *
     * @var list<string>
     */
    protected array $fixtures = [
        'app.TReservationInfo',
    ];

    /**
     * Test index method
     *
     * @return void
     * @uses \App\Controller\TReservationInfoController::index()
     */
    public function testIndex(): void
    {
       //インデックスメソッドのテスト
        $this->get('/t-reservation-info');
        $this->assertResponseOk();
        $this->assertResponseContains('TReservationInfo');

    }

    /**
     * Test view method
     *
     * @return void
     * @uses \App\Controller\TReservationInfoController::view()
     */
    public function testView(): void
    {
        //ビューのテスト
        $this->get('/TReservationInfo/view?date=2025-07-02');
        $this->assertResponseOk();
        $this->assertResponseContains('2025-07-02');

    }

    /**
     * Test add method
     *
     * @return void
     * @uses \App\Controller\TReservationInfoController::add()
     */
    public function testAdd(): void
    {
        $this->markTestIncomplete('Not implemented yet.');
    }

    /**
     * Test edit method
     *
     * @return void
     * @uses \App\Controller\TReservationInfoController::edit()
     */
    public function testEdit(): void
    {
        $this->markTestIncomplete('Not implemented yet.');
    }

    /**
     * Test delete method
     *
     * @return void
     * @uses \App\Controller\TReservationInfoController::delete()
     */
    public function testDelete(): void
    {
        $this->markTestIncomplete('Not implemented yet.');
    }
}
