create table t_individual_reservation_info
(
    i_id_user          int         not null,
    d_reservation_date date        not null,
    i_reservation_type tinyint     not null,
    i_id_room          int         not null,
    eat_flag           tinyint     null,
    i_change_flag      tinyint     null,
    dt_create          datetime    null,
    c_create_user      varchar(50) null,
    dt_update          datetime    null,
    c_update_user      varchar(50) null,
    primary key (i_id_user, d_reservation_date, i_id_room, i_reservation_type)
);

