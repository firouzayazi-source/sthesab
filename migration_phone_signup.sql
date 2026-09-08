-- ============================================================
-- migration_phone_signup.sql — ثبت‌نام با شماره موبایل
--
-- ⛔ این فایل هیچ راهِ ورودِ تازه‌ای باز نمی‌کند. ثبت‌نام با شماره
--    پشتِ **دو** کلیدِ مدیر است (`allow_signup` و `allow_sms_login`) و
--    هر دو پیش‌فرض خاموش‌اند. اینجا فقط ساختار جا باز می‌کند.
--
-- دو تغییر، و هر دو «اجازه‌ی NULL» هستند نه ستونِ تازه:
--
-- ۱. `users.password_hash` → NULL می‌پذیرد.
--    ⛔ **`NULL` تنها معنای «این حساب رمز ندارد» است.** جایگزینش یک
--       ستونِ بولینِ `has_password` بود که همان دام همیشگی است: دو
--       منبعِ حقیقت که دیر یا زود از هم دور می‌افتند، و آن‌وقت
--       `password_verify()` با یک هشِ ساختگی صدا زده می‌شود.
--    ⚠ هر جایی که این ستون خوانده می‌شود باید پیش از
--      `password_verify()` نبودنش را بسنجد. روی PHP 8 آن تابع با
--      `null` فقط `Deprecated` می‌دهد و `false` برمی‌گرداند؛ در PHP 9
--      `TypeError` می‌شود. `Auth::verifyCredentials()` جای این سنجش
--      برای مسیرِ ورود است.
--
-- ۲. `sms_codes.user_id` → NULL می‌پذیرد.
--    کدِ **ثبت‌نام** پیش از وجود داشتنِ کاربر فرستاده می‌شود، پس در آن
--    لحظه شناسه‌ای برای نوشتن نیست. `NULL` یعنی «این کد هنوز به هیچ
--    حسابی وصل نیست»؛ کلیدِ خارجی و `ON DELETE CASCADE` دست‌نخورده
--    می‌مانند و برای کدهای ورود مثل قبل کار می‌کنند.
--    ⚠ شمارشِ سقفِ درخواست روی ستونِ `phone` است نه `user_id`، پس
--      nullable شدنِ این ستون هیچ سدی را باز نمی‌کند.
--
-- ایمن برای اجرای دوباره: هر `ALTER` فقط وقتی اجرا می‌شود که ستون
-- هنوز `NOT NULL` باشد. بدون این شرط، هر اجرا کلِ جدول را بازسازی
-- می‌کرد — درست ولی گران.
-- ============================================================

-- ---------- users.password_hash می‌تواند NULL باشد ----------
SET @pw_notnull = (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users'
      AND COLUMN_NAME = 'password_hash' AND IS_NULLABLE = 'NO'
);
SET @sql = IF(@pw_notnull = 1,
    'ALTER TABLE `users` MODIFY COLUMN `password_hash` VARCHAR(255) NULL DEFAULT NULL COMMENT ''NULL = این حساب رمز ندارد (ثبت‌نام با شماره)''',
    'SELECT ''users.password_hash از قبل NULL می‌پذیرد'' AS skipped'
);
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- ---------- sms_codes.user_id می‌تواند NULL باشد ----------
SET @has_sms = (
    SELECT COUNT(*) FROM information_schema.TABLES
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'sms_codes'
);
SET @uid_notnull = (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'sms_codes'
      AND COLUMN_NAME = 'user_id' AND IS_NULLABLE = 'NO'
);
SET @sql = IF(@has_sms = 1 AND @uid_notnull = 1,
    'ALTER TABLE `sms_codes` MODIFY COLUMN `user_id` INT UNSIGNED NULL DEFAULT NULL COMMENT ''NULL = کدِ ثبت‌نام؛ هنوز حسابی وجود ندارد''',
    'SELECT ''sms_codes.user_id نیازی به تغییر ندارد'' AS skipped'
);
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SELECT 'migration_phone_signup با موفقیت اجرا شد.' AS result;
