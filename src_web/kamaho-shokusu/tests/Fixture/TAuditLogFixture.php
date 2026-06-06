<?php
declare(strict_types=1);

namespace App\Test\Fixture;

use Cake\TestSuite\Fixture\TestFixture;

class TAuditLogFixture extends TestFixture
{
    public string $table = 't_audit_log';

    public function init(): void
    {
        $this->records = [
            [
                'c_category'        => 'user',
                'c_action'          => 'user_login',
                'c_target_table'    => 'm_user_info',
                'c_target_id'       => '1',
                'i_actor_user_id'   => 1,
                'c_actor_user_name' => 'admin_user',
                'c_ip_address'      => '127.0.0.1',
                'i_result'          => true,
                'c_detail'          => null,
                'dt_create'         => '2026-06-01 10:00:00',
            ],
            [
                'c_category'        => 'user',
                'c_action'          => 'user_login_failed',
                'c_target_table'    => 'm_user_info',
                'c_target_id'       => null,
                'i_actor_user_id'   => null,
                'c_actor_user_name' => 'unknown',
                'c_ip_address'      => '192.168.1.100',
                'i_result'          => false,
                'c_detail'          => '{"login_account":"unknown"}',
                'dt_create'         => '2026-06-01 11:00:00',
            ],
            [
                'c_category'        => 'approval',
                'c_action'          => 'approval_block_leader',
                'c_target_table'    => 't_individual_reservation_info',
                'c_target_id'       => '1:2026-06-01',
                'i_actor_user_id'   => 2,
                'c_actor_user_name' => 'block_leader',
                'c_ip_address'      => '10.0.0.1',
                'i_result'          => true,
                'c_detail'          => '{"count":1}',
                'dt_create'         => '2026-06-01 12:00:00',
            ],
        ];
        parent::init();
    }
}
