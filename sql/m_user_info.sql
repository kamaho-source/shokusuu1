create table m_user_info(
                            i_id_user int(11) primary key auto_increment,
                            c_login_account varchar(50)  ,
                            c_login_passwd varchar(255) ,
                            c__user_name varchar(50) ,
                            i_admin tinyint(4) ,
                            i_disp__no int(11) ,
                            i_enable tinyint(4) ,
                            i_del_flag tinyint(4) ,
                            dt_create datetime,
                            c_create_user varchar(50),
                            dt_update datetime,
                            c_update_user varchar(50)
);