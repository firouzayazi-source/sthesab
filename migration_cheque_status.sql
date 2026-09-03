-- ============================================================
-- چرخه‌ی کاملِ چک: پاس شد / برگشت خورد / خرج شد
-- ============================================================
--
-- ⛔ چرا: چک تفاوت‌سازترین چیزِ این اپ برای بازار ایران است، ولی
--    مدلش فقط دو حالت داشت — `is_settled` صفر یا یک. در واقعیت:
--
--    • **برگشت خوردن** رخدادِ مالیِ جدی‌ای است و همان لحظه چکِ دریافتی
--      به «طلب» تبدیل می‌شود.
--    • **خرج شدن** (واگذاری به نفر سوم) در بازار ایران بسیار رایج است.
--
--    کاربر ناچار بود چکِ خرج‌شده را «پاس شد» بزند — که پول را به
--    اشتباه به حسابش اضافه می‌کرد — یا حذفش کند و تاریخچه را از دست
--    بدهد. هر دو راه غلط بود.
--
-- ⚠ `is_settled` عمداً **حذف نمی‌شود** و مقدارِ مشتق می‌ماند:
--   `is_settled = 1  ⇔  status = 'cleared'`
--   دلیلش این است که `walletBalances()`، `financialEvents()`،
--   `my-assets.php` و تست‌های موجود همه به آن تکیه دارند. اگر برداشته
--   می‌شد، محاسبه‌ی موجودی و «خالص چک‌های در جریان» باید یک‌جا بازنویسی
--   می‌شد — و آن‌ها منطقِ پول‌اند، نه نمایش.
--
-- ایدمپوتنت است.

-- ---------- ستون وضعیت ----------
SET @has_status := (
    SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'cheques' AND column_name = 'status'
);
SET @sql := IF(@has_status = 0,
    "ALTER TABLE `cheques`
       ADD COLUMN `status` ENUM('pending','cleared','bounced','endorsed')
           NOT NULL DEFAULT 'pending' AFTER `is_settled`",
    'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- ---------- گیرنده‌ی چکِ خرج‌شده ----------
SET @has_endorsed := (
    SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'cheques' AND column_name = 'endorsed_to'
);
SET @sql := IF(@has_endorsed = 0,
    "ALTER TABLE `cheques` ADD COLUMN `endorsed_to` VARCHAR(150) DEFAULT NULL AFTER `status`",
    'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- ---------- ایندکس ----------
SET @has_idx := (
    SELECT COUNT(*) FROM information_schema.statistics
    WHERE table_schema = DATABASE() AND table_name = 'cheques' AND index_name = 'idx_cheque_status'
);
SET @sql := IF(@has_idx = 0,
    'ALTER TABLE `cheques` ADD INDEX `idx_cheque_status` (`user_id`, `status`)',
    'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- ---------- هم‌گام کردنِ چک‌های موجود ----------
-- هر چکی که تا امروز «پاس شده» علامت خورده، وضعیتش cleared است.
-- بقیه pending می‌مانند (پیش‌فرضِ ستون). چون شرطِ `status = 'pending'`
-- دارد، اجرای دوباره چیزی را که کاربر بعداً عوض کرده برنمی‌گرداند.
UPDATE `cheques`
SET `status` = 'cleared'
WHERE `is_settled` = 1 AND `status` = 'pending';

-- ---------- پیوندِ چکِ برگشتی به طلبِ ساخته‌شده ----------
-- ⚠ بدونِ این پیوند، چکِ برگشتی و طلبِ حاصلش **دو قلمِ جدا** می‌شدند و
--   اگر روزی وضعیتِ چک برگردانده شود، طلبِ یتیم باقی می‌ماند. با آن،
--   برگرداندنِ وضعیت همان ردیف را هم پس می‌گیرد.
SET @has_cheque_id := (
    SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'debts' AND column_name = 'cheque_id'
);
SET @sql := IF(@has_cheque_id = 0,
    'ALTER TABLE `debts` ADD COLUMN `cheque_id` BIGINT UNSIGNED DEFAULT NULL',
    'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @has_didx := (
    SELECT COUNT(*) FROM information_schema.statistics
    WHERE table_schema = DATABASE() AND table_name = 'debts' AND index_name = 'idx_debt_cheque'
);
SET @sql := IF(@has_didx = 0,
    'ALTER TABLE `debts` ADD INDEX `idx_debt_cheque` (`cheque_id`)',
    'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
