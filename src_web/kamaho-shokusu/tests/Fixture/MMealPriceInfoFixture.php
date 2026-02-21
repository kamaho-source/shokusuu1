<?php
declare(strict_types=1);

namespace App\Test\Fixture;

use Cake\TestSuite\Fixture\TestFixture;

/**
 * MMealPriceInfoFixture
 */
class MMealPriceInfoFixture extends TestFixture
{
    /**
     * Table name
     *
     * @var string
     */
    public string $table = 'm_meal_price_info';

    /**
     * Init method
     *
     * @return void
     */
    public function init(): void
    {
        $this->records = [
            [
                'i_id' => 1,
                'i_fiscal_year' => 2024,
                'i_morning_price' => 300,
                'i_lunch_price' => 500,
                'i_dinner_price' => 600,
                'i_bento_price' => 450,
                'dt_create' => '2024-07-29 09:11:06',
                'c_create_user' => 'テストユーザー',
                'dt_update' => '2024-07-29 09:11:06',
                'c_update_user' => 'テストユーザー',
            ],
        ];
        parent::init();
    }
}
