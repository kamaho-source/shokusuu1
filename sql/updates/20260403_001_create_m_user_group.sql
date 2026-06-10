CREATE TABLE IF NOT EXISTS m_user_group (
    i_id_user INT NOT NULL,
    i_id_room INT NOT NULL,
    active_flag TINYINT NULL,
    dt_create DATETIME NULL,
    c_create_user VARCHAR(50) NULL,
    dt_update DATETIME NULL,
    c_update_user VARCHAR(50) NULL,
    PRIMARY KEY (i_id_user, i_id_room)
);

SET @idx_room_exists := (
    SELECT COUNT(1)
    FROM information_schema.statistics
    WHERE table_schema = DATABASE()
      AND table_name = 'm_user_group'
      AND index_name = 'idx_user_group_room_id'
);
SET @idx_room_sql := IF(
    @idx_room_exists = 0,
    'CREATE INDEX idx_user_group_room_id ON m_user_group (i_id_room)',
    'SELECT 1'
);
PREPARE idx_room_stmt FROM @idx_room_sql;
EXECUTE idx_room_stmt;
DEALLOCATE PREPARE idx_room_stmt;

SET @idx_user_exists := (
    SELECT COUNT(1)
    FROM information_schema.statistics
    WHERE table_schema = DATABASE()
      AND table_name = 'm_user_group'
      AND index_name = 'idx_user_group_user_id'
);
SET @idx_user_sql := IF(
    @idx_user_exists = 0,
    'CREATE INDEX idx_user_group_user_id ON m_user_group (i_id_user)',
    'SELECT 1'
);
PREPARE idx_user_stmt FROM @idx_user_sql;
EXECUTE idx_user_stmt;
DEALLOCATE PREPARE idx_user_stmt;
