-- ============================================================
-- 承認履歴テーブル
--
-- 承認・差し戻しの操作ログを保持する。
-- t_individual_reservation_info の複合主キーを外部キーとして参照。
--
-- i_approval_status:
--   1 = ブロック長承認
--   2 = 管理者承認（最終）
--   3 = 差し戻し
-- ============================================================
CREATE TABLE t_approval_log
(
    i_id_approval      INT AUTO_INCREMENT PRIMARY KEY,

    -- 対象レコードの複合キー（t_individual_reservation_info と対応）
    i_id_user          INT     NOT NULL COMMENT '対象ユーザー',
    d_reservation_date DATE    NOT NULL COMMENT '予約日',
    i_id_room          INT     NOT NULL COMMENT 'ブロック(部屋)ID',
    i_reservation_type TINYINT NOT NULL COMMENT '食種 1:朝 2:昼 3:夕 4:弁当',

    -- 承認情報
    i_approval_status  TINYINT NOT NULL COMMENT '1:ブロック長承認 2:管理者承認(最終) 3:差し戻し',
    i_approver_id      INT     NOT NULL COMMENT '承認操作を行ったユーザー(i_id_user)',
    c_reject_reason    VARCHAR(255) DEFAULT NULL COMMENT '差し戻し理由',

    -- 監査
    dt_create          DATETIME NOT NULL,

    FOREIGN KEY (i_id_user)     REFERENCES m_user_info (i_id_user),
    FOREIGN KEY (i_id_room)     REFERENCES m_room_info (i_id_room),
    FOREIGN KEY (i_approver_id) REFERENCES m_user_info (i_id_user)
);
