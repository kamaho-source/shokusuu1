create table m_user_group(
    i_id_user int(11) ,
    i_id_room int(11),
    active_flag tinyint(4),
    dt_create datetime,
    c_create_user varchar(50),
    dt_update datetime,
    c_update_user varchar(50),
    primary key(i_id_user, i_id_room)
);