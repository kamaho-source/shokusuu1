create table m_user_info
(
    i_id_staff      int          null,
    i_id_user       int auto_increment
        primary key,
    c_login_account varchar(50)  null,
    c_login_passwd  varchar(255) null,
    c_user_name     varchar(50)  null,
    i_user_gender   int          null,
    i_user_age      int          null,
    i_user_level    tinyint      null,
    i_user_rank     int          null,
    i_admin         tinyint      null,
    i_disp_no       int          null,
    i_enable        tinyint      null,
    i_del_flag      tinyint      null,
    dt_create       datetime     null,
    c_create_user   varchar(50)  null,
    dt_update       datetime     null,
    c_update_user   varchar(50)  null
);

