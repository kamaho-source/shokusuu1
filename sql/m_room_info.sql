create table m_room_info(
                            i_id_room int(11) primary key auto_increment,
                            c_room_name varchar(50),
                            i_disp_no int(11),
                            i_enable tinyint(4),
                            i_del_flg tinyint(4),
                            dt_create datetime,
                            c_create_user varchar(50),
                            dt_update datetime,
                            c_update_user varchar(50)
);