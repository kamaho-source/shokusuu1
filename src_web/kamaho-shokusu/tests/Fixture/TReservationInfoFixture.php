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
                'd_reservation_date' => '2024-08-03',
                'i_id_room' => 1,
                'c_reservation_type' => 1,
                'i_taberu_ninzuu' => 1,
                'i_tabenai_ninzuu' => 1,
                'dt_create' => '2024-08-03 07:55:38',
                'c_create_user' => 'Lorem ipsum dolor sit amet',
                'dt_update' => '2024-08-03 07:55:38',
                'c_update_user' => 'Lorem ipsum dolor sit amet',
            ],
        ];
        parent::init();
    }
}
