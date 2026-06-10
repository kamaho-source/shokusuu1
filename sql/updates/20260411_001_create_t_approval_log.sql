CREATE TABLE IF NOT EXISTS `t_approval_log` (
  `i_id_approval` int NOT NULL AUTO_INCREMENT,
  `i_id_user` int NOT NULL COMMENT '対象ユーザー',
  `d_reservation_date` date NOT NULL COMMENT '予約日',
  `i_id_room` int NOT NULL COMMENT 'ブロック(部屋)ID',
  `i_reservation_type` tinyint NOT NULL COMMENT '食種 1:朝 2:昼 3:夕 4:弁当',
  `i_approval_status` tinyint NOT NULL COMMENT '1:ブロック長承認 2:管理者承認(最終) 3:差し戻し',
  `i_approver_id` int NOT NULL COMMENT '承認操作を行ったユーザー(i_id_user)',
  `c_reject_reason` varchar(255) DEFAULT NULL COMMENT '差し戻し理由',
  `dt_create` datetime NOT NULL,
  PRIMARY KEY (`i_id_approval`),
  KEY `i_id_user` (`i_id_user`),
  KEY `i_id_room` (`i_id_room`),
  KEY `i_approver_id` (`i_approver_id`),
  CONSTRAINT `t_approval_log_ibfk_1` FOREIGN KEY (`i_id_user`) REFERENCES `m_user_info` (`i_id_user`),
  CONSTRAINT `t_approval_log_ibfk_2` FOREIGN KEY (`i_id_room`) REFERENCES `m_room_info` (`i_id_room`),
  CONSTRAINT `t_approval_log_ibfk_3` FOREIGN KEY (`i_approver_id`) REFERENCES `m_user_info` (`i_id_user`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
