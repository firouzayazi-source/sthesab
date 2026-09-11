-- ============================================
-- دورِ سومِ ایندکس‌ها — دو تا، و هر دو اندازه‌گیری شده‌اند
-- ============================================
--
-- ⛔ خرابی‌ای که این فایل می‌بندد **با تعدادِ کوئری دیده نمی‌شود** و به
--    همین دلیل از زیرِ `tests/test_query_budget.php` رد شده بود: تعدادِ
--    کوئریِ هیچ صفحه‌ای عوض نمی‌شود، فقط هر کدامشان با بزرگ شدنِ
--    تاریخچه‌ی کاربر کندتر می‌شوند. روی دیتابیسِ توسعه (چند صد تراکنش)
--    کاملاً نامرئی است.
--
-- اندازه‌گیری روی یک دیتابیسِ **دوکاربره با ۲۰٬۰۰۰ تراکنش برای هر
-- کاربر** (کمترینِ هفت اجرا، از PDO، پس زمانِ راه‌اندازیِ کلاینت در آن
-- نیست):
--
--     صفحه‌ی خانه — ۸ تراکنشِ آخر        ۱۸٫۰۶ ms  →  ۰٫۲۴ ms
--     صفحه‌ی تراکنش‌ها — صفحه‌ی اول       ۱۸٫۸۸ ms  →  ۰٫۳۲ ms
--     داشبورد — جمعِ روزانه‌ی تاریخچه     ۲۲٫۰۷ ms  →  ۱۰٫۲۷ ms
--     admin/users — آخرین فعالیت         ۳۷٫۷۶ ms  →  ۱۲٫۰۵ ms
--
--    برای مقایسه: رندرِ کلِ یک صفحه ۴ تا ۸ میلی‌ثانیه است. یعنی پیش از
--    این، **یک** کوئری چند برابرِ کلِ صفحه طول می‌کشید.
--
-- ⚠ هزینه‌ی نوشتن هم اندازه‌گیری شد، نه حدس زده: با **هر سه** ایندکس،
--   درجِ یک تراکنش از ۰٫۰۶۵۷ به ۰٫۰۷۱۳ میلی‌ثانیه رفت (+۸٫۵٪). کاربر
--   روزی چند تراکنش ثبت می‌کند و صفحه‌ها را ده‌ها بار باز می‌کند، پس این
--   معامله یک‌طرفه است.
--
-- ⚠ چرا دو کاربر: همان درسِ `migration_indexes2.sql`. روی جدولی که
--   همه‌ی ردیف‌هایش مالِ یک کاربر است، اپتیمایزر هر ایندکسِ `user_id` را
--   کنار می‌گذارد و سنجش «ایندکس بی‌فایده است» می‌گوید.
--
-- ایدمپوتنت است: با الگوی `INFORMATION_SCHEMA` + `PREPARE`، پس هم روی
-- MariaDB کار می‌کند هم روی MySQL 8 (که `ADD INDEX IF NOT EXISTS` ندارد).

