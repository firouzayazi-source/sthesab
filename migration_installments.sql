-- ============================================================
-- وام و قسط روی طلب و بدهی
-- ============================================================
--
-- ⛔ چرا: رایج‌ترین بدهیِ خانوارِ ایرانی وامِ بانکی یا قرضِ صندوق با
--    اقساطِ ماهانه است. مدلِ فعلی فقط یک مبلغ و **یک** سررسید دارد،
--    پس کاربر یا باید ۳۶ بدهیِ جدا بسازد (که دفترش را غیرقابل خواندن
--    می‌کند) یا کلِ وام را یک بدهی با سررسیدِ آخر ثبت کند — که آن‌وقت
--    «آینده مالی» و «پول قابل خرج» قسطِ ماهِ بعد را اصلاً نمی‌بینند و
--    عددِ قابل خرج به‌شدت خوش‌بین می‌شود.
--
-- ⚠ **هیچ ردیفِ قسطی ذخیره نمی‌شود.** برنامه‌ی قسط مثل تراکنش‌های
--   دوره‌ای *مجازی* است و در `financialEvents()` از روی همین سه ستون
--   ساخته می‌شود. دلیلش:
--     • ۳۶ ردیف به‌ازای هر وام یعنی جدولی که سریع بزرگ می‌شود
--     • پرداختِ واقعی از قبل در `debt_payments` ثبت می‌شود؛ ردیفِ قسط
--       نسخه‌ی دومی از همان حقیقت می‌شد و دیر یا زود از آن دور می‌افتاد
--     • تغییر تعداد اقساط یعنی بازسازیِ همه‌ی ردیف‌های پرداخت‌نشده
--
-- ایدمپوتنت است.

SET @has_cnt := (
    SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'debts' AND column_name = 'installment_count'
);
SET @sql := IF(@has_cnt = 0,
    'ALTER TABLE `debts` ADD COLUMN `installment_count` SMALLINT UNSIGNED DEFAULT NULL',
    'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @has_every := (
    SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'debts' AND column_name = 'installment_every'
);
SET @sql := IF(@has_every = 0,
    "ALTER TABLE `debts` ADD COLUMN `installment_every` ENUM('monthly','weekly') DEFAULT NULL",
    'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- تاریخِ **اولین** قسط جداست از `due_date` (که سررسیدِ آخر است).
-- بدون آن معلوم نبود شمارش از کِی شروع می‌شود.
SET @has_first := (
    SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'debts' AND column_name = 'first_installment_date'
);
SET @sql := IF(@has_first = 0,
    'ALTER TABLE `debts` ADD COLUMN `first_installment_date` DATE DEFAULT NULL',
    'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
