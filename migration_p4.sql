-- ============================================================
-- migration_p4.sql — عکس پروفایل
-- ایمن و قابل اجرای دوباره.
-- ============================================================

SET @col_exists = (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'avatar'
);
SET @sql = IF(@col_exists = 0,
    'ALTER TABLE `users` ADD COLUMN `avatar` VARCHAR(120) NULL AFTER `full_name`',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SELECT 'migration_p4 با موفقیت اجرا شد.' AS result;
