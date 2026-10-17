-- ============================================================
-- migration_biz_sayad.sql — شناسه‌ی صیادِ چک (مهر ۱۴۰۵)
--
-- خواسته‌ی مالکِ نصب: کنارِ هر چک شناسه‌ی ۱۶ رقمیِ صیاد ثبت شود و یادآوری شود که چکِ دریافتی در
-- سامانه‌ی صیاد «تأیید» (و چکِ پرداختی «ثبت») شده یا نه — چکِ ثبت‌نشده در صیاد قابلِ وصول نیست.
--
-- ⛔ «تأیید شد» فقط یک علامتِ دستیِ فروشنده است (`BizCheques::confirmSayad()`)؛ برنامه به سامانه‌ی صیاد
--    وصل نمی‌شود و هرگز خودش چیزی را «تأییدشده» نمی‌خواند.
-- ایدمپوتنت (IF NOT EXISTS).
-- ============================================================

ALTER TABLE `biz_payments`
    ADD COLUMN IF NOT EXISTS `cheque_sayad`    CHAR(16) NULL DEFAULT NULL COMMENT 'شناسه‌ی ۱۶ رقمیِ صیاد',
    ADD COLUMN IF NOT EXISTS `cheque_sayad_ok` TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'در سامانه‌ی صیاد تأیید/ثبت شد (علامتِ دستی)';

ALTER TABLE `biz_payments`
    ADD INDEX IF NOT EXISTS `idx_biz_pay_sayad` (`user_id`, `cheque_sayad`);

SELECT 'migration_biz_sayad با موفقیت اجرا شد.' AS result;
