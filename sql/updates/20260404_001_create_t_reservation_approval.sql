CREATE TABLE IF NOT EXISTS t_reservation_approval (
    i_id_user INT NOT NULL,
    d_reservation_date DATE NOT NULL,
    i_id_room INT NOT NULL,
    i_reservation_type TINYINT NOT NULL,
    i_requested_flag TINYINT NOT NULL,
    i_status TINYINT NOT NULL DEFAULT 0,
    c_reason VARCHAR(255) NULL,
    i_reviewer_user INT NULL,
    dt_reviewed DATETIME NULL,
    dt_create DATETIME NULL,
    c_create_user VARCHAR(50) NULL,
    dt_update DATETIME NULL,
    c_update_user VARCHAR(50) NULL,
    PRIMARY KEY (i_id_user, d_reservation_date, i_id_room, i_reservation_type)
);

SET @idx_status_exists := (
    SELECT COUNT(1)
    FROM information_schema.statistics
    WHERE table_schema = DATABASE()
      AND table_name = 't_reservation_approval'
      AND index_name = 'idx_reservation_approval_status_date'
);
SET @idx_status_sql := IF(
    @idx_status_exists = 0,
    'CREATE INDEX idx_reservation_approval_status_date ON t_reservation_approval (i_status, d_reservation_date)',
    'SELECT 1'
);
PREPARE idx_status_stmt FROM @idx_status_sql;
EXECUTE idx_status_stmt;
DEALLOCATE PREPARE idx_status_stmt;
