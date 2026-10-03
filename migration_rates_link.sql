-- ============================================================
-- نوع‌های داراییِ پیش‌فرض ⇐ نرخِ روز (برای کاربرانِ موجود)
-- ============================================================
--
-- **خواسته‌ی مالکِ نصب:** «وقتی دارایی طلا یا دلار می‌ذاره به تومن در لحظه
-- حساب بشه.» کاربرِ تازه نوع‌های پیش‌فرض را وصل‌شده می‌گیرد
-- (`defaultAssetTypes()`)؛ این فایل همان را برای کاربرانِ **موجود** می‌کند.
--
-- ⛔ فقط سه نوعِ پیش‌فرض، با **نام و واحدِ دقیق** («دلار»/«دلار»،
--    «طلا (۱۸ عیار)»/«گرم»، «سکه تمام»/«عدد») و فقط آن‌هایی که هنوز وصل
--    نیستند. نوعی که کاربر خودش ساخته یا واحدش را عوض کرده دست نمی‌خورد —
--    «طلا» با واحدِ مثقال به گرمِ ۱۸ وصل می‌شد و ارزش چهار برابر غلط درمی‌آمد.
-- ⛔ رشته‌ی ثابت، نه متغیرِ کاربری (درسِ collation در CLAUDE.md).
-- ⚠ نشانه (`asset_rates_linked`) عمداً خودِ داده نیست: کاربری که بعداً وصل را
--   قطع کند، با اجرای دوباره دوباره وصل نمی‌شود. ایدمپوتنت.

UPDATE `asset_types` SET `rate_code` = 'usd'
 WHERE `rate_code` IS NULL AND `name` = 'دلار' AND `unit` = 'دلار'
   AND NOT EXISTS (SELECT 1 FROM `app_settings` WHERE `setting_key` = 'asset_rates_linked');
UPDATE `asset_types` SET `rate_code` = 'gold18'
 WHERE `rate_code` IS NULL AND `name` = 'طلا (۱۸ عیار)' AND `unit` = 'گرم'
   AND NOT EXISTS (SELECT 1 FROM `app_settings` WHERE `setting_key` = 'asset_rates_linked');
UPDATE `asset_types` SET `rate_code` = 'coin_emami'
 WHERE `rate_code` IS NULL AND `name` = 'سکه تمام' AND `unit` = 'عدد'
   AND NOT EXISTS (SELECT 1 FROM `app_settings` WHERE `setting_key` = 'asset_rates_linked');

-- قیمتِ هر واحد همین حالا از نرخی که هست (بقیه با دریافتِ بعدی، `Rates::applyToAssets()`)
UPDATE `asset_types` `at` JOIN `market_rates` `r` ON `r`.`code` = `at`.`rate_code`
   SET `at`.`current_price` = `r`.`price`, `at`.`price_updated_at` = DATE(`r`.`fetched_at`)
 WHERE `at`.`rate_code` IS NOT NULL;

INSERT INTO `app_settings` (`setting_key`, `setting_value`)
VALUES ('asset_rates_linked', '1')
ON DUPLICATE KEY UPDATE `setting_value` = '1';
