-- ============================================================
-- t_individual_reservation_info に承認ステータスカラムを追加
--
-- i_approval_status:
--   0 = 未承認
--   1 = ブロック長承認済
--   2 = 管理者承認済（最終）
--   3 = 差し戻し
-- ============================================================
SET @sql = (SELECT IF(
    (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME   = 't_individual_reservation_info'
       AND COLUMN_NAME  = 'i_approval_status') = 0,
    'ALTER TABLE t_individual_reservation_info ADD COLUMN i_approval_status TINYINT NOT NULL DEFAULT 0 COMMENT ''0:未承認 1:ブロック長承認済 2:管理者承認済(最終) 3:差し戻し'' AFTER i_change_flag',
    'SELECT 1'
));
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
