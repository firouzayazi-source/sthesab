-- ============================================================
-- ثبتِ پیامکِ بانک در پس‌زمینه‌ی اپ اندروید — بی‌باز کردنِ اپ
-- ============================================================
--
-- **گزارشِ مالکِ نصب (مهر ۱۴۰۵):** «اپ اندروید هنوز نمی‌تونه اس‌ام‌اس‌های
-- بانکی رو بخونه … خودش بذاره روی حساب در حساب‌لند». تا امروز پیامک فقط
-- وقتی ثبت می‌شد که کاربر اپ را باز می‌کرد (پارسر در صفحه‌ی وب بود).
--
-- چهار چیز، و **هیچ‌کدام متنِ پیامک را نگه نمی‌دارد**:
--   ۱. `api_tokens.scope` — توکنِ اپ برای پیامک فقط `sms` است: با آن فقط
--      `api/v1/sms/*` باز می‌شود، نه فهرستِ تراکنش‌ها یا حذف (کمترین دسترسی).
--   ۲. `sms_links` — جفت شدنِ گوشی با حساب: اپ یک کدِ تصادفی می‌سازد، صفحه‌ی
--      واردشده آن را به کاربرِ نشست می‌بندد، و اپ با همان کد توکن را
--      می‌گیرد. فقط هشِ کد ذخیره می‌شود؛ یک‌بارمصرف و ده‌دقیقه‌ای.
--   ۳. `sms_posted` — نگهبانِ تکرار در سرور: اثرِ انگشتِ ۳۲ بیتیِ پیامک
--      (FNV-1a، برگشت‌ناپذیر) تا پاسخِ گم‌شده و تلاشِ دوباره تراکنشِ دوم نسازد.
--   ۴. `wallets.sms_balance_at` — زمانِ پیامکی که آخرین بار «مانده»ی حساب را
--      گذاشت؛ پیامکِ قدیمی‌تر مانده‌ی تازه‌تر را بازنویسی نمی‌کند.
--
-- ایدمپوتنت. شاهد: جدولِ `sms_posted`.

ALTER TABLE `api_tokens`
    ADD COLUMN IF NOT EXISTS `scope` VARCHAR(16) NULL DEFAULT NULL
        COMMENT 'NULL = دسترسیِ کاملِ api/v1؛ sms = فقط api/v1/sms/*';

ALTER TABLE `wallets`
    ADD COLUMN IF NOT EXISTS `sms_balance_at` DATETIME NULL DEFAULT NULL
        COMMENT 'زمانِ پیامکی که آخرین بار مانده را گذاشت — SmsSync';

CREATE TABLE IF NOT EXISTS `sms_links` (
    `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id`    INT UNSIGNED NOT NULL,
    `nonce_hash` CHAR(64)     NOT NULL,
    `expires_at` DATETIME     NOT NULL,
    `claimed_at` DATETIME         NULL,
    `created_at` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_sms_links_nonce` (`nonce_hash`),
    KEY `idx_sms_links_user` (`user_id`),
    CONSTRAINT `fk_sms_links_user`
        FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_persian_ci;

CREATE TABLE IF NOT EXISTS `sms_posted` (
    `id`             INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id`        INT UNSIGNED NOT NULL,
    `fp`             CHAR(8)      NOT NULL,
    `transaction_id` INT UNSIGNED     NULL,
    `created_at`     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_sms_posted_user_fp` (`user_id`, `fp`),
    KEY `idx_sms_posted_created` (`created_at`),
    CONSTRAINT `fk_sms_posted_user`
        FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_persian_ci;
