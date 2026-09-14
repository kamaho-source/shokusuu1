-- ============================================================
-- t_individual_reservation_info に (i_id_room, d_reservation_date) の
-- 複合インデックスを追加する。
--
-- 主キーは (i_id_user, d_reservation_date, i_id_room, i_reservation_type) のため、
-- 部屋＋日付を先頭条件にする以下のクエリが全表スキャンになっていた。
--   - ReservationQueryService::getUsersByRoomForBulk のスナップショット MAX 取得
--   - ReservationQueryService::getReservationSnapshots
-- 一括予約画面は表示・曜日切替のたびにこれらを実行するため、行数増加に比例して遅くなる。
-- ============================================================

SET @idx_room_date_exists := (
    SELECT COUNT(1)
    FROM information_schema.statistics
    WHERE table_schema = DATABASE()
      AND table_name = 't_individual_reservation_info'
      AND index_name = 'idx_tiri_room_date'
);
SET @idx_room_date_sql := IF(
    @idx_room_date_exists = 0,
    'CREATE INDEX idx_tiri_room_date ON t_individual_reservation_info (i_id_room, d_reservation_date)',
    'SELECT 1'
);
PREPARE idx_room_date_stmt FROM @idx_room_date_sql;
EXECUTE idx_room_date_stmt;
DEALLOCATE PREPARE idx_room_date_stmt;
