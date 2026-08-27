-- ============================================
-- افزودن آیکن و رنگ به دسته‌بندی‌ها
-- این فایل را در phpMyAdmin روی دیتابیس فعلی Import کنید
--
-- توجه: در ستون icon فقط یک «کد انگلیسی» ذخیره می‌شود (مثل food یا transport)
-- و خود ایموجی در کد PHP نمایش داده می‌شود. به این ترتیب هیچ‌وقت مشکل
-- کاراکترست و تبدیل شدن ایموجی به ???? پیش نمی‌آید.
-- ============================================

-- قابل اجرای دوباره: اگر ستون‌ها از قبل باشند، چیزی تغییر نمی‌کند.
SET @has_icon = (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'categories' AND COLUMN_NAME = 'icon'
);
SET @fresh = IF(@has_icon = 0, 1, 0);

SET @sql = IF(@has_icon = 0,
    'ALTER TABLE `categories` ADD COLUMN `icon` VARCHAR(24) NOT NULL DEFAULT ''default'' AFTER `type`',
    'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @has_color = (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'categories' AND COLUMN_NAME = 'color'
);
SET @sql = IF(@has_color = 0,
    'ALTER TABLE `categories` ADD COLUMN `color` VARCHAR(7) NOT NULL DEFAULT ''#64748b'' AFTER `icon`',
    'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- UPDATE های زیر فقط در اولین اجرا کار می‌کنند (@fresh = 1).
-- وگرنه آیکون‌هایی که کاربر خودش از پنل عوض کرده بازنویسی می‌شدند.

-- ---------- هزینه‌ها ----------
UPDATE `categories` SET `icon` = 'food',      `color` = '#f97316' WHERE (`name` LIKE '%غذا%' OR `name` LIKE '%خورا%' OR `name` LIKE '%رستوران%' OR `name` LIKE '%میوه%') AND @fresh = 1;
UPDATE `categories` SET `icon` = 'transport', `color` = '#0ea5e9' WHERE (`name` LIKE '%حمل%' OR `name` LIKE '%نقل%' OR `name` LIKE '%تاکسی%' OR `name` LIKE '%بنزین%' OR `name` LIKE '%خودرو%' OR `name` LIKE '%ماشین%') AND @fresh = 1;
UPDATE `categories` SET `icon` = 'shopping',  `color` = '#8b5cf6' WHERE (`name` LIKE '%خرید%' OR `name` LIKE '%فروشگاه%' OR `name` LIKE '%سوپر%') AND @fresh = 1;
UPDATE `categories` SET `icon` = 'home',      `color` = '#ef4444' WHERE (`name` LIKE '%اجاره%' OR `name` LIKE '%مسکن%' OR `name` LIKE '%خونه%' OR `name` LIKE '%خانه%' OR `name` LIKE '%رهن%') AND @fresh = 1;
UPDATE `categories` SET `icon` = 'health',    `color` = '#ec4899' WHERE (`name` LIKE '%درمان%' OR `name` LIKE '%پزشک%' OR `name` LIKE '%دارو%' OR `name` LIKE '%سلامت%' OR `name` LIKE '%بهداشت%') AND @fresh = 1;
UPDATE `categories` SET `icon` = 'bill',      `color` = '#eab308' WHERE (`name` LIKE '%قبض%' OR `name` LIKE '%برق%' OR `name` LIKE '%آب%' OR `name` LIKE '%گاز%' OR `name` LIKE '%شارژ%') AND @fresh = 1;
UPDATE `categories` SET `icon` = 'phone',     `color` = '#06b6d4' WHERE (`name` LIKE '%موبایل%' OR `name` LIKE '%اینترنت%' OR `name` LIKE '%تلفن%' OR `name` LIKE '%گوشی%') AND @fresh = 1;
UPDATE `categories` SET `icon` = 'education', `color` = '#6366f1' WHERE (`name` LIKE '%آموزش%' OR `name` LIKE '%تحصیل%' OR `name` LIKE '%کتاب%' OR `name` LIKE '%کلاس%') AND @fresh = 1;
UPDATE `categories` SET `icon` = 'fun',       `color` = '#d946ef' WHERE (`name` LIKE '%تفریح%' OR `name` LIKE '%سفر%' OR `name` LIKE '%مسافرت%') AND @fresh = 1;
UPDATE `categories` SET `icon` = 'clothes',   `color` = '#f43f5e' WHERE (`name` LIKE '%پوشاک%' OR `name` LIKE '%لباس%') AND @fresh = 1;
UPDATE `categories` SET `icon` = 'gift',      `color` = '#a855f7' WHERE (`name` LIKE '%هدیه%' OR `name` LIKE '%کادو%') AND @fresh = 1;
UPDATE `categories` SET `icon` = 'repair',    `color` = '#78716c' WHERE (`name` LIKE '%تعمیر%' OR `name` LIKE '%صافکار%' OR `name` LIKE '%خدمات%') AND @fresh = 1;

-- ---------- درآمدها ----------
UPDATE `categories` SET `icon` = 'salary', `color` = '#059669' WHERE (`type` = 'income' AND (`name` LIKE '%حقوق%' OR `name` LIKE '%درآمد%' OR `name` LIKE '%دستمزد%')) AND @fresh = 1;
UPDATE `categories` SET `icon` = 'profit', `color` = '#16a34a' WHERE (`type` = 'income' AND (`name` LIKE '%سود%' OR `name` LIKE '%سرمایه%' OR `name` LIKE '%سهام%')) AND @fresh = 1;
UPDATE `categories` SET `icon` = 'sale',   `color` = '#14b8a6' WHERE (`type` = 'income' AND `name` LIKE '%فروش%') AND @fresh = 1;

-- ---------- باقی موارد ----------
UPDATE `categories` SET `icon` = 'money', `color` = '#10b981' WHERE (`type` = 'income' AND `icon` = 'default') AND @fresh = 1;
