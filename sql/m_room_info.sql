create table m_room_info
(
    i_id_room     int auto_increment
        primary key,
    c_room_name   varchar(50) null,
    i_disp_no     int         null,
    i_enable      tinyint     null,
    i_del_flg     tinyint     null,
    dt_create     datetime    null,
    c_create_user varchar(50) null,
    dt_update     datetime    null,
    c_update_user varchar(50) null
);