-- ---------- ۱. فهرستِ «آخرین تراکنش‌ها» روی صفحه‌ی خانه ----------
--
-- ⛔ آن فهرست `ORDER BY created_at DESC LIMIT 8` است و **هیچ ایندکسی
--    `created_at` نداشت**. ایندکس‌های موجود همه با `transaction_date`
--    تمام می‌شدند، پس برای نشان دادنِ **۸** ردیف، دیتابیس همه‌ی
--    تراکنش‌های کاربر را می‌خواند و در حافظه مرتب می‌کرد (`filesort`).
--    یعنی هزینه‌ی صفحه‌ی خانه با کلِ تاریخچه‌ی کاربر خطی بالا می‌رفت.
--
-- ⚠ همین ایندکس `MAX(created_at) WHERE user_id = ?` را هم می‌پوشاند —
--   همان زیرکوئریِ «آخرین فعالیت» در `admin/users.php` که به‌ازای هر
--   کاربر یک بار اجرا می‌شود. بیشترِ بهبودِ آن صفحه از همین‌جاست.
SET @ix = (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'transactions'
      AND INDEX_NAME = 'idx_user_created'
);
SET @tb = (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'transactions'
);
SET @sql = IF(@tb = 1 AND @ix = 0,
    'ALTER TABLE `transactions` ADD INDEX `idx_user_created` (`user_id`, `created_at`)',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ---------- ۲. فهرستِ تراکنش‌ها، و جمعِ روزانه‌ی داشبورد ----------
--
-- ⛔ یک ایندکس برای **دو** کارِ متفاوت، و عمداً یکی است نه دو تا:
--
--    الف) `ORDER BY transaction_date DESC, created_at DESC` در
--         `transactions.php`. ایندکسِ `idx_user_date` فقط کلیدِ اولِ
--         مرتب‌سازی را داشت، پس `filesort` سرِ جایش می‌ماند و کلِ
--         تاریخچه برای یک صفحه‌ی ۴۰تایی خوانده می‌شد.
--
--    ب) جمعِ روزانه‌ی داشبورد. آن کوئری عمداً کلِ تاریخچه را **یک بار**
--       می‌خواند (تا هشت کوئریِ جدا به سه برسد) و آن تصمیم درست است،
--       ولی باید از خودِ ایندکس خوانده شود نه از جدول. ستون‌های `type`
--       و `amount` برای همین در انتهای ایندکس‌اند: `Using index`.
--
-- ⚠ نسخه‌ی اول **دو** ایندکسِ جدا داشت
--   (`…,created_at` و `…,type,amount`). با یکی کردنشان هر دو کوئری
--   همان عدد را گرفتند و یک ایندکس کمتر روی داغ‌ترین جدولِ اپ نشست.
--   اندازه‌گیری شد، حدس زده نشد.
--
-- ⚠ `idx_user_date (user_id, transaction_date)` حالا پیشوندِ این است و
--   از دیدِ فضا افزونه — ولی حذف نشد، همان قاعده‌ی `migration_indexes2`:
--   `DROP INDEX` روی نصبی که آن را ندارد یک شاخه‌ی تازه‌ی شکست می‌سازد،
--   و کمترین کاری که مسئله را حل می‌کند همین است.
SET @ix = (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'transactions'
      AND INDEX_NAME = 'idx_user_date_created'
);
SET @tb = (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'transactions'
);
SET @sql = IF(@tb = 1 AND @ix = 0,
    'ALTER TABLE `transactions`
     ADD INDEX `idx_user_date_created` (`user_id`, `transaction_date`, `created_at`, `type`, `amount`)',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ---------- ۳. جمعِ درآمد و هزینه‌ی هر حساب در `walletBalances()` ----------
--
-- ⛔ `walletBalances()` سنگین‌ترین کوئریِ اپ است و روی خانه، صفحه‌ی
--    حساب‌ها و دارایی اجرا می‌شود. بعد از دو ایندکسِ بالا، **تنها**
--    چیزی بود که روی صفحه‌ی خانه مانده بود: ۲۳ از ۳۵ میلی‌ثانیه.
--
--    زیرکوئریِ داغش `GROUP BY wallet_id` روی همه‌ی تراکنش‌های کاربر
--    است. `idx_user_wallet_date` هر دو ستونِ گروه‌بندی را دارد ولی نه
--    `type` و نه `amount` را، پس برای **هر** ردیف به خودِ جدول
--    برمی‌گشت. با این ایندکس پاسخ کاملاً از ایندکس درمی‌آید:
--
--        زیرکوئری        ۲۱٫۰۸ ms  →  ۶٫۲۵ ms   (`Using index`)
--        walletBalances() ۲۲٫۹۹ ms  →  ۷٫۳۱ ms
--
-- ⚠ `idx_user_wallet_date` حذف نشد: صافیِ `?wallet=ID` صفحه‌ی
--   تراکنش‌ها با `transaction_date` مرتب می‌شود و این یکی آن ستون را
--   ندارد. همان قاعده‌ی `idx_cheque_status` در `migration_indexes2`.
SET @ix = (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'transactions'
      AND INDEX_NAME = 'idx_user_wallet_sum'
);
-- ⛔ اینجا **ستون** سنجیده می‌شود نه جدول: `transactions.wallet_id` در
--    `schema.sql` نیست و `migration_repair.sql` اضافه‌اش می‌کند. با
--    شاهدِ «جدول هست»، نصبی که هنوز به آن migration نرسیده با
--    «Unknown column» می‌مرد.
SET @col = (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'transactions'
      AND COLUMN_NAME = 'wallet_id'
);
SET @sql = IF(@col = 1 AND @ix = 0,
    'ALTER TABLE `transactions`
     ADD INDEX `idx_user_wallet_sum` (`user_id`, `wallet_id`, `type`, `amount`)',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
