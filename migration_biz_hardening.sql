-- ============================================================
-- محیطِ فروشگاهی — سخت‌سازی پیش از عرضه به فروشگاه‌ها
-- ============================================================
--
-- **خواسته‌ی مالکِ نصب:** «بخشِ فروشگاهی را بررسی کن … می‌خوام سیستم بی‌نقص
-- باشه در همه‌جا برای وارد شدن به معامله‌ی واقعی.» (بازرسیِ مهر ۱۴۰۵)
--
-- ⛔ `first_issued_at` — جای سند در سرگذشتِ هر گوشی (`BizSerial`).
--    سرگذشتِ IMEI به ترتیبِ صدور «تا» می‌شود. تا امروز ترتیب از `issued_at`
--    بود و «برگشت به پیش‌نویس ← صدورِ دوباره» آن را به **اکنون** می‌برد: خریدی
--    که برای اصلاحِ قیمت دوباره صادر شد، از فروشِ همان گوشی جلو می‌افتاد و
--    گوشیِ فروخته‌شده دوباره «در انبار» دیده می‌شد — دو بار فروختنی.
--    `first_issued_at` فقط در **اولین** صدور نوشته می‌شود و دیگر عوض نمی‌شود.
--
-- ایدمپوتنت.

ALTER TABLE `biz_invoices`
    ADD COLUMN IF NOT EXISTS `first_issued_at` DATETIME NULL DEFAULT NULL
        COMMENT 'اولین صدور؛ ترتیبِ سرگذشتِ IMEI — BizSerial';

UPDATE `biz_invoices`
   SET `first_issued_at` = `issued_at`
 WHERE `first_issued_at` IS NULL AND `issued_at` IS NOT NULL;

INSERT INTO `app_settings` (`setting_key`, `setting_value`)
VALUES ('biz_hardening_seeded', '1')
ON DUPLICATE KEY UPDATE `setting_key` = `setting_key`;

SELECT 'migration_biz_hardening با موفقیت اجرا شد.' AS result;
