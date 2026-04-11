-- 管理者ユーザー（i_admin = 1）の有効フラグを有効化する（i_enable: 0=有効, 1=無効）
UPDATE m_user_info SET i_enable = 0 WHERE i_admin = 1 AND i_enable != 0;
