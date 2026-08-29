-- ============================================================
-- migration_trades2.sql — پیوند سود معامله به حسابداری
--
-- خواسته‌ی کاربر: بخش معامله جدا بماند، ولی «سود و زیان» هر فروش در
-- حسابداری دیده شود — در آخرین تراکنش‌ها و جمع روز/هفته/ماه.
--
-- راهش: به ازای هر فروش، یک ردیف تراکنش برای سهم سود (نه کل مبلغ
-- فروش) ساخته می‌شود. این ستون آن پیوند را نگه می‌دارد تا با ویرایش
-- یا حذف فروش، تراکنش سودش هم به‌روز یا حذف شود.
--
-- ایمن و قابل اجرای دوباره.
-- ============================================================

SET @col_exists = (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'trade_sales' AND COLUMN_NAME = 'profit_tx_id'
);
SET @sql = IF(@col_exists = 0,
    'ALTER TABLE `trade_sales` ADD COLUMN `profit_tx_id` BIGINT UNSIGNED NULL AFTER `notes`',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SELECT 'migration_trades2 با موفقیت اجرا شد.' AS result;
