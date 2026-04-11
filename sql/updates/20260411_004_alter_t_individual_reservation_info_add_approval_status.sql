-- ============================================================
-- t_individual_reservation_info に承認ステータスカラムを追加
--
-- i_approval_status:
--   0 = 未承認
--   1 = ブロック長承認済
--   2 = 管理者承認済（最終）
--   3 = 差し戻し
-- ============================================================
ALTER TABLE t_individual_reservation_info
    ADD COLUMN IF NOT EXISTS i_approval_status TINYINT NOT NULL DEFAULT 0
        COMMENT '0:未承認 1:ブロック長承認済 2:管理者承認済(最終) 3:差し戻し'
        AFTER i_change_flag;
