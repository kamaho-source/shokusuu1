-- フィードバック・お問い合わせテーブル作成
CREATE TABLE IF NOT EXISTS `t_contacts` (
    `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT 'ID',
    `category`   VARCHAR(50)  NOT NULL COMMENT 'カテゴリ（ご意見・不具合報告・使い方の質問・その他）',
    `name`       VARCHAR(100) NOT NULL COMMENT '送信者名',
    `email`      VARCHAR(255) NOT NULL COMMENT 'メールアドレス',
    `body`       TEXT         NOT NULL COMMENT '内容',
    `user_id`    INT UNSIGNED NULL     COMMENT 'ログインユーザーID（未ログイン時はNULL）',
    `created`    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `modified`   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='フィードバック・お問い合わせ';
