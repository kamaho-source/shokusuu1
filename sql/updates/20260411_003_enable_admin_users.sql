-- 管理者ユーザー（i_admin = 1）の有効フラグを有効化する
UPDATE m_user_info SET i_enable = 1 WHERE i_admin = 1 AND i_enable = 0;
