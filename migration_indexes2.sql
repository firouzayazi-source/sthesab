-- ============================================
-- دورِ دومِ ایندکس‌ها — فقط دو تا، و هر دو با EXPLAIN سنجیده شده‌اند
-- ============================================
--
-- ⛔ این فایل عمداً کوتاه است. بیشترِ جدول‌های تازه از قبل ایندکسِ درست
--    دارند؛ افزودنِ ایندکسِ حدسی هزینه‌ی نوشتن را بالا می‌برد بی‌آنکه
--    هیچ کوئری‌ای را تندتر کند — یعنی همان «کارِ بی‌فایده‌ای که به نظر
--    مفید می‌آید». هر دو موردِ زیر روی یک دیتابیسِ **دوکاربره با
--    ۱۲٬۰۰۰ تراکنش و ۱٬۶۰۰ اعلان** اندازه‌گیری شدند: پیش از افزودن
--    `type=ALL` (پویشِ کلِ جدول) و پس از آن `ref` + `Using index`.
--
-- ⚠ چرا دو کاربر: روی جدولی که همه‌ی ردیف‌هایش مالِ یک کاربر است،
--   اپتیمایزر هر ایندکسِ `user_id` را کنار می‌گذارد و پویشِ کامل واقعاً
--   ارزان‌تر است. سنجشِ تک‌کاربره «ایندکس بی‌فایده است» می‌گوید و آن
--   دقیقاً همان سنجشی است که روی سیستمِ سالم دروغ می‌گوید.
--
-- ایدمپوتنت است: با الگوی `INFORMATION_SCHEMA` + `PREPARE`، پس هم روی
-- MariaDB کار می‌کند هم روی MySQL 8 (که `ADD INDEX IF NOT EXISTS` ندارد).

-- ---------- ۱. شمارشِ اعلانِ نخوانده ----------
--
-- ⛔ این کوئری در `includes/header.php` است، یعنی روی **هر صفحه‌ی اپ**
--    اجرا می‌شود. `idx_notif_user (user_id, id)` به `read_at IS NULL`
--    کمکی نمی‌کند، پس اپتیمایزر کلِ جدولِ `notifications` — ردیف‌های
--    همه‌ی کاربران — را پویش می‌کرد. با این ایندکس پاسخ کاملاً از خودِ
--    ایندکس درمی‌آید و هیچ ردیفی خوانده نمی‌شود.
--
-- ⚠ `idx_notif_user` حذف نشد: فهرستِ مرکزِ اعلان با `ORDER BY id DESC`
--   می‌آید و این یکی آن ترتیب را ندارد.
SET @ix = (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'notifications'
      AND INDEX_NAME = 'idx_notif_unread'
);
SET @tb = (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'notifications'
);
SET @sql = IF(@tb = 1 AND @ix = 0,
    'ALTER TABLE `notifications` ADD INDEX `idx_notif_unread` (`user_id`, `read_at`)',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ---------- ۲. چکِ در جریان ----------
--
-- ⛔ همان شرطی که `chequeActiveSql()` می‌سازد: `is_settled = 0 AND
--    status = 'pending'`. `idx_cheque_status (user_id, status)` هست ولی
--    اپتیمایزر با ستونِ سومِ فیلتر کنارش می‌گذاشت و کلِ جدول را
--    می‌خواند. این یکی هر سه ستونِ شرط را دارد به‌علاوه‌ی `due_date`
--    که همه‌ی همین کوئری‌ها با آن مرتب یا صافی می‌شوند، پس پاسخ از خودِ
--    ایندکس درمی‌آید.
--
-- ⚠ `idx_cheque_status` عمداً حذف نشد. پیشوندِ این یکی است و از دیدِ
--   فضا افزونه، ولی جدولِ چک چند صد ردیف است و هزینه‌ی نوشتنش ناچیز؛
--   در برابرش `DROP INDEX` روی نصبی که آن را ندارد یک شاخه‌ی تازه‌ی
--   شکست می‌سازد. کمترین کاری که مسئله را حل می‌کند، همین است.
SET @ix = (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'cheques'
      AND INDEX_NAME = 'idx_cheque_open'
);
SET @cs = (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'cheques' AND COLUMN_NAME = 'status'
);
SET @sql = IF(@cs = 1 AND @ix = 0,
    'ALTER TABLE `cheques` ADD INDEX `idx_cheque_open` (`user_id`, `status`, `is_settled`, `due_date`)',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
