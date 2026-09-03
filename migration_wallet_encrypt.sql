-- ============================================================
-- جا باز کردن برای شماره‌ی کارت/حساب/شبای **رمزشده**
-- ============================================================
--
-- ⛔ چرا: این سه ستون به اندازه‌ی متنِ خام ساخته شده بودند
--    (VARCHAR(19)/(40)/(26)). مقدارِ رمزشده با نانس و برچسبِ اصالت و
--    base64 حدود سه برابر می‌شود، پس بدون این migration اولین ذخیره‌ی
--    یک شماره‌ی کارت با «Data too long» رد می‌شد — یا بدتر، روی
--    پیکربندیِ غیر-strict **بی‌صدا بریده** می‌شد و آن‌وقت رمزگشایی‌اش
--    برای همیشه شکست می‌خورد: داده رفته، بی‌هیچ خطایی.
--
-- ⚠ فقط جا باز می‌کند. خودِ رمز کردنِ ردیف‌های موجود کارِ
--   `deploy/encrypt-cards.php` است، چون در SQL شدنی نیست.
--
-- بدونِ کلید (`APP_ENCRYPTION_KEY` خالی) این migration بی‌ضرر است:
-- ستون‌ها بزرگ‌تر می‌شوند و همه چیز مثل قبل خام می‌ماند.
--
-- ایدمپوتنت است: اجرای دوباره هیچ اثری ندارد.

-- ---------- card_number ----------
SET @c = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'wallets'
            AND COLUMN_NAME = 'card_number' AND CHARACTER_MAXIMUM_LENGTH < 255);
SET @s = IF(@c > 0,
    'ALTER TABLE `wallets` MODIFY COLUMN `card_number` VARCHAR(255) NULL',
    'SELECT 1');
PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ---------- account_number ----------
SET @c = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'wallets'
            AND COLUMN_NAME = 'account_number' AND CHARACTER_MAXIMUM_LENGTH < 255);
SET @s = IF(@c > 0,
    'ALTER TABLE `wallets` MODIFY COLUMN `account_number` VARCHAR(255) NULL',
    'SELECT 1');
PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ---------- iban ----------
SET @c = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'wallets'
            AND COLUMN_NAME = 'iban' AND CHARACTER_MAXIMUM_LENGTH < 255);
SET @s = IF(@c > 0,
    'ALTER TABLE `wallets` MODIFY COLUMN `iban` VARCHAR(255) NULL',
    'SELECT 1');
PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SELECT 'migration_wallet_encrypt با موفقیت اجرا شد.' AS result;
