-- ============================================================
-- migration_p3.sql — پروفایل کاربر و دستگاه‌های مورد اعتماد
-- ایمن و قابل اجرای دوباره.
-- ============================================================

-- ---------- دستگاه‌های مورد اعتماد ----------
-- توکن هرگز خام ذخیره نمی‌شود؛ فقط هش آن. حتی اگر دیتابیس لو برود،
-- کسی نمی‌تواند با محتویات این جدول وارد حساب شود.
CREATE TABLE IF NOT EXISTS `trusted_devices` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id` INT UNSIGNED NOT NULL,
    `selector` CHAR(24) NOT NULL,
    `token_hash` CHAR(64) NOT NULL,
    `device_label` VARCHAR(120) NULL,
    `last_used_at` DATETIME NULL,
    `expires_at` DATETIME NOT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uniq_selector` (`selector`),
    KEY `idx_user` (`user_id`),
    CONSTRAINT `fk_trusted_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_persian_ci;

-- ---------- تنظیمات شخصی هر کاربر ----------
-- session_hours: چند ساعت بدون فعالیت، نشست معتبر بماند
SET @col_exists = (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'session_hours'
);
SET @sql = IF(@col_exists = 0,
    'ALTER TABLE `users` ADD COLUMN `session_hours` SMALLINT UNSIGNED NOT NULL DEFAULT 1 AFTER `role`',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SELECT 'migration_p3 با موفقیت اجرا شد.' AS result;
