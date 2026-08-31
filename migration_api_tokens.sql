-- ---------------------------------------------------------------
-- توکن‌های API برای اپ‌های موبایل (اندروید، iOS، و هر مشتری دیگر)
--
-- چرا توکن و نه همان نشست و کوکی:
--   نشست PHP به کوکی و به توکن CSRF گره خورده است. هر دو برای مرورگر
--   ساخته شده‌اند: کوکی خودکار فرستاده می‌شود (پس CSRF لازم می‌شود) و
--   عمرشان را سرور تعیین می‌کند. یک اپ موبایل کوکی خودکار ندارد، پس
--   CSRF هم بی‌معنی است، و باید بتواند ماه‌ها وارد بماند بی‌آنکه
--   مهلتِ نشستِ وب رویش اثر بگذارد.
--
-- الگوی selector/validator — همان که در trusted_devices و
-- password_resets استفاده شده و اینجا هم عمداً تکرار می‌شود:
--   • selector  → فقط برای پیدا کردن ردیف، ایندکس یکتا دارد
--   • validator → هرگز خام ذخیره نمی‌شود، فقط sha256 آن
-- با این کار حتی اگر کسی جدول را بخواند نمی‌تواند توکن بسازد، و
-- مقایسه هم زمان‌ثابت است چون جست‌وجو با selector انجام می‌شود نه با
-- خودِ راز.
-- ---------------------------------------------------------------

CREATE TABLE IF NOT EXISTS `api_tokens` (
    `id`           INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id`      INT UNSIGNED NOT NULL,
    `selector`     CHAR(24)     NOT NULL,
    `token_hash`   CHAR(64)     NOT NULL,
    -- نامی که خودِ دستگاه می‌فرستد؛ فقط برای اینکه کاربر در فهرست
    -- دستگاه‌ها بفهمد کدام است. هرگز به آن اعتماد نمی‌شود.
    `device_label` VARCHAR(80)      NULL,
    `platform`     VARCHAR(20)      NULL,   -- android / ios / web / ...
    `last_used_at` DATETIME         NULL,
    `expires_at`   DATETIME     NOT NULL,
    `revoked_at`   DATETIME         NULL,
    `created_at`   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_api_tokens_selector` (`selector`),
    KEY `idx_api_tokens_user` (`user_id`),
    KEY `idx_api_tokens_expires` (`expires_at`),
    CONSTRAINT `fk_api_tokens_user`
        FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
