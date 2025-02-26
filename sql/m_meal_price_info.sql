create table m_meal_price_info
(
    i_id            int auto_increment
        primary key,
    i_fiscal_year   int         null,
    i_morning_price int         null,
    i_lunch_price   int         null,
    i_dinner_price  int         null,
    i_bento_price   int         null,
    dt_create       datetime    null,
    c_create_user   varchar(50) null,
    dt_update       datetime    null,
    c_update_user   varchar(50) null
);

