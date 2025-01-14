<?php
declare(strict_types=1);

namespace App\Test\Fixture;

use Cake\TestSuite\Fixture\TestFixture;

/**
 * MUserInfoFixture
 */
class MUserInfoFixture extends TestFixture
{
    /**
     * Table name
     *
     * @var string
     */
    public string $table = 'm_user_info';
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
                'c_login_account' => 'Lorem ipsum dolor sit amet',
                'c_login_passwd' => 'Lorem ipsum dolor sit amet',
                'c__user_name' => 'Lorem ipsum dolor sit amet',
                'i_admin' => 1,
                'i_disp__no' => 1,
                'i_enable' => 1,
                'i_del_flag' => 1,
                'dt_create' => '2024-07-29 09:10:37',
                'c_create_user' => 'Lorem ipsum dolor sit amet',
                'dt_update' => '2024-07-29 09:10:37',
                'c_update_user' => 'Lorem ipsum dolor sit amet',
            ],
        ];
        parent::init();
    }
}
