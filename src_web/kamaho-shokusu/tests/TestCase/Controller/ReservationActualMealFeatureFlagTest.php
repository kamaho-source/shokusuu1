<?php
declare(strict_types=1);

namespace App\Test\TestCase\Controller;

use Cake\Core\Configure;
use Cake\TestSuite\IntegrationTestTrait;
use Cake\TestSuite\TestCase;

/**
 * ReservationActualMealController 機能フラグ(Features.actualMeal) ガードの統合テスト
 *
 * - 実食機能オフ: 実食系URLは 404
 * - 食数一括管理(mealCountGrid)は実食機能とは独立のため、実食オフでも 404 にならない
 */
class ReservationActualMealFeatureFlagTest extends TestCase
{
    use IntegrationTestTrait;

    private mixed $original = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->original = Configure::read('Features.actualMeal');
    }

    protected function tearDown(): void
    {
        Configure::write('Features.actualMeal', $this->original);
        parent::tearDown();
    }

    public function testActualMealOff_management_returns404(): void
    {
        Configure::write('Features.actualMeal', false);
        $this->get('/TReservationInfo/actual-meal-management');
        $this->assertResponseCode(404);
    }

    public function testActualMealOff_myActualMeal_returns404(): void
    {
        Configure::write('Features.actualMeal', false);
        $this->get('/TReservationInfo/my-actual-meal');
        $this->assertResponseCode(404);
    }

    public function testActualMealOff_mealCountGrid_isNotBlocked(): void
    {
        Configure::write('Features.actualMeal', false);
        $this->get('/TReservationInfo/meal-count-grid');
        // 食数一括管理は実食機能のガード対象外。フラグでは塞がず 404 にならない。
        $this->assertResponseCode(302);
    }

    public function testActualMealOn_management_isNotBlockedByFlag(): void
    {
        Configure::write('Features.actualMeal', true);
        $this->get('/TReservationInfo/actual-meal-management');
        // フラグでは塞がない。未ログインなので認証リダイレクト等になり、404 にはならない。
        $this->assertResponseCode(302);
    }
}
