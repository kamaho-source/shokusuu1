<?php
declare(strict_types=1);

namespace App\Test\Fixture;

use Cake\TestSuite\Fixture\TestFixture;

/**
 * TReservationInfoFixture
 */
class TReservationInfoFixture extends TestFixture
{
    /**
     * Table name
     *
     * @var string
     */
    public string $table = 't_reservation_info';
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
                'dt_date' => '2025-06-25',
                'i_id_room' => 1,
                'i_id_user' => 1,
                'i_id_user_group' => 1,
                'dt_create' => '2025-05-21 19:20:24',
                's_create_user' => '秦晴彦',
                'dt_update' => null,
                's_update_user' => null
            ],
        ];
        parent::init();
    }
}
