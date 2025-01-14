<?php
declare(strict_types=1);

namespace App\Test\Fixture;

use Cake\TestSuite\Fixture\TestFixture;

/**
 * MRoomInfoFixture
 */
class MRoomInfoFixture extends TestFixture
{
    /**
     * Table name
     *
     * @var string
     */
    public string $table = 'm_room_info';
    /**
     * Init method
     *
     * @return void
     */
    public function init(): void
    {
        $this->records = [
            [
                'i_id_room' => 1,
                'c_room_name' => 'Lorem ipsum dolor sit amet',
                'i_disp_no' => 1,
                'i_enable' => 1,
                'i_del_flg' => 1,
                'dt_create' => '2024-07-29 09:11:06',
                'c_create_user' => 'Lorem ipsum dolor sit amet',
                'dt_update' => '2024-07-29 09:11:06',
                'c_update_user' => 'Lorem ipsum dolor sit amet',
            ],
        ];
        parent::init();
    }
}
