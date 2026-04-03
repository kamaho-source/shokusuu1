CREATE TABLE IF NOT EXISTS t_individual_reservation_info (
    i_id_user INT NOT NULL,
    d_reservation_date DATE NOT NULL,
    i_reservation_type TINYINT NOT NULL,
    i_id_room INT NOT NULL,
    eat_flag TINYINT NULL,
    i_change_flag TINYINT NULL,
    i_version INT NOT NULL DEFAULT 1,
    dt_create DATETIME NULL,
    c_create_user VARCHAR(50) NULL,
    dt_update DATETIME NULL,
    c_update_user VARCHAR(50) NULL,
    PRIMARY KEY (i_id_user, d_reservation_date, i_id_room, i_reservation_type)
);

SET @has_i_change_flag := (
    SELECT COUNT(1)
    FROM information_schema.columns
    WHERE table_schema = DATABASE()
      AND table_name = 't_individual_reservation_info'
      AND column_name = 'i_change_flag'
);
SET @sql_i_change_flag := IF(
    @has_i_change_flag = 0,
    'ALTER TABLE t_individual_reservation_info ADD COLUMN i_change_flag TINYINT NULL AFTER eat_flag',
    'SELECT 1'
);
PREPARE stmt_i_change_flag FROM @sql_i_change_flag;
EXECUTE stmt_i_change_flag;
DEALLOCATE PREPARE stmt_i_change_flag;

SET @has_i_version := (
    SELECT COUNT(1)
    FROM information_schema.columns
    WHERE table_schema = DATABASE()
      AND table_name = 't_individual_reservation_info'
      AND column_name = 'i_version'
);
SET @sql_i_version := IF(
    @has_i_version = 0,
    'ALTER TABLE t_individual_reservation_info ADD COLUMN i_version INT NOT NULL DEFAULT 1 AFTER i_change_flag',
    'SELECT 1'
);
PREPARE stmt_i_version FROM @sql_i_version;
EXECUTE stmt_i_version;
DEALLOCATE PREPARE stmt_i_version;

SET @pk_exists := (
    SELECT COUNT(1)
    FROM information_schema.table_constraints
    WHERE table_schema = DATABASE()
      AND table_name = 't_individual_reservation_info'
      AND constraint_type = 'PRIMARY KEY'
);
SET @sql_pk := IF(
    @pk_exists = 0,
    'ALTER TABLE t_individual_reservation_info ADD PRIMARY KEY (i_id_user, d_reservation_date, i_id_room, i_reservation_type)',
    'SELECT 1'
);
PREPARE stmt_pk FROM @sql_pk;
EXECUTE stmt_pk;
DEALLOCATE PREPARE stmt_pk;
