create table trancate_reservation_info(
  i_id_user int(11),
  d_reservation_date date,
  i_reservation_type tinyint(4),
  i_id_room int(11),
  eat_flag tinyint(4),
  dt_create datetime,
  c_create_user varchar(50),
  dt_update datetime,
  c_update_user varchar(50),
  primary key(i_id_user, d_reservation_date, i_id_room)
)