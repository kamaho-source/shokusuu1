-- 管理者ユーザーの有効フラグを修正する（i_enable: 0=有効, 1=無効）
-- 003で誤って i_enable=1（無効）に設定したため、0（有効）に戻す
UPDATE m_user_info SET i_enable = 0 WHERE i_admin = 1 AND i_enable = 1;
