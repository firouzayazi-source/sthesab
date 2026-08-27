-- ============================================
-- افزودن آیکن و رنگ به دسته‌بندی‌ها
-- این فایل را در phpMyAdmin روی دیتابیس فعلی Import کنید
--
-- توجه: در ستون icon فقط یک «کد انگلیسی» ذخیره می‌شود (مثل food یا transport)
-- و خود ایموجی در کد PHP نمایش داده می‌شود. به این ترتیب هیچ‌وقت مشکل
-- کاراکترست و تبدیل شدن ایموجی به ???? پیش نمی‌آید.
-- ============================================

ALTER TABLE `categories`
    ADD COLUMN `icon` VARCHAR(24) NOT NULL DEFAULT 'default' AFTER `type`,
    ADD COLUMN `color` VARCHAR(7) NOT NULL DEFAULT '#64748b' AFTER `icon`;

-- ---------- هزینه‌ها ----------
UPDATE `categories` SET `icon` = 'food',      `color` = '#f97316' WHERE `name` LIKE '%غذا%' OR `name` LIKE '%خورا%' OR `name` LIKE '%رستوران%' OR `name` LIKE '%میوه%';
UPDATE `categories` SET `icon` = 'transport', `color` = '#0ea5e9' WHERE `name` LIKE '%حمل%' OR `name` LIKE '%نقل%' OR `name` LIKE '%تاکسی%' OR `name` LIKE '%بنزین%' OR `name` LIKE '%خودرو%' OR `name` LIKE '%ماشین%';
UPDATE `categories` SET `icon` = 'shopping',  `color` = '#8b5cf6' WHERE `name` LIKE '%خرید%' OR `name` LIKE '%فروشگاه%' OR `name` LIKE '%سوپر%';
UPDATE `categories` SET `icon` = 'home',      `color` = '#ef4444' WHERE `name` LIKE '%اجاره%' OR `name` LIKE '%مسکن%' OR `name` LIKE '%خونه%' OR `name` LIKE '%خانه%' OR `name` LIKE '%رهن%';
UPDATE `categories` SET `icon` = 'health',    `color` = '#ec4899' WHERE `name` LIKE '%درمان%' OR `name` LIKE '%پزشک%' OR `name` LIKE '%دارو%' OR `name` LIKE '%سلامت%' OR `name` LIKE '%بهداشت%';
UPDATE `categories` SET `icon` = 'bill',      `color` = '#eab308' WHERE `name` LIKE '%قبض%' OR `name` LIKE '%برق%' OR `name` LIKE '%آب%' OR `name` LIKE '%گاز%' OR `name` LIKE '%شارژ%';
UPDATE `categories` SET `icon` = 'phone',     `color` = '#06b6d4' WHERE `name` LIKE '%موبایل%' OR `name` LIKE '%اینترنت%' OR `name` LIKE '%تلفن%' OR `name` LIKE '%گوشی%';
UPDATE `categories` SET `icon` = 'education', `color` = '#6366f1' WHERE `name` LIKE '%آموزش%' OR `name` LIKE '%تحصیل%' OR `name` LIKE '%کتاب%' OR `name` LIKE '%کلاس%';
UPDATE `categories` SET `icon` = 'fun',       `color` = '#d946ef' WHERE `name` LIKE '%تفریح%' OR `name` LIKE '%سفر%' OR `name` LIKE '%مسافرت%';
UPDATE `categories` SET `icon` = 'clothes',   `color` = '#f43f5e' WHERE `name` LIKE '%پوشاک%' OR `name` LIKE '%لباس%';
UPDATE `categories` SET `icon` = 'gift',      `color` = '#a855f7' WHERE `name` LIKE '%هدیه%' OR `name` LIKE '%کادو%';
UPDATE `categories` SET `icon` = 'repair',    `color` = '#78716c' WHERE `name` LIKE '%تعمیر%' OR `name` LIKE '%صافکار%' OR `name` LIKE '%خدمات%';

-- ---------- درآمدها ----------
UPDATE `categories` SET `icon` = 'salary', `color` = '#059669' WHERE `type` = 'income' AND (`name` LIKE '%حقوق%' OR `name` LIKE '%درآمد%' OR `name` LIKE '%دستمزد%');
UPDATE `categories` SET `icon` = 'profit', `color` = '#16a34a' WHERE `type` = 'income' AND (`name` LIKE '%سود%' OR `name` LIKE '%سرمایه%' OR `name` LIKE '%سهام%');
UPDATE `categories` SET `icon` = 'sale',   `color` = '#14b8a6' WHERE `type` = 'income' AND `name` LIKE '%فروش%';

-- ---------- باقی موارد ----------
UPDATE `categories` SET `icon` = 'money', `color` = '#10b981' WHERE `type` = 'income' AND `icon` = 'default';
