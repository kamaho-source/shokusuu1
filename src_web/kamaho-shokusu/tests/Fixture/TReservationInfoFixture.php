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
     * Fields
     *
     * @var array
     */
    public array $fields = [
        'd_reservation_date' => ['type' => 'date', 'length' => null, 'null' => false, 'default' => null, 'comment' => ''],
        'i_id_room' => ['type' => 'integer', 'length' => 11, 'unsigned' => false, 'null' => false, 'default' => null, 'comment' => ''],
        'c_reservation_type' => ['type' => 'tinyinteger', 'length' => 4, 'unsigned' => false, 'null' => false, 'default' => null, 'comment' => ''],
        'i_taberu_ninzuu' => ['type' => 'integer', 'length' => 11, 'unsigned' => false, 'null' => true, 'default' => null, 'comment' => ''],
        'i_tabenai_ninzuu' => ['type' => 'integer', 'length' => 11, 'unsigned' => false, 'null' => true, 'default' => null, 'comment' => ''],
        'dt_create' => ['type' => 'datetime', 'length' => null, 'null' => true, 'default' => null, 'comment' => ''],
        'c_create_user' => ['type' => 'string', 'length' => 50, 'null' => true, 'default' => null, 'collate' => 'utf8mb4_general_ci', 'comment' => ''],
        'dt_update' => ['type' => 'datetime', 'length' => null, 'null' => true, 'default' => null, 'comment' => ''],
        'c_update_user' => ['type' => 'string', 'length' => 50, 'null' => true, 'default' => null, 'collate' => 'utf8mb4_general_ci', 'comment' => ''],
        '_constraints' => [
            'primary' => ['type' => 'primary', 'columns' => ['d_reservation_date', 'i_id_room', 'c_reservation_type'], 'length' => []],
        ],
        '_options' => [
            'engine' => 'InnoDB',
            'collation' => 'utf8mb4_general_ci'
        ],
    ];

    /**
     * Init method
     *
     * @return void
     */
    public function init(): void
    {
        $this->records = [
            [
                'd_reservation_date' => '2025-06-25',
                'i_id_room' => 1,
                'c_reservation_type' => 1,
                'i_taberu_ninzuu' => 5,
                'i_tabenai_ninzuu' => 0,
                'dt_create' => '2025-05-21 19:20:24',
                'c_create_user' => '秦晴彦',
                'dt_update' => null,
                'c_update_user' => null
            ],
        ];
        parent::init();
    }
}
