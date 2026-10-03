-- ---------------------------------------------------------------
-- migration_store_share_edit.sql — سودِ فروشگاه، قابلِ اصلاح در دفترِ شخصی
--
-- **خواسته‌ی مالکِ نصب:** «سمتِ سهامدارِ فروشگاه توی حساب لند به خوبی سودش
-- میاد … اولندش امکانِ ویرایشِ سود بیاد، ممکنه سود تغییر کرده باشه … و
-- حتما هم باید قابل تغییر باشه موارد.»
--
-- ─── ⛔ چرا دو ستون ───
--
-- تا امروز سطرِ سود را می‌شد ویرایش کرد، ولی همگام‌سازیِ بعدی (هر چند
-- دقیقه) با `ON DUPLICATE KEY UPDATE` همان مبلغِ فروشگاه را دوباره
-- می‌نوشت — اصلاحِ کاربر **بی‌صدا** پس گرفته می‌شد.
--
--   store_share_edited  ۱ = کاربر این سطر را اصلاح کرده؛ همگام‌سازی دیگر
--                       مبلغ، نوع، عنوان، تاریخ و توضیحش را نمی‌نویسد.
--   store_share_origin  آخرین عددی که فروشگاه برای همین سطر گفته (علامت‌دار)،
--                       تا کنارِ عددِ اصلاح‌شده دیده شود و «برگرداندن به
--                       عددِ فروشگاه» ممکن باشد. `NULL` = فروشگاه دیگر این
--                       سطر را ندارد (فاکتور حذف شد)؛ سطرِ اصلاح‌شده آن‌وقت
--                       پاک **نمی‌شود** — دادهٔ کاربر است — فقط نشان می‌خورد.
--
-- ایدمپوتنت است.
-- ---------------------------------------------------------------

ALTER TABLE `transactions`
    ADD COLUMN IF NOT EXISTS `store_share_origin` BIGINT NULL DEFAULT NULL
    COMMENT 'آخرین عددِ فروشگاه برای این سطر (علامت‌دار)؛ NULL یعنی دیگر در فروشگاه نیست'
    AFTER `store_share_ref`;

-- ⚠ ستونِ آخرِ فایل پیش از نشانه: شاهدِ `--verify` روی نشانه است.
ALTER TABLE `transactions`
    ADD COLUMN IF NOT EXISTS `store_share_edited` TINYINT(1) NOT NULL DEFAULT 0
    COMMENT '۱ یعنی کاربر سطرِ سودِ فروشگاه را اصلاح کرده و همگام‌سازی آن را نمی‌نویسد'
    AFTER `store_share_origin`;

-- ⚠ عددِ فروشگاه برای سطرهای موجود همان مبلغِ امروزشان است (هنوز هیچ‌کدام
--   اصلاح نشده‌اند، چون اصلاح تا امروز ماندگار نبود).
UPDATE `transactions`
   SET `store_share_origin` = IF(`type` = 'income', `amount`, -CAST(`amount` AS SIGNED))
 WHERE `store_share_ref` IS NOT NULL AND `store_share_origin` IS NULL AND `store_share_edited` = 0;

INSERT INTO `app_settings` (`setting_key`, `setting_value`)
VALUES ('store_share_edit_seeded', '1')
ON DUPLICATE KEY UPDATE `setting_key` = `setting_key`;

SELECT 'migration_store_share_edit با موفقیت اجرا شد.' AS result;
