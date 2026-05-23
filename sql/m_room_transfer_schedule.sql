create table m_room_transfer_schedule
(
    i_id             int          not null auto_increment,
    i_id_user        int          not null,
    i_id_room_from   int          null,
    i_id_room_to     int          not null,
    d_effective_date date         not null,
    i_status         tinyint      not null default 0 comment '0=予約中, 1=適用済み, 2=キャンセル',
    c_create_user    varchar(50)  null,
    dt_create        datetime     null,
    c_update_user    varchar(50)  null,
    dt_update        datetime     null,
    primary key (i_id),
    constraint fk_rts_user foreign key (i_id_user) references m_user_info (i_id_user),
    constraint fk_rts_room_from foreign key (i_id_room_from) references m_room_info (i_id_room),
    constraint fk_rts_room_to foreign key (i_id_room_to) references m_room_info (i_id_room)
);

create index idx_rts_user_id on m_room_transfer_schedule (i_id_user);
create index idx_rts_effective_date on m_room_transfer_schedule (d_effective_date, i_status);
