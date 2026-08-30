-- ============================================================
-- migration_user_categories.sql — دسته‌بندی شخصیِ هر کاربر
--
-- تا حالا جدول `categories` اصلاً `user_id` نداشت: یک فهرست مشترک بین
-- همه‌ی کاربران که فقط مدیر می‌توانست عوضش کند. یعنی کاربر عادی نه
-- می‌توانست «شهریه‌ی مدرسه» را به هزینه‌ها اضافه کند و نه چیزی که به
-- کارش نمی‌آمد را کنار بگذارد.
--
-- حالا:
--   user_id IS NULL   → دسته‌ی پیش‌فرضِ برنامه. همه می‌بینندش و فقط
--                       مدیر از پنل خودش تغییرش می‌دهد.
--   user_id = X       → دسته‌ی شخصیِ همان کاربر. فقط خودش می‌بیند،
--                       اضافه می‌کند و حذف می‌کند.
--
-- ردیف‌های موجود همه NULL می‌شوند، یعنی دقیقاً همان رفتار قبلی: همه‌ی
-- دسته‌های امروزی برای همه می‌مانند و هیچ تراکنشی دسته‌اش را از دست
-- نمی‌دهد.
--
-- ایمن و قابل اجرای دوباره.
-- ============================================================

SET @c = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'categories' AND COLUMN_NAME = 'user_id');
SET @s = IF(@c = 0,
    'ALTER TABLE `categories` ADD COLUMN `user_id` INT UNSIGNED NULL AFTER `id`, ADD KEY `idx_user_type` (`user_id`, `type`)',
    'SELECT 1');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- حذف کاربر، دسته‌های شخصی‌اش را هم می‌برد. دسته‌های پیش‌فرض (NULL)
-- دست‌نخورده می‌مانند چون به هیچ کاربری وصل نیستند.
SET @c = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLE_CONSTRAINTS
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'categories' AND CONSTRAINT_NAME = 'fk_categories_user');
SET @s = IF(@c = 0,
    'ALTER TABLE `categories` ADD CONSTRAINT `fk_categories_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE',
    'SELECT 1');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SELECT 'migration_user_categories با موفقیت اجرا شد.' AS result;
