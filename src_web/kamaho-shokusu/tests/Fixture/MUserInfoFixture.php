<?php
declare(strict_types=1);

namespace App\Test\Fixture;

use Cake\TestSuite\Fixture\TestFixture;

/**
 * MUserInfoFixture
 *
 * Note: このシステムでは i_enable = 0 が「有効（未無効化）」を表す。
 *       i_enable = 1 は「無効化済み」を意味するため、
 *       認証可能なテストユーザーは i_enable = 0 に統一する。
 */
class MUserInfoFixture extends TestFixture
{
    public string $table = 'm_user_info';

    public function init(): void
    {
        $this->records = [
            [
                'i_id_user'       => 1,
                'tenant_id'       => 1,
                'facility_id'     => 1,
                'c_login_account' => 'admin_user',
                'c_login_passwd'  => 'dummy_password',
                'c_user_name'     => '管理者ユーザー',
                'i_admin'         => 1,
                'i_user_level'    => 0,
                'i_disp_no'       => 1,
                'i_enable'        => 0,
                'i_del_flag'      => 0,
                'dt_create'       => '2024-07-29 09:10:37',
                'c_create_user'   => 'system',
                'dt_update'       => '2024-07-29 09:10:37',
                'c_update_user'   => 'system',
            ],
            [
                'i_id_user'       => 2,
                'tenant_id'       => 1,
                'facility_id'     => 1,
                'c_login_account' => 'staff_user',
                'c_login_passwd'  => 'dummy_password',
                'c_user_name'     => '職員ユーザー',
                'i_admin'         => 0,
                'i_user_level'    => 0,
                'i_disp_no'       => 2,
                'i_enable'        => 0,
                'i_del_flag'      => 0,
                'dt_create'       => '2024-07-29 09:10:37',
                'c_create_user'   => 'system',
                'dt_update'       => '2024-07-29 09:10:37',
                'c_update_user'   => 'system',
            ],
            [
                'i_id_user'       => 3,
                'tenant_id'       => 1,
                'facility_id'     => 1,
                'c_login_account' => 'non_staff_user',
                'c_login_passwd'  => 'dummy_password',
                'c_user_name'     => '一般ユーザー',
                'i_admin'         => 0,
                'i_user_level'    => 1,
                'i_disp_no'       => 3,
                'i_enable'        => 0,
                'i_del_flag'      => 0,
                'dt_create'       => '2024-07-29 09:10:37',
                'c_create_user'   => 'system',
                'dt_update'       => '2024-07-29 09:10:37',
                'c_update_user'   => 'system',
            ],
            [
                // system_admin は全テナント横断。tenant_id は null
                'i_id_user'       => 4,
                'tenant_id'       => null,
                'facility_id'     => null,
                'c_login_account' => 'system_admin_user',
                'c_login_passwd'  => 'dummy_password',
                'c_user_name'     => 'システム管理者',
                'i_admin'         => 3,
                'i_user_level'    => 0,
                'i_disp_no'       => 4,
                'i_enable'        => 0,
                'i_del_flag'      => 0,
                'dt_create'       => '2024-07-29 09:10:37',
                'c_create_user'   => 'system',
                'dt_update'       => '2024-07-29 09:10:37',
                'c_update_user'   => 'system',
            ],
            [
                'i_id_user'       => 5,
                'tenant_id'       => 1,
                'facility_id'     => 1,
                'c_login_account' => 'admin_user_t1',
                'c_login_passwd'  => 'dummy_password',
                'c_user_name'     => '施設管理者',
                'i_admin'         => 1,
                'i_user_level'    => 0,
                'i_disp_no'       => 5,
                'i_enable'        => 0,
                'i_del_flag'      => 0,
                'dt_create'       => '2024-07-29 09:10:37',
                'c_create_user'   => 'system',
                'dt_update'       => '2024-07-29 09:10:37',
                'c_update_user'   => 'system',
            ],
            // 削除済みユーザー（restore テスト用）
            [
                'i_id_user'       => 6,
                'tenant_id'       => 1,
                'facility_id'     => 1,
                'c_login_account' => 'deleted_user',
                'c_login_passwd'  => 'dummy_password',
                'c_user_name'     => '削除済みユーザー',
                'i_admin'         => 0,
                'i_user_level'    => 0,
                'i_disp_no'       => 6,
                'i_enable'        => 0,
                'i_del_flag'      => 1,
                'dt_create'       => '2024-07-29 09:10:37',
                'c_create_user'   => 'system',
                'dt_update'       => '2024-07-29 09:10:37',
                'c_update_user'   => 'system',
            ],
            // テナント2のユーザー（越境テスト用）
            [
                'i_id_user'       => 10,
                'tenant_id'       => 2,
                'facility_id'     => 2,
                'c_login_account' => 'admin_user_t2',
                'c_login_passwd'  => 'dummy_password',
                'c_user_name'     => 'テナント2管理者',
                'i_admin'         => 1,
                'i_user_level'    => 0,
                'i_disp_no'       => 10,
                'i_enable'        => 0,
                'i_del_flag'      => 0,
                'dt_create'       => '2024-07-29 09:10:37',
                'c_create_user'   => 'system',
                'dt_update'       => '2024-07-29 09:10:37',
                'c_update_user'   => 'system',
            ],
        ];
        parent::init();
    }
}
