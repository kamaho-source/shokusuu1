CREATE TABLE IF NOT EXISTS m_room_transfer_schedule
(
    i_id             INT         NOT NULL AUTO_INCREMENT,
    i_id_user        INT         NOT NULL,
    i_id_room_from   INT         NULL,
    i_id_room_to     INT         NOT NULL,
    d_effective_date DATE        NOT NULL,
    i_status         TINYINT     NOT NULL DEFAULT 0 COMMENT '0=予約中, 1=適用済み, 2=キャンセル',
    c_create_user    VARCHAR(50) NULL,
    dt_create        DATETIME    NULL,
    c_update_user    VARCHAR(50) NULL,
    dt_update        DATETIME    NULL,
    PRIMARY KEY (i_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

SET @idx_user_exists := (
    SELECT COUNT(1)
    FROM information_schema.statistics
    WHERE table_schema = DATABASE()
      AND table_name = 'm_room_transfer_schedule'
      AND index_name = 'idx_rts_user_id'
);
SET @idx_user_sql := IF(
    @idx_user_exists = 0,
    'CREATE INDEX idx_rts_user_id ON m_room_transfer_schedule (i_id_user)',
    'SELECT 1'
);
PREPARE idx_user_stmt FROM @idx_user_sql;
EXECUTE idx_user_stmt;
DEALLOCATE PREPARE idx_user_stmt;

SET @idx_date_exists := (
    SELECT COUNT(1)
    FROM information_schema.statistics
    WHERE table_schema = DATABASE()
      AND table_name = 'm_room_transfer_schedule'
      AND index_name = 'idx_rts_effective_date'
);
SET @idx_date_sql := IF(
    @idx_date_exists = 0,
    'CREATE INDEX idx_rts_effective_date ON m_room_transfer_schedule (d_effective_date, i_status)',
    'SELECT 1'
);
PREPARE idx_date_stmt FROM @idx_date_sql;
EXECUTE idx_date_stmt;
DEALLOCATE PREPARE idx_date_stmt;
