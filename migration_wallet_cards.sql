-- ============================================================
-- migration_wallet_cards.sql — اطلاعات کامل حساب بانکی
--
-- تا حالا فقط نام بانک و چهار رقم آخر کارت ذخیره می‌شد. برای اینکه
-- کاربر بتواند کارتش را کامل ثبت کند و شکل کارت واقعی ببیندش، شماره‌ی
-- کارت، شماره‌ی حساب، شبا و کد بانک اضافه می‌شوند.
--
-- ⚠️ این‌ها داده‌ی حساس‌اند: فقط صاحب حساب (WHERE user_id) می‌بیندشان،
-- در فهرست حساب‌ها نمایش داده نمی‌شوند و فقط داخل نمای کارت باز
-- می‌شوند. در بکاپ‌ها هم هستند — فایل بکاپ را جایی امن نگه دارید.
--
-- ایمن و قابل اجرای دوباره.
-- ============================================================

SET @c = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'wallets' AND COLUMN_NAME = 'card_number');
SET @s = IF(@c = 0, 'ALTER TABLE `wallets` ADD COLUMN `card_number` VARCHAR(19) NULL AFTER `card_last4`', 'SELECT 1');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @c = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'wallets' AND COLUMN_NAME = 'account_number');
SET @s = IF(@c = 0, 'ALTER TABLE `wallets` ADD COLUMN `account_number` VARCHAR(40) NULL AFTER `card_number`', 'SELECT 1');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @c = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'wallets' AND COLUMN_NAME = 'iban');
SET @s = IF(@c = 0, 'ALTER TABLE `wallets` ADD COLUMN `iban` VARCHAR(26) NULL AFTER `account_number`', 'SELECT 1');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- کد بانک انتخاب‌شده از فهرست، تا رنگ و طرح کارت از روی آن ساخته شود
SET @c = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'wallets' AND COLUMN_NAME = 'bank_code');
SET @s = IF(@c = 0, 'ALTER TABLE `wallets` ADD COLUMN `bank_code` VARCHAR(20) NULL AFTER `bank_name`', 'SELECT 1');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SELECT 'migration_wallet_cards با موفقیت اجرا شد.' AS result;
