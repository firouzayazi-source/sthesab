-- ============================================================
-- سندِ جفت در دریافت/پرداختِ فروشگاه — `biz_payments.pair_id`
-- ============================================================
--
-- ⛔ دو جفتِ سند که تا امروز به هم پیوند نداشتند:
--
--   ۱. **حقوق با کسرِ مساعده** (`BizPayroll::paySalary()`): هزینه‌ی حقوق و دریافتِ
--      «کسرِ مساعده از حقوق» در یک تراکنش ساخته می‌شوند، ولی جدا باطل می‌شدند.
--      ابطالِ فقط هزینه، صندوق را ۲٬۰۰۰ بیشتر و مساعده‌ی کارمند را «تسویه» نشان
--      می‌داد در حالی که هنوز باز بود (بازرسیِ مهر ۱۴۰۵). حالا کسر به حقوق اشاره
--      می‌کند و با هم باطل می‌شوند.
--
--   ۲. **برگشتیِ چکی که دریافتش در دوره‌ی بسته است** (`BizCheques::bounce()`):
--      برگشتی خودِ دریافت را باطل می‌کرد، پس چکِ دوره‌ی بسته دیگر برگشتی نمی‌شد.
--      حالا سندِ معکوسی با تاریخِ روزِ برگشت ساخته می‌شود که به چک اشاره می‌کند؛
--      ابطالِ همان سند، برگشتی را پس می‌گیرد.
--
-- ⚠ کلیدِ خارجی به خودِ جدول (مثلِ `cheque_settle_id`)، پس بازگردانیِ بکاپ پیوند
--   را خودش نگاشت می‌کند (`importForeignKeys()`).
--
-- ایدمپوتنت.

ALTER TABLE `biz_payments`
    ADD COLUMN IF NOT EXISTS `pair_id` INT UNSIGNED NULL DEFAULT NULL
        COMMENT 'سندِ جفت: کسرِ مساعده → حقوق، معکوسِ برگشتی → چک';

ALTER TABLE `biz_payments`
    ADD INDEX IF NOT EXISTS `idx_biz_payments_pair` (`pair_id`);

ALTER TABLE `biz_payments`
    ADD CONSTRAINT `fk_biz_payments_pair` FOREIGN KEY IF NOT EXISTS (`pair_id`)
        REFERENCES `biz_payments` (`id`) ON DELETE SET NULL;

-- جفت‌های قدیمیِ حقوق: کسر (دریافت با همین عنوانِ ثابت) و نزدیک‌ترین هزینه‌ی پیش از
-- آن برای همان کارمند، همان روز و همان صندوق — دقیقاً همان دو ردیفی که
-- `paySalary()` پشتِ سرِ هم در یک تراکنش می‌سازد. فقط ردیف‌های بی‌جفت.
UPDATE `biz_payments` r
  JOIN (SELECT r2.id AS rid,
               (SELECT MAX(e.id) FROM `biz_payments` e
                 WHERE e.user_id = r2.user_id AND e.party_id = r2.party_id AND e.pay_date = r2.pay_date
                   AND e.account_id = r2.account_id AND e.kind = 'expense' AND e.id < r2.id) AS eid
          FROM `biz_payments` r2
         WHERE r2.kind = 'receipt' AND r2.title = 'کسرِ مساعده از حقوق' AND r2.pair_id IS NULL) m ON m.rid = r.id
   SET r.pair_id = m.eid
 WHERE m.eid IS NOT NULL;

INSERT INTO `app_settings` (`setting_key`, `setting_value`)
VALUES ('biz_pair_seeded', '1')
ON DUPLICATE KEY UPDATE `setting_key` = `setting_key`;

SELECT 'migration_biz_pair با موفقیت اجرا شد.' AS result;
