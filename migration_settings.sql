-- ============================================
-- افزودن جدول تنظیمات عمومی برنامه
-- این فایل را در phpMyAdmin روی دیتابیس فعلی Import کنید
-- ============================================

CREATE TABLE IF NOT EXISTS `app_settings` (
    `setting_key` VARCHAR(50) NOT NULL,
    `setting_value` VARCHAR(255) NOT NULL,
    PRIMARY KEY (`setting_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_persian_ci;

INSERT IGNORE INTO `app_settings` (`setting_key`, `setting_value`) VALUES ('require_full_login', '0');
