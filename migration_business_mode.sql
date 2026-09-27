-- ============================================================
-- محیطِ فروشگاهی (Business Mode) — مرحله‌ی ۱: جداسازی
-- ============================================================
--
-- ⛔ فقط افزودنی. هیچ ردیف و هیچ ستونِ موجودی عوض نمی‌شود، پس بعد از
--    این فایل همه‌ی حساب‌ها دقیقاً همان «شخصی»ِ قبلی‌اند.
--
-- ۱. `biz_settings` — سربرگِ فاکتورِ هر حسابِ فروشگاهی.
--    ⚠ `user_id` از نوعِ `INT UNSIGNED` است، هم‌نوعِ `users.id`؛ با `INT`
--      خام، MySQL کلیدِ خارجی را با errno 150 رد می‌کند و فایل وسطِ کار
--      می‌ایستد (همان تله‌ی `store_shareholders`).
--
-- ۲. `users.account_type` — `personal` (پیش‌فرض) یا `business`.
--    `VARCHAR` است نه `ENUM`، همان دلیلِ `users.role`: نوعِ تازه‌ی فردا
--    نباید `ALTER TABLE` بخواهد.
--    ⛔ آخرین دستورِ فایل است و شاهدِ `migrate.sh` هم همین ستون است، پس
--       وجودش یعنی کلِ فایل اجرا شده.
--
-- ایدمپوتنت: اجرای دوباره هیچ کاری نمی‌کند.

CREATE TABLE IF NOT EXISTS `biz_settings` (
    `user_id`        INT UNSIGNED NOT NULL,
    `shop_name`      VARCHAR(120) NOT NULL DEFAULT '',
    `phone`          VARCHAR(40)  NULL DEFAULT NULL,
    `address`        VARCHAR(300) NULL DEFAULT NULL,
    `invoice_footer` VARCHAR(500) NULL DEFAULT NULL,
    `created_at`     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`user_id`),
    CONSTRAINT `fk_biz_settings_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`)
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_persian_ci;

ALTER TABLE `users`
    ADD COLUMN IF NOT EXISTS `account_type` VARCHAR(20) NOT NULL DEFAULT 'personal'
        COMMENT 'personal = حساب لندِ شخصی، business = محیطِ فروشگاهی (/store)';
