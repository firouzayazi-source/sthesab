-- ============================================================
-- محیطِ فروشگاهی — دفترِ چک (دریافتی و پرداختی)
-- ============================================================
--
-- **خواسته‌ی مالکِ نصب:** «حداقل خواسته‌هایی که مدیریتِ فروشگاه دارد» — از
-- روی منوی «بانکداری ← فهرستِ چک‌های دریافتی/پرداختی» و «اعلاناتِ سررسیدِ
-- چک»ِ حسابفا.
--
-- ⛔ چک جدولِ جدا ندارد: یک «دریافت» یا «پرداخت» با روشِ `cheque` است که پولش
--    در یک صندوقِ نگه‌دارنده می‌نشیند (`biz_accounts.kind` = `cheque_in` یا
--    `cheque_out`، `BizCash::chequeAccount()`)؛ وصول یک «انتقال» از آن صندوق به
--    بانک است و برگشت ابطالِ همان دریافت/پرداخت. پس مانده‌ی طرف‌حساب،
--    موجودیِ صندوق و گزارش‌ها هیچ حسابِ تازه‌ای نمی‌خواهند.
--
-- `cheque_status` = NULL یعنی «این سند چکِ دفتر نیست» (همه‌ی سندهای قدیمی،
-- حتی آن‌ها که روشِ «چک» داشتند) — هیچ ردیفِ موجودی عوض نمی‌شود.
-- ⛔ `cheque_settle_id` روی **انتقالِ وصول** می‌نشیند و به چک اشاره می‌کند، نه
--    برعکس: انتقال همیشه بعد از چک ساخته می‌شود، پس ارجاع رو به عقب است و
--    بازگرداندنِ بکاپ (`user_import.php`، که شناسه‌ها را فقط از روی کلیدِ
--    خارجی و به ترتیبِ درج نگاشت می‌کند) آن را درست به چکِ تازه وصل می‌کند.
--    ارجاعِ رو به جلو در آن لحظه هنوز نگاشتی نداشت و به ردیفِ دیگری می‌خورد.
--    `SET NULL` نه `RESTRICT` — همان درسِ `ref_invoice_id`: `DELETE`ِ
--    چندردیفیِ `deleteUserAccount()` روی یک جدول با RESTRICT می‌شکست.
-- ⛔ شاهدِ `migrate.sh` ستونِ `biz_payments.cheque_status` است. ایدمپوتنت.

ALTER TABLE `biz_payments`
    ADD COLUMN IF NOT EXISTS `cheque_no`        VARCHAR(30) NULL DEFAULT NULL COMMENT 'شماره‌ی چک (صیادی یا سری)',
    ADD COLUMN IF NOT EXISTS `cheque_bank`      VARCHAR(60) NULL DEFAULT NULL COMMENT 'بانکِ صادرکننده',
    ADD COLUMN IF NOT EXISTS `cheque_due`       DATE NULL DEFAULT NULL COMMENT 'سررسید',
    ADD COLUMN IF NOT EXISTS `cheque_settle_id` INT UNSIGNED NULL DEFAULT NULL COMMENT 'روی انتقالِ وصول: چکی که وصول می‌کند',
    ADD COLUMN IF NOT EXISTS `cheque_status`    VARCHAR(10) NULL DEFAULT NULL COMMENT 'pending / cleared / bounced — NULL = چکِ دفتر نیست';

ALTER TABLE `biz_payments`
    ADD INDEX IF NOT EXISTS `idx_biz_payments_cheque` (`user_id`, `cheque_status`, `cheque_due`),
    ADD INDEX IF NOT EXISTS `idx_biz_payments_settle` (`cheque_settle_id`);

ALTER TABLE `biz_payments`
    ADD CONSTRAINT `fk_biz_payments_settle` FOREIGN KEY IF NOT EXISTS (`cheque_settle_id`)
        REFERENCES `biz_payments` (`id`) ON DELETE SET NULL;
