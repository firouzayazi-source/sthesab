-- ============================================
-- نشانه‌ی «پیش‌فرض‌های کاربر ساخته شد» از app_settings به users می‌رود
-- ============================================
--
-- ⛔ **خرابیِ واقعی، و بی‌صدا:** `seedUserDefaults()` به‌ازای **هر
--    کاربر** یک ردیف در `app_settings` می‌نوشت (`seeded_user_42`). آن
--    جدول جای تنظیماتِ **نصب** است — چند ده ردیف — نه یک ردیف به‌ازای
--    هر کاربر.
--
--    و چون `app_settings` ستونِ `user_id` ندارد، `deleteUserAccount()`
--    (که جدول‌ها را از روی همان ستون پیدا می‌کند) آن ردیف را **نمی‌بیند**:
--    کاربر حذف می‌شود و نشانه‌اش تا ابد می‌ماند. یعنی «حذفی که چیزی جا
--    می‌گذارد» — دقیقاً همان چیزی که `userDataTables()` برای نبودنش
--    نوشته شد.
--
-- ⛔ و چرا نشانه اصلاً لازم است (تا کسی وسوسه نشود حذفش کند): اگر
--    «پیش‌فرض‌ها ساخته شده‌اند» را از خودِ داده بخوانیم، کاربری که همه‌ی
--    بانک‌های پیش‌فرض را **عمداً** پاک کرده، با بازدیدِ بعدی همه را
--    دوباره می‌گیرد. همان استدلالِ `more_categories_seeded`.
--
-- ⚠ نقلِ داده باید **دقیق** باشد، وگرنه همان بازگشتِ ناخواسته اتفاق
--   می‌افتد. به همین دلیل تطبیق **عددی** است نه رشته‌ای: شناسه از نامِ
--   کلید بیرون کشیده و با `id` مقایسه می‌شود، پس هیچ مقایسه‌ی
--   ستون‌به‌ستونِ متنی در کار نیست و «Illegal mix of collations» ممکن
--   نیست (همان دامی که `FIND_IN_SET(name, @var)` را روی سرور کشت).
--
-- ⚠ `\_` در `LIKE` عمدی است: `_` خودش یک وایلدکارتِ تک‌کاراکتری است و
--   بدونِ escape الگو کلیدهای دیگری را هم می‌گرفت.

-- ---------- ۱. ستونِ تازه ----------
SET @c = (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users'
      AND COLUMN_NAME = 'defaults_seeded_at'
);
SET @sql = IF(@c = 0,
    'ALTER TABLE `users` ADD COLUMN `defaults_seeded_at` DATETIME NULL DEFAULT NULL',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ---------- ۲. نقلِ نشانه‌های موجود ----------
--
-- ⚠ فقط کسانی که نشانه‌شان واقعاً `1` است. ردیفی با مقدارِ دیگر یعنی
--   seed تمام نشده و باید دوباره اجرا شود.
UPDATE `users`
   SET `defaults_seeded_at` = NOW()
 WHERE `defaults_seeded_at` IS NULL
   AND `id` IN (
        SELECT CAST(SUBSTRING(`setting_key`, 13) AS UNSIGNED)
          FROM `app_settings`
         WHERE `setting_key` LIKE 'seeded\_user\_%'
           AND `setting_value` = '1'
   );

-- ---------- ۳. پاک کردنِ ردیف‌های منتقل‌شده ----------
--
-- ⛔ **بعد از** نقل، نه قبلش. و بدونِ شرطِ مقدار: ردیفی که مقدارش `1`
--    نبوده هم باید برود — کارش تمام است و منتقل نشدنش یعنی seed دوباره
--    اجرا می‌شود، که همان رفتارِ درست است (`INSERT IGNORE`).
DELETE FROM `app_settings` WHERE `setting_key` LIKE 'seeded\_user\_%';
