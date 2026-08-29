-- ============================================================
-- migration_money_links.sql — وصل کردن چک و طلب/بدهی به حساب‌ها
--
-- تا حالا چک پاس‌شده و بدهیِ تسویه‌شده فقط یک تیک بودند: پول واقعاً
-- جابه‌جا می‌شد ولی هیچ‌جای برنامه معلوم نبود از کدام حساب رفت یا به
-- کدام حساب آمد. حالا:
--
--   cheques.settle_wallet_id  → چک دریافتی به این حساب واریز شد،
--                               چک صادره از این حساب برداشت شد.
--   debt_payments.wallet_id   → هر پرداخت طلب/بدهی از/به این حساب.
--   debt_payments.is_settlement → پرداختی که خودِ برنامه هنگام زدن تیک
--                               «تسویه شد» برای باقیمانده ساخته است.
--                               برداشتن تیک همین ردیف‌ها را پس می‌گیرد.
--
-- همچنین «کیف پول نقدی» به «کیف پول» تغییر نام می‌دهد. آن نام را خود
-- migration_wallets/repair برای همه‌ی کاربران ساخته بود و پیش‌فرضِ فرم
-- ثبت تراکنش است؛ اسم کوتاه‌تر گویاتر است.
--
-- ایمن و قابل اجرای دوباره.
-- ============================================================

-- ---------- ۱. حساب وصول چک ----------
SET @c = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'cheques' AND COLUMN_NAME = 'settle_wallet_id');
SET @s = IF(@c = 0,
    'ALTER TABLE `cheques` ADD COLUMN `settle_wallet_id` INT UNSIGNED NULL AFTER `is_settled`, ADD KEY `idx_settle_wallet` (`settle_wallet_id`)',
    'SELECT 1');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @c = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLE_CONSTRAINTS
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'cheques' AND CONSTRAINT_NAME = 'fk_cheques_settle_wallet');
SET @s = IF(@c = 0,
    'ALTER TABLE `cheques` ADD CONSTRAINT `fk_cheques_settle_wallet` FOREIGN KEY (`settle_wallet_id`) REFERENCES `wallets` (`id`) ON DELETE SET NULL ON UPDATE CASCADE',
    'SELECT 1');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- ---------- ۲. حساب پرداخت طلب/بدهی ----------
SET @c = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'debt_payments' AND COLUMN_NAME = 'wallet_id');
SET @s = IF(@c = 0,
    'ALTER TABLE `debt_payments` ADD COLUMN `wallet_id` INT UNSIGNED NULL AFTER `user_id`, ADD KEY `idx_paym_wallet` (`wallet_id`)',
    'SELECT 1');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @c = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLE_CONSTRAINTS
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'debt_payments' AND CONSTRAINT_NAME = 'fk_paym_wallet');
SET @s = IF(@c = 0,
    'ALTER TABLE `debt_payments` ADD CONSTRAINT `fk_paym_wallet` FOREIGN KEY (`wallet_id`) REFERENCES `wallets` (`id`) ON DELETE SET NULL ON UPDATE CASCADE',
    'SELECT 1');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @c = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'debt_payments' AND COLUMN_NAME = 'is_settlement');
SET @s = IF(@c = 0,
    'ALTER TABLE `debt_payments` ADD COLUMN `is_settlement` TINYINT(1) NOT NULL DEFAULT 0 AFTER `payment_date`',
    'SELECT 1');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- ---------- ۳. ایندکس تفکیک تراکنش‌ها بر اساس حساب ----------
-- صفحه‌ی تراکنش‌ها حالا فیلتر «حساب» دارد و walletBalances هم روی همین
-- ستون گروه می‌بندد.
SET @c = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'transactions' AND INDEX_NAME = 'idx_user_wallet_date');
SET @s = IF(@c = 0,
    'ALTER TABLE `transactions` ADD INDEX `idx_user_wallet_date` (`user_id`, `wallet_id`, `transaction_date`)',
    'SELECT 1');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- ---------- ۴. نام کوتاه‌تر برای کیف پول پیش‌فرض ----------
-- نام حساب یکتا نیست، پس تغییر نام بی‌خطر است و اجرای دوباره کاری نمی‌کند.
UPDATE `wallets` SET `name` = 'کیف پول' WHERE `name` = 'کیف پول نقدی';

SELECT 'migration_money_links با موفقیت اجرا شد.' AS result;
