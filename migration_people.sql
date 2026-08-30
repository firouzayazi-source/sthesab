-- ============================================================
-- migration_people.sql — فهرست اشخاص
--
-- تا پیش از این، نام طرف مقابل در چک و طلب و بدهی هر بار دستی تایپ
-- می‌شد. نتیجه‌اش هم غلط تایپی بود («علی رضایی» و «علي رضایی» دو نفر
-- می‌شدند) و هم کار تکراری.
--
-- ⚠ ستون `counterparty_name` عمداً دست‌نخورده می‌ماند و همچنان نامِ
-- ذخیره‌شده است. این جدول فقط یک فهرستِ کمکی برای پر کردنِ همان ستون
-- است، نه جایگزینش. دلیلش:
--   • رکوردهای قدیمی بدون هیچ کاری کار می‌کنند
--   • همه‌ی صفحه‌های نمایش (داشبورد، جستجو، چک‌ها، …) دست نمی‌خورند
--   • حذف یک شخص از فهرست، تاریخچه را خراب نمی‌کند
--
-- `role` عمداً ENUM نیست: کاربر باید بتواند سمتِ دلخواه بنویسد، مثل
-- همان کاری که برای «نوع حساب» شد.
--
-- ایمن برای اجرای دوباره.
-- ============================================================

CREATE TABLE IF NOT EXISTS `people` (
    `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id`    INT UNSIGNED NOT NULL,
    `name`       VARCHAR(150) NOT NULL,
    `role`       VARCHAR(60)  NOT NULL DEFAULT 'سایر' COMMENT 'همکار / مشتری / سایر یا هر چیزی که کاربر بنویسد',
    `note`       VARCHAR(255) NULL,
    `created_at` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_user_name` (`user_id`, `name`),
    KEY `idx_user_role` (`user_id`, `role`),
    CONSTRAINT `fk_people_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_persian_ci;

-- ---------- طرف مقابل برای معاملات ----------
-- خرید از چه کسی، فروش به چه کسی. مثل چک و طلب، نام ذخیره می‌شود نه
-- شناسه — به همان دلیل‌های بالا.
SET @has = (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'trades' AND COLUMN_NAME = 'counterparty_name'
);
SET @sql = IF(@has = 0,
    'ALTER TABLE `trades` ADD COLUMN `counterparty_name` VARCHAR(150) NULL AFTER `title`',
    'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @has = (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'trade_sales' AND COLUMN_NAME = 'counterparty_name'
);
SET @sql = IF(@has = 0,
    'ALTER TABLE `trade_sales` ADD COLUMN `counterparty_name` VARCHAR(150) NULL AFTER `sale_date`',
    'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SELECT 'migration_people با موفقیت اجرا شد.' AS result;
