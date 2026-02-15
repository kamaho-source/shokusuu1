-- Test database schema.
--
-- If you are not using CakePHP migrations you can put
-- your application's schema in this file and use it in tests.

-- Enforce unique login IDs at DB level.
ALTER TABLE m_user_info
    ADD CONSTRAINT uq_m_user_info_login_account UNIQUE (c_login_account);

-- Add optimistic-lock version for individual reservations.
ALTER TABLE t_individual_reservation_info
    ADD COLUMN i_version INT NOT NULL DEFAULT 1;
