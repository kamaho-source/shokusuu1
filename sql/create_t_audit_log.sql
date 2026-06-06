-- ============================================================
-- 監査ログテーブル
--
-- システム内の重要操作を記録する。
--
-- c_category:
--   user        = ユーザー管理
--   reservation = 予約操作
--   actual_meal = 実食入力
--   approval    = 承認フロー
--   master      = マスタデータ管理
--   system      = システム操作（エクスポートなど）
--
-- i_result:
--   1 = 成功
--   0 = 失敗
-- ============================================================
CREATE TABLE t_audit_log
(
    i_id_audit        BIGINT AUTO_INCREMENT PRIMARY KEY,
    c_category        VARCHAR(50)   NOT NULL                 COMMENT 'カテゴリ',
    c_action          VARCHAR(100)  NOT NULL                 COMMENT '操作種別',
    c_target_table    VARCHAR(100)  DEFAULT NULL             COMMENT '対象テーブル',
    c_target_id       VARCHAR(255)  DEFAULT NULL             COMMENT '対象レコードID',
    i_actor_user_id   INT           DEFAULT NULL             COMMENT '操作者ユーザーID',
    c_actor_user_name VARCHAR(50)   NOT NULL                 COMMENT '操作者ユーザー名',
    c_ip_address      VARCHAR(45)   DEFAULT NULL             COMMENT '操作元IPアドレス',
    i_result          TINYINT       NOT NULL DEFAULT 1       COMMENT '1:成功 0:失敗',
    c_detail          TEXT          DEFAULT NULL             COMMENT '操作詳細（JSON）',
    dt_create         DATETIME      NOT NULL                 COMMENT '操作日時',

    INDEX idx_category     (c_category),
    INDEX idx_action       (c_action),
    INDEX idx_actor        (i_actor_user_id),
    INDEX idx_dt_create    (dt_create),
    INDEX idx_target       (c_target_table, c_target_id(50)),

    FOREIGN KEY (i_actor_user_id) REFERENCES m_user_info (i_id_user)
        ON DELETE SET NULL
);
