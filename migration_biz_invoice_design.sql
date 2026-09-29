-- ============================================================
-- محیطِ فروشگاهی — طراحیِ فاکتور و لوگوی فروشگاه
-- ============================================================
--
-- **خواسته‌ی مالکِ نصب:** «برای بخش چاپ فاکتور امکان دیزاین فاکتور کامل
-- بذار تا کاربر بتونه فاکتور خودشو بسازه با لوگوی خودش.»
--
-- ۱. `biz_settings.invoice_design` — یک JSON (قالب، رنگ، جای لوگو، ستون‌ها،
--    بخش‌ها، عنوان و متنِ شرایط). معنا و پیش‌فرض فقط در
--    `BizInvoiceDesign::DEFAULTS` است؛ `NULL` یعنی «هنوز طراحی نشده» و
--    فاکتور همان ظاهرِ قبلی را دارد.
--
-- ۲. `biz_logos` — لوگوی هر فروشگاه، **جدولِ جدا**: `Biz::settings()` روی
--    هر صفحه‌ی فروشگاه `SELECT *` می‌زند و چند ده کیلوبایت تصویر نباید با
--    آن بیاید. تصویر پس از بازسازی با GD (نه فایلِ خامِ کاربر) به‌صورتِ
--    base64 نگه داشته می‌شود: بی‌پوشه‌ی آپلود و بی‌دسترسیِ فایل، در بکاپ و
--    خروجیِ حساب می‌آید، و با حذفِ حساب (CASCADE) می‌رود. روی برگه با
--    `data:` می‌نشیند که CSPِ `img-src 'self' data:` می‌پذیرد.
--    ⚠ `user_id` از نوعِ `INT UNSIGNED` (تله‌ی errno 150).
--
-- ایدمپوتنت.

CREATE TABLE IF NOT EXISTS `biz_logos` (
    `user_id`    INT UNSIGNED NOT NULL,
    `mime`       VARCHAR(20)  NOT NULL COMMENT 'image/png | image/jpeg — BizInvoiceDesign::LOGO_MIMES',
    `data`       MEDIUMTEXT   NOT NULL COMMENT 'base64ِ تصویرِ بازسازی‌شده',
    `width`      SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    `height`     SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`user_id`),
    CONSTRAINT `fk_biz_logos_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`)
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_persian_ci;

ALTER TABLE `biz_settings`
    ADD COLUMN IF NOT EXISTS `invoice_design` VARCHAR(3000) NULL DEFAULT NULL
        COMMENT 'طراحیِ فاکتور (JSON) — BizInvoiceDesign::get()';
