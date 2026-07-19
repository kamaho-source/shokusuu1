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
                'i_id_room'    => 1,
                'tenant_id'    => 1,
                'facility_id'  => 1,
                'c_room_name'  => 'テナント1の部屋',
                'i_disp_no'    => 1,
                'i_enable'     => 1,
                'i_del_flg'    => 0,
                'dt_create'    => '2024-07-29 09:11:06',
                'c_create_user'=> 'system',
                'dt_update'    => '2024-07-29 09:11:06',
                'c_update_user'=> 'system',
            ],
            // テナント2の部屋（越境テスト用）
            [
                'i_id_room'    => 2,
                'tenant_id'    => 2,
                'facility_id'  => 2,
                'c_room_name'  => 'テナント2の部屋',
                'i_disp_no'    => 1,
                'i_enable'     => 1,
                'i_del_flg'    => 0,
                'dt_create'    => '2024-07-29 09:11:06',
                'c_create_user'=> 'system',
                'dt_update'    => '2024-07-29 09:11:06',
                'c_update_user'=> 'system',
            ],
        ];
        parent::init();
    }
}
