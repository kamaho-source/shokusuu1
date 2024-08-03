create table t_reservation_info(
   d_reservation_date date,
   i_id_room int(11),
   c_reservation_type tinyint(4),
   i_taberu_ninzuu int(11),
   i_tabenai_ninzuu int(11),
   dt_create datetime,
   c_create_user varchar(50),
   dt_update datetime,
   c_update_user varchar(50),
    primary key (d_reservation_date, i_id_room, c_reservation_type)
)