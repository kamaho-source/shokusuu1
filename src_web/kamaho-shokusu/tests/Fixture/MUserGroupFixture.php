<?php
declare(strict_types=1);

namespace App\Test\Fixture;

use Cake\TestSuite\Fixture\TestFixture;

/**
 * MUserGroupFixture
 */
class MUserGroupFixture extends TestFixture
{
    /**
     * Table name
     *
     * @var string
     */
    public string $table = 'm_user_group';
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
                'i_id_room' => 1,
                'active_flag' => 0,
                'dt_create' => '2024-09-07 15:59:52',
                'c_create_user' => 'Lorem ipsum dolor sit amet',
                'dt_update' => '2024-09-07 15:59:52',
                'c_update_user' => 'Lorem ipsum dolor sit amet',
            ],
        ];
        parent::init();
    }
}
