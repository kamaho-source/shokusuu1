<?php
declare(strict_types=1);

return [
    'm_meal_price_info' => [
        'columns' => [
            'i_id' => ['type' => 'integer', 'autoIncrement' => true, 'null' => false],
            'i_fiscal_year' => ['type' => 'integer', 'null' => true],
            'i_morning_price' => ['type' => 'integer', 'null' => true],
            'i_lunch_price' => ['type' => 'integer', 'null' => true],
            'i_dinner_price' => ['type' => 'integer', 'null' => true],
            'i_bento_price' => ['type' => 'integer', 'null' => true],
            'dt_create' => ['type' => 'datetime', 'null' => true],
            'c_create_user' => ['type' => 'string', 'length' => 50, 'null' => true],
            'dt_update' => ['type' => 'datetime', 'null' => true],
            'c_update_user' => ['type' => 'string', 'length' => 50, 'null' => true],
        ],
        'constraints' => [
            'primary' => ['type' => 'primary', 'columns' => ['i_id']],
        ],
    ],
    'm_menu_info' => [
        'columns' => [
            'id' => ['type' => 'integer', 'autoIncrement' => true, 'null' => false],
            'c_menu_name' => ['type' => 'string', 'length' => 100, 'null' => true],
            'dt_create' => ['type' => 'datetime', 'null' => true],
            'dt_update' => ['type' => 'datetime', 'null' => true],
        ],
        'constraints' => [
            'primary' => ['type' => 'primary', 'columns' => ['id']],
        ],
    ],
    'm_room_info' => [
        'columns' => [
            'i_id_room' => ['type' => 'integer', 'autoIncrement' => true, 'null' => false],
            'c_room_name' => ['type' => 'string', 'length' => 50, 'null' => true],
            'i_disp_no' => ['type' => 'integer', 'null' => true],
            'i_enable' => ['type' => 'integer', 'null' => true],
            'i_del_flg' => ['type' => 'integer', 'null' => true],
            'dt_create' => ['type' => 'datetime', 'null' => true],
            'c_create_user' => ['type' => 'string', 'length' => 50, 'null' => true],
            'dt_update' => ['type' => 'datetime', 'null' => true],
            'c_update_user' => ['type' => 'string', 'length' => 50, 'null' => true],
        ],
        'constraints' => [
            'primary' => ['type' => 'primary', 'columns' => ['i_id_room']],
        ],
    ],
    'm_user_group' => [
        'columns' => [
            'i_id_user' => ['type' => 'integer', 'null' => false],
            'i_id_room' => ['type' => 'integer', 'null' => false],
            'active_flag' => ['type' => 'integer', 'null' => true],
            'dt_create' => ['type' => 'datetime', 'null' => true],
            'c_create_user' => ['type' => 'string', 'length' => 50, 'null' => true],
            'dt_update' => ['type' => 'datetime', 'null' => true],
            'c_update_user' => ['type' => 'string', 'length' => 50, 'null' => true],
        ],
        'indexes' => [
            'idx_user_group_room_id' => ['type' => 'index', 'columns' => ['i_id_room']],
            'idx_user_group_user_id' => ['type' => 'index', 'columns' => ['i_id_user']],
        ],
        'constraints' => [
            'primary' => ['type' => 'primary', 'columns' => ['i_id_user', 'i_id_room']],
        ],
    ],
    'm_user_info' => [
        'columns' => [
            'i_id_staff' => ['type' => 'integer', 'null' => true],
            'i_id_user' => ['type' => 'integer', 'autoIncrement' => true, 'null' => false],
            'c_login_account' => ['type' => 'string', 'length' => 50, 'null' => true],
            'c_login_passwd' => ['type' => 'string', 'length' => 255, 'null' => true],
            'c_user_name' => ['type' => 'string', 'length' => 50, 'null' => true],
            'i_user_gender' => ['type' => 'integer', 'null' => true],
            'i_user_age' => ['type' => 'integer', 'null' => true],
            'i_user_level' => ['type' => 'integer', 'null' => true],
            'i_user_rank' => ['type' => 'integer', 'null' => true],
            'i_admin' => ['type' => 'integer', 'null' => true],
            'i_disp_no' => ['type' => 'integer', 'null' => true],
            'i_enable' => ['type' => 'integer', 'null' => true],
            'i_del_flag' => ['type' => 'integer', 'null' => true],
            'dt_create' => ['type' => 'datetime', 'null' => true],
            'c_create_user' => ['type' => 'string', 'length' => 50, 'null' => true],
            'dt_update' => ['type' => 'datetime', 'null' => true],
            'c_update_user' => ['type' => 'string', 'length' => 50, 'null' => true],
        ],
        'constraints' => [
            'primary' => ['type' => 'primary', 'columns' => ['i_id_user']],
            'uq_m_user_info_login_account' => ['type' => 'unique', 'columns' => ['c_login_account']],
        ],
    ],
    't_approval_log' => [
        'columns' => [
            'i_id_approval' => ['type' => 'integer', 'autoIncrement' => true, 'null' => false],
            'i_id_user' => ['type' => 'integer', 'null' => false],
            'd_reservation_date' => ['type' => 'date', 'null' => false],
            'i_id_room' => ['type' => 'integer', 'null' => false],
            'i_reservation_type' => ['type' => 'integer', 'null' => false],
            'i_approval_status' => ['type' => 'integer', 'null' => false],
            'i_approver_id' => ['type' => 'integer', 'null' => false],
            'c_reject_reason' => ['type' => 'string', 'length' => 255, 'null' => true],
            'dt_create' => ['type' => 'datetime', 'null' => false],
        ],
        'constraints' => [
            'primary' => ['type' => 'primary', 'columns' => ['i_id_approval']],
        ],
    ],
    't_individual_reservation_info' => [
        'columns' => [
            'i_id_user' => ['type' => 'integer', 'null' => false],
            'd_reservation_date' => ['type' => 'date', 'null' => false],
            'i_reservation_type' => ['type' => 'integer', 'null' => false],
            'i_id_room' => ['type' => 'integer', 'null' => false],
            'eat_flag' => ['type' => 'integer', 'null' => true],
            'i_change_flag' => ['type' => 'integer', 'null' => true],
            'i_version' => ['type' => 'integer', 'null' => false, 'default' => 1],
            'i_approval_status' => ['type' => 'integer', 'null' => true, 'default' => 0],
            'dt_create' => ['type' => 'datetime', 'null' => true],
            'c_create_user' => ['type' => 'string', 'length' => 50, 'null' => true],
            'dt_update' => ['type' => 'datetime', 'null' => true],
            'c_update_user' => ['type' => 'string', 'length' => 50, 'null' => true],
        ],
        'constraints' => [
            'primary' => [
                'type' => 'primary',
                'columns' => ['i_id_user', 'd_reservation_date', 'i_id_room', 'i_reservation_type'],
            ],
        ],
    ],
    't_notification' => [
        'columns' => [
            'i_id_notification' => ['type' => 'integer', 'autoIncrement' => true, 'null' => false],
            'i_id_user' => ['type' => 'integer', 'null' => false],
            'c_notification_type' => ['type' => 'string', 'length' => 50, 'null' => false],
            'c_title' => ['type' => 'string', 'length' => 100, 'null' => false],
            'c_message' => ['type' => 'string', 'length' => 255, 'null' => false],
            'c_link' => ['type' => 'string', 'length' => 255, 'null' => true],
            'i_is_read' => ['type' => 'integer', 'null' => false, 'default' => 0],
            'dt_read' => ['type' => 'datetime', 'null' => true],
            'dt_create' => ['type' => 'datetime', 'null' => false],
        ],
        'constraints' => [
            'primary' => ['type' => 'primary', 'columns' => ['i_id_notification']],
        ],
    ],
    't_reservation_info' => [
        'columns' => [
            'd_reservation_date' => ['type' => 'date', 'null' => false],
            'i_id_room' => ['type' => 'integer', 'null' => false],
            'c_reservation_type' => ['type' => 'integer', 'null' => false],
            'i_taberu_ninzuu' => ['type' => 'integer', 'null' => true],
            'i_tabenai_ninzuu' => ['type' => 'integer', 'null' => true],
            'dt_create' => ['type' => 'datetime', 'null' => true],
            'c_create_user' => ['type' => 'string', 'length' => 50, 'null' => true],
            'dt_update' => ['type' => 'datetime', 'null' => true],
            'c_update_user' => ['type' => 'string', 'length' => 50, 'null' => true],
        ],
        'constraints' => [
            'primary' => [
                'type' => 'primary',
                'columns' => ['d_reservation_date', 'i_id_room', 'c_reservation_type'],
            ],
        ],
    ],
];
