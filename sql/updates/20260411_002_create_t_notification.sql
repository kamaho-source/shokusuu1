CREATE TABLE IF NOT EXISTS `t_notification` (
  `i_id_notification` int NOT NULL AUTO_INCREMENT,
  `i_id_user` int NOT NULL COMMENT '通知先ユーザー',
  `c_notification_type` varchar(50) NOT NULL COMMENT '通知種別',
  `c_title` varchar(100) NOT NULL COMMENT '通知タイトル',
  `c_message` varchar(255) NOT NULL COMMENT '通知本文',
  `c_link` varchar(255) DEFAULT NULL COMMENT '遷移先',
  `i_is_read` tinyint NOT NULL DEFAULT '0' COMMENT '0:未読 1:既読',
  `dt_read` datetime DEFAULT NULL COMMENT '既読日時',
  `dt_create` datetime NOT NULL COMMENT '作成日時',
  PRIMARY KEY (`i_id_notification`),
  KEY `idx_notification_user_read_created` (`i_id_user`,`i_is_read`,`dt_create`),
  CONSTRAINT `fk_notification_user` FOREIGN KEY (`i_id_user`) REFERENCES `m_user_info` (`i_id_user`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
