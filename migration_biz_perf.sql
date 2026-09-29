-- ============================================
-- ⛔ سرعتِ فروشگاه — شش ایندکسِ پوششی، هر شش اندازه‌گیری شده
-- ============================================
--
-- اندازه‌گیری روی یک فروشگاهِ پرکار (۳۰۰ کالا، ۸۰ طرف‌حساب، ۱۵۰۰ فاکتورِ
-- فروش در یک سال، ۸۰۰ دریافت/پرداخت) — کمترینِ بیست اجرا از PDO:
--
--     موجودیِ همه‌ی طرف‌حساب‌ها (منوها، خلاصه)   ۴٫۹۵ ms → ۲٫۳۰ ms
--     موجودیِ صندوق‌ها (بیشترِ صفحه‌ها)          ۲٫۶۸ ms → ۰٫۹۹ ms
--     سریِ روزانه‌ی فروشِ یک سال (داشبورد)       ۷٫۱۱ ms → ۴٫۴۸ ms
--     فروش به‌تفکیکِ دسته (گزارش، یک سال)       ۱۴۳ ms → ۱۴ ms  (با FORCE INDEX)
--
-- ⚠ ایندکس‌های قدیمی حذف نشدند (همان قاعده‌ی `migration_indexes2.sql`):
--   `DROP INDEX` روی نصبی که آن را ندارد شاخه‌ی تازه‌ی شکست می‌سازد، و
--   ستون‌های کلیدِ خارجی ایندکسِ خودشان را لازم دارند.
-- ⚠ `user_id` داخلِ هر ایندکس است: زیرکوئری‌ها `x.user_id = p.user_id` هم
--   دارند و بی‌آن ستون برای هر ردیف به جدول برمی‌گشت (نسخه‌ی اولِ همین
--   سنجش بدونِ `user_id` هیچ بهبودی نداد).
--
-- ایدمپوتنت است: الگوی `INFORMATION_SCHEMA` + `PREPARE` (MySQL 8 هم
-- `ADD INDEX IF NOT EXISTS` ندارد).

-- ---------- 1. idx_biz_invoices_day ----------
-- سریِ روزانه‌ی فروش (داشبورد) و همه‌ی گزارش‌های بازه‌ای: فاکتورِ صادرشده در
--    یک بازه، با `kind` داخلِ خودِ ایندکس (بی‌برگشت به جدول).
SET @tb = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES
           WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'biz_invoices');
SET @ix = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
           WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'biz_invoices' AND INDEX_NAME = 'idx_biz_invoices_day');
SET @sql = IF(@tb = 1 AND @ix = 0,
    'ALTER TABLE `biz_invoices` ADD INDEX `idx_biz_invoices_day` (`user_id`, `status`, `inv_date`, `kind`)',
    'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ---------- 2. idx_biz_lines_cover ----------
-- ردیف‌های همان فاکتورها برای جمعِ فروش و بهای تمام‌شده، و گروه‌بندی
--    به‌تفکیکِ کالا/دسته — پوششی.
SET @tb = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES
           WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'biz_invoice_lines');
SET @ix = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
           WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'biz_invoice_lines' AND INDEX_NAME = 'idx_biz_lines_cover');
SET @sql = IF(@tb = 1 AND @ix = 0,
    'ALTER TABLE `biz_invoice_lines` ADD INDEX `idx_biz_lines_cover` (`invoice_id`, `user_id`, `product_id`, `net_total`, `unit_cost`, `qty`)',
    'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ---------- 3. idx_biz_invoices_party_sum ----------
-- `BizParties::BALANCE_SQL` — زیرکوئریِ اسنادِ هر طرف‌حساب، پوششی.
SET @tb = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES
           WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'biz_invoices');
SET @ix = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
           WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'biz_invoices' AND INDEX_NAME = 'idx_biz_invoices_party_sum');
SET @sql = IF(@tb = 1 AND @ix = 0,
    'ALTER TABLE `biz_invoices` ADD INDEX `idx_biz_invoices_party_sum` (`party_id`, `user_id`, `status`, `kind`, `total`)',
    'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ---------- 4. idx_biz_payments_party_sum ----------
-- همان، برای دریافت/پرداختِ هر طرف‌حساب.
SET @tb = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES
           WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'biz_payments');
SET @ix = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
           WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'biz_payments' AND INDEX_NAME = 'idx_biz_payments_party_sum');
SET @sql = IF(@tb = 1 AND @ix = 0,
    'ALTER TABLE `biz_payments` ADD INDEX `idx_biz_payments_party_sum` (`party_id`, `user_id`, `status`, `kind`, `amount`)',
    'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ---------- 5. idx_biz_payments_acc_sum ----------
-- `BizCash::BALANCE_SQL` — موجودیِ هر صندوق (روی بیشترِ صفحه‌ها).
SET @tb = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES
           WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'biz_payments');
SET @ix = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
           WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'biz_payments' AND INDEX_NAME = 'idx_biz_payments_acc_sum');
SET @sql = IF(@tb = 1 AND @ix = 0,
    'ALTER TABLE `biz_payments` ADD INDEX `idx_biz_payments_acc_sum` (`account_id`, `user_id`, `status`, `kind`, `amount`)',
    'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ---------- 6. idx_biz_payments_to_sum ----------
-- همان، سرِ مقصدِ انتقال‌ها.
SET @tb = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES
           WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'biz_payments');
SET @ix = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
           WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'biz_payments' AND INDEX_NAME = 'idx_biz_payments_to_sum');
SET @sql = IF(@tb = 1 AND @ix = 0,
    'ALTER TABLE `biz_payments` ADD INDEX `idx_biz_payments_to_sum` (`to_account_id`, `user_id`, `kind`, `status`, `amount`)',
    'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
