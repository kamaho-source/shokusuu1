<?php
declare(strict_types=1);

namespace App\Test\Fixture;

use Cake\TestSuite\Fixture\TestFixture;

/**
 * TIndividualReservationInfoFixture
 */
class TIndividualReservationInfoFixture extends TestFixture
{
    /**
     * Table name
     *
     * @var string
     */
    public string $table = 't_individual_reservation_info';
    /**
     * Init method
     *
     * @return void
     */
    public function init(): void
    {
        $this->records = [
            [
                'i_id_user' => 1,
                'd_reservation_date' => '2024-09-07',
                'i_reservation_type' => 1,
                'i_id_room' => 1,
                'eat_flag' => 1,
                'dt_create' => '2024-09-07 16:00:30',
                'c_create_user' => 'Lorem ipsum dolor sit amet',
                'dt_update' => '2024-09-07 16:00:30',
                'c_update_user' => 'Lorem ipsum dolor sit amet',
            ],
        ];
        parent::init();
    }
}
