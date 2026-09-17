-- migration_audit_log.sql — دفترِ رویدادهای امنیتی و مدیریتی
--
-- ⛔ «چه کسی، کِی، از کجا، چه کاری کرد» — و نه «چه چیزی». فقط رویدادهای
--    امنیتی/مدیریتی (ورود، تغییر رمز، ابطال دسترسی، ساخت/حذف/غیرفعال‌سازی
--    کاربر، تغییر تنظیمات، هدیه/تأیید اشتراک، خروجی/بازگرداندنِ کلِ
--    داده). کارِ روزمره‌ی کاربر با دفترِ خودش (تراکنش، چک، بودجه) اینجا
--    ثبت نمی‌شود — آن همان «ردِ رفتاری» است که `admin/insights.php`
--    عمداً از آن پرهیز کرده.
--
-- ⛔ ستونِ کاربر `actor_id` است نه `user_id`: `userDataTables()` هر جدولِ
--    `user_id`دار را هنگامِ حذفِ حساب می‌برد، و ردیفِ `account.deleted`
--    باید بعد از حذف هم بماند. کلیدِ خارجی هم به همین دلیل ندارد.
--    ردیف‌ها ۹۰ روز می‌مانند (`Audit::KEEP_DAYS`) و بعد پاک می‌شوند.
--
-- ⛔ `detail` کوتاه و شسته است (`Log::redact()`): نه مبلغ، نه عنوان، نه
--    ایمیل، نه رمز، نه شماره کارت.

CREATE TABLE IF NOT EXISTS `audit_log` (
    `id`             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `created_at`     DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `request_id`     VARCHAR(64)     NOT NULL DEFAULT '',
    `actor_id`       INT UNSIGNED    NULL,
    `target_user_id` INT UNSIGNED    NULL,
    `action`         VARCHAR(60)     NOT NULL,
    `entity`         VARCHAR(40)     NULL,
    `entity_id`      BIGINT UNSIGNED NULL,
    `ip`             VARCHAR(45)     NULL,
    `detail`         VARCHAR(500)    NULL,
    PRIMARY KEY (`id`),
    KEY `idx_audit_created` (`created_at`),
    KEY `idx_audit_actor`   (`actor_id`, `created_at`),
    KEY `idx_audit_action`  (`action`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_persian_ci;
