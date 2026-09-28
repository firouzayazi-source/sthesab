-- ============================================================
-- محیطِ فروشگاهی — اطلاعاتِ رسمیِ کسب‌وکار، گزینه‌های فاکتور، کدهای خریدار
-- ============================================================
--
-- **خواسته‌ی مالکِ نصب:** «برای بخشِ فروشگاهی با حداقل خواسته‌هایی که مدیریتِ
-- فروشگاه دارد» — از روی منوهای حسابفا: «اطلاعاتِ کسب‌وکار» (شناسه‌ی ملی، کد
-- اقتصادی، شماره‌ی ثبت، کد پستی) و تبِ «فاکتور»ِ تنظیماتِ مالی.
--
-- ۱. `biz_settings` — چهار کدِ رسمیِ فروشگاه (روی سربرگِ فاکتور) و
--    `invoice_prefs`: یک JSON مثلِ `print_prefs` — گزینه‌ی تازه‌ی فردا
--    `ALTER TABLE` نمی‌خواهد و مقدارِ ناشناخته به پیش‌فرض برمی‌گردد.
-- ۲. `biz_parties` — سه کدِ خریدار (فاکتورِ رسمی بدونِ کدِ اقتصادیِ خریدار
--    به کارِ حسابداریِ او نمی‌آید).
--
-- همه‌ی ستون‌ها nullable‌اند: هیچ نصبی با این فایل رفتارش عوض نمی‌شود.
-- ⛔ شاهدِ `migrate.sh` ستونِ `biz_parties.economic_code` است — آخرین دستور.
-- ایدمپوتنت.

ALTER TABLE `biz_settings`
    ADD COLUMN IF NOT EXISTS `national_id`   VARCHAR(20) NULL DEFAULT NULL COMMENT 'شناسه‌ی ملی / کد ملی',
    ADD COLUMN IF NOT EXISTS `economic_code` VARCHAR(20) NULL DEFAULT NULL COMMENT 'کد اقتصادی',
    ADD COLUMN IF NOT EXISTS `reg_no`        VARCHAR(20) NULL DEFAULT NULL COMMENT 'شماره‌ی ثبت',
    ADD COLUMN IF NOT EXISTS `postal_code`   VARCHAR(20) NULL DEFAULT NULL COMMENT 'کد پستی',
    ADD COLUMN IF NOT EXISTS `invoice_prefs` VARCHAR(400) NULL DEFAULT NULL COMMENT 'JSON — Biz::invoicePrefs()';

ALTER TABLE `biz_parties`
    ADD COLUMN IF NOT EXISTS `national_id`   VARCHAR(20) NULL DEFAULT NULL COMMENT 'شناسه‌ی ملی / کد ملی',
    ADD COLUMN IF NOT EXISTS `postal_code`   VARCHAR(20) NULL DEFAULT NULL COMMENT 'کد پستی',
    ADD COLUMN IF NOT EXISTS `economic_code` VARCHAR(20) NULL DEFAULT NULL COMMENT 'کد اقتصادی';
