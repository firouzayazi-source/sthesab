-- ============================================================
-- migration_password_reset.sql — بازیابی رمز با ایمیل
--
-- تا پیش از این هیچ راهی برای بازیابی نبود: کاربری که رمزش را فراموش
-- می‌کرد، باید مدیر رمزش را عوض می‌کرد؛ و اگر خود مدیر فراموش می‌کرد،
-- هیچ‌کس نمی‌توانست کاری بکند.
--
-- الگوی توکن: selector + validator (همان الگویی که trusted_devices
-- استفاده می‌کند). selector برای پیدا کردن سطر است و validator فقط
-- به‌صورت هش ذخیره می‌شود — پس حتی با دسترسی به دیتابیس هم نمی‌شود
-- لینک بازیابی معتبر ساخت.
--
-- ایمن برای اجرای دوباره.
-- ============================================================

-- ---------- ۱. ستون ایمیل روی users ----------
SET @has_email = (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'email'
);
SET @sql = IF(@has_email = 0,
    'ALTER TABLE `users` ADD COLUMN `email` VARCHAR(190) NULL AFTER `username`',
    'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ایندکس یکتا روی ایمیل. در MySQL/MariaDB چند NULL در ستون یکتا مجاز
-- است، پس کاربرانی که ایمیل ندارند مشکلی ایجاد نمی‌کنند.
SET @has_idx = (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND INDEX_NAME = 'uq_email'
);
SET @sql = IF(@has_idx = 0,
    'ALTER TABLE `users` ADD UNIQUE KEY `uq_email` (`email`)',
    'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ---------- ۲. جدول توکن‌های بازیابی ----------
CREATE TABLE IF NOT EXISTS `password_resets` (
    `id`             INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id`        INT UNSIGNED NOT NULL,
    `selector`       CHAR(24)     NOT NULL COMMENT 'برای پیدا کردن سطر؛ در لینک می‌آید',
    `validator_hash` CHAR(64)     NOT NULL COMMENT 'هش sha256 از بخش مخفی لینک',
    `expires_at`     DATETIME     NOT NULL,
    `used_at`        DATETIME     NULL     COMMENT 'هر توکن فقط یک بار',
    `request_ip`     VARCHAR(45)  NULL,
    `created_at`     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_selector` (`selector`),
    KEY `idx_user_created` (`user_id`, `created_at`),
    CONSTRAINT `fk_reset_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_persian_ci;

SELECT 'migration_password_reset با موفقیت اجرا شد.' AS result;
