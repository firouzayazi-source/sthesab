-- ============================================================
-- migration_trades_default.sql — بخش معاملات پیش‌فرض روشن
--
-- `migration_trades.sql` ستون را با `DEFAULT 0` ساخت و هر کاربر
-- باید خودش از پروفایل روشنش می‌کرد. خواسته‌ی مالکِ نصب برعکسش شد:
-- «بخش معاملات پیش‌فرض فعال باشه».
--
-- دو کارِ جدا، و هر دو لازم:
--   ۱. پیش‌فرضِ **ستون** ۱ می‌شود — `createUserAccount()` این ستون را
--      اصلاً در `INSERT` نمی‌آورد، پس تنها مرجعِ «کاربرِ تازه» همین
--      پیش‌فرض است و فهرستِ دومی در کد ساخته نمی‌شود.
--   ۲. کاربرانِ **موجود** یک بار روشن می‌شوند — وگرنه مالکِ نصب
--      به‌روزرسانی می‌کرد و برای خودش هیچ اتفاقی نمی‌افتاد، یعنی
--      «تنظیمی که کار نمی‌کند».
--
-- ⛔ و نشانه‌ی `trades_default_on` دقیقاً همان کاری را می‌کند که
--    `more_categories_seeded` می‌کند: از **اجرای دوم** به بعد این فایل
--    به هیچ ردیفی دست نمی‌زند. بدونِ آن، هر deploy کلیدی را که کاربر
--    عمداً خاموش کرده دوباره روشن می‌کرد — بی‌هیچ خطایی.
--
-- ⚠ هزینه‌ی صادقانه‌ی همان یک بار: ستون `NOT NULL DEFAULT 0` است، پس
--   «هرگز تصمیم نگرفته» از «عمداً خاموش گذاشته» قابل تفکیک نیست و آن
--   یک اجرا هر دو را روشن می‌کند. پذیرفته شد چون اثرش برگشت‌پذیر و
--   بی‌خطر است: فقط یک قلمِ منو ظاهر می‌شود و هیچ داده‌ای عوض نمی‌شود.
--   از آن به بعد انتخابِ کاربر برای همیشه محترم است.
--
-- ایمن و قابل اجرای دوباره.
-- ============================================================

SET @col_exists = (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'trades_enabled'
);

-- ۱) کاربرِ تازه
SET @sql = IF(@col_exists = 1,
    'ALTER TABLE `users` ALTER COLUMN `trades_enabled` SET DEFAULT 1',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ۲) کاربرانِ موجود — فقط یک بار
-- ⚠ مقایسه‌ی `setting_key` با یک **رشته‌ی ثابت** است نه با متغیرِ
--   کاربری: رشته‌ی ثابت قابلیتِ تبدیلِ ۴ دارد و به collation ستون
--   تبدیل می‌شود، پس «Illegal mix of collations» ممکن نیست.
SET @already_on = (
    SELECT COUNT(*) FROM `app_settings` WHERE `setting_key` = 'trades_default_on'
);
SET @sql = IF(@col_exists = 1 AND @already_on = 0,
    'UPDATE `users` SET `trades_enabled` = 1 WHERE `trades_enabled` = 0',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- نشانه: از این به بعد این فایل به هیچ کاربری دست نمی‌زند.
INSERT INTO `app_settings` (`setting_key`, `setting_value`)
VALUES ('trades_default_on', '1')
ON DUPLICATE KEY UPDATE `setting_value` = '1';

SELECT 'migration_trades_default با موفقیت اجرا شد.' AS result;
