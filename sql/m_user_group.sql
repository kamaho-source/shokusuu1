create table m_user_group
(
    i_id_user     int         not null,
    i_id_room     int         not null,
    active_flag   tinyint     null,
    dt_create     datetime    null,
    c_create_user varchar(50) null,
    dt_update     datetime    null,
    c_update_user varchar(50) null,
    primary key (i_id_user, i_id_room)
);

create index idx_user_group_room_id
    on m_user_group (i_id_room);

create index idx_user_group_user_id
    on m_user_group (i_id_user);

