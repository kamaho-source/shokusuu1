-- ============================================================
-- アプリ内通知テーブル
--
-- 差し戻しなどのユーザー向け通知を保持する。
-- ============================================================
CREATE TABLE IF NOT EXISTS t_notification
(
    i_id_notification   INT AUTO_INCREMENT PRIMARY KEY,
    i_id_user           INT          NOT NULL COMMENT '通知先ユーザー',
    c_notification_type VARCHAR(50)  NOT NULL COMMENT '通知種別',
    c_title             VARCHAR(100) NOT NULL COMMENT '通知タイトル',
    c_message           VARCHAR(255) NOT NULL COMMENT '通知本文',
    c_link              VARCHAR(255) DEFAULT NULL COMMENT '遷移先',
    i_is_read           TINYINT      NOT NULL DEFAULT 0 COMMENT '0:未読 1:既読',
    dt_read             DATETIME     DEFAULT NULL COMMENT '既読日時',
    dt_create           DATETIME     NOT NULL COMMENT '作成日時',

    KEY idx_notification_user_read_created (i_id_user, i_is_read, dt_create),
    CONSTRAINT fk_notification_user
        FOREIGN KEY (i_id_user) REFERENCES m_user_info (i_id_user)
);
