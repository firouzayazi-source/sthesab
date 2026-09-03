-- ============================================================
-- migration_sms_login.sql — ورود با کد پیامکی
--
-- ⛔ این قابلیت پیش‌فرض **خاموش** است و فقط مدیر روشنش می‌کند
--    (`app_settings.allow_sms_login`). این migration فقط ساختار را
--    می‌سازد؛ ساختنِ ستون و جدول هیچ راهِ ورودِ تازه‌ای باز نمی‌کند.
--
-- ⛔ چرا کد در دیتابیس **هش** می‌شود و خام نه:
--    یک کدِ ۵ رقمی خودش کوتاه است؛ اگر خام ذخیره شود، هر کسی که یک بار
--    به دیتابیس یا به بکاپ برسد می‌تواند در همان چند دقیقه با آن وارد
--    شود. همان الگوی `api_tokens` و `password_resets`: فقط sha256.
--
-- ⛔ `attempts` روی خودِ ردیفِ کد است، نه فقط شمارشِ کلی.
--    ۵ رقم یعنی ۱۰۰٬۰۰۰ حالت. بدون سقفِ تلاش روی همان کد، ورود با
--    پیامک از ورود با رمز **ضعیف‌تر** می‌شد: مهاجم شماره‌ی قربانی را
--    می‌داند، یک کد درخواست می‌دهد و بعد آزادانه حدس می‌زند. این
--    مهم‌ترین محافظِ کلِ این قابلیت است.
--
-- ⛔ `used_at` یعنی کد یک‌بارمصرف است. بدون آن، کدی که کاربر استفاده
--    کرده تا آخرِ پنجره‌ی ۳ دقیقه‌ای برای هر کسی که متنِ پیامک را دیده
--    باشد کار می‌کرد.
--
-- `phone` روی `users` است و **یکتا**: دو حساب با یک شماره یعنی کدِ
-- فرستاده‌شده معلوم نیست مالِ کدام است. یکتاییِ شرطی (`UNIQUE` با
-- `NULL` های متعدد) در MySQL کار می‌کند، پس حساب‌های بی‌شماره‌ی موجود
-- دست‌نخورده می‌مانند.
--
-- ایمن برای اجرای دوباره.
-- ============================================================

-- ---------- users.phone ----------
SET @has_phone = (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'phone'
);
SET @sql = IF(@has_phone = 0,
    'ALTER TABLE `users` ADD COLUMN `phone` VARCHAR(20) NULL DEFAULT NULL COMMENT ''شماره موبایل نرمال‌شده: 09xxxxxxxxx''',
    'SELECT ''users.phone از قبل هست'' AS skipped'
);
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @has_idx = (
    SELECT COUNT(*) FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND INDEX_NAME = 'uniq_users_phone'
);
SET @sql = IF(@has_idx = 0,
    'ALTER TABLE `users` ADD UNIQUE KEY `uniq_users_phone` (`phone`)',
    'SELECT ''uniq_users_phone از قبل هست'' AS skipped'
);
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- ---------- sms_codes ----------
CREATE TABLE IF NOT EXISTS `sms_codes` (
    `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id`    INT UNSIGNED NOT NULL,
    `phone`      VARCHAR(20)  NOT NULL COMMENT 'همان شماره‌ی نرمال‌شده که پیامک به آن رفت',
    `code_hash`  CHAR(64)     NOT NULL COMMENT 'sha256 کدِ خام؛ خودِ کد هرگز ذخیره نمی‌شود',
    `attempts`   TINYINT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'تلاشِ ناموفق روی همین کد',
    `used_at`    DATETIME     NULL DEFAULT NULL COMMENT 'یک‌بارمصرف: پرشدنش یعنی سوخته',
    `expires_at` DATETIME     NOT NULL,
    `request_ip` VARCHAR(45)  NOT NULL DEFAULT '',
    `created_at` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_user_created` (`user_id`, `created_at`),
    KEY `idx_phone_created` (`phone`, `created_at`),
    KEY `idx_expires` (`expires_at`),
    CONSTRAINT `fk_sms_codes_user` FOREIGN KEY (`user_id`)
        REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_persian_ci;

SELECT 'migration_sms_login با موفقیت اجرا شد.' AS result;
