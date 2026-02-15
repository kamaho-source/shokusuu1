<?php
declare(strict_types=1);

namespace App\Test\Fixture;

use Cake\TestSuite\Fixture\TestFixture;

/**
 * MMenuInfoFixture
 */
class MMenuInfoFixture extends TestFixture
{
    /**
     * Table name
     *
     * @var string
     */
    public string $table = 'm_menu_info';

    /**
     * Init method
     *
     * @return void
     */
    public function init(): void
    {
        $this->records = [
            [
                'id' => 1,
                'c_menu_name' => 'テストメニュー',
                'dt_create' => '2024-07-29 09:11:06',
                'dt_update' => '2024-07-29 09:11:06',
            ],
        ];
        parent::init();
    }
}
