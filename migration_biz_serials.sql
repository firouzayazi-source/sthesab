-- ============================================================
-- محیطِ فروشگاهی — گوشی با IMEI (فروشگاهِ موبایل و لوازم جانبی)
-- ============================================================
--
-- **خواسته‌ی مالکِ نصب:** «در بخشِ ورودِ کالای جدید، با خریدِ جدید یا وارد
-- کردنِ کالاها به انبار، باید امکانِ تعریفِ محصول، قیمت، و اضافه کردنِ کدِ
-- IMEI یک و دوم برای گوشی‌ها… و فاکتور باید هوشمند باشد: با IMEI یا نامِ
-- گوشی سرچ شود.»
--
-- ⛔ فقط افزودنی و فقط `biz_*`. هیچ جدولِ حساب لندِ شخصی دست نمی‌خورد.
--
-- ۱. `biz_products.has_serial` — «گوشی» است؟ (هر واحدش IMEI دارد). نوعِ
--    کالا از دو ستون ساخته می‌شود و ستونِ سومی لازم نیست:
--    خدمت = `track_stock = 0`، گوشی = `has_serial = 1`، بقیه = کالا/لوازم.
-- ۲. `biz_invoice_lines.imei1` / `imei2` — ⛔ **جدولِ جدای «واحد» ساخته
--    نشد.** وضعیتِ هر گوشی (در انبار یا فروخته) از **ردیف‌های اسنادِ
--    صادرشده** درمی‌آید: آخرین حرکتِ باطل‌نشده‌ی آن IMEI. پس ابطال و
--    برگشت‌به‌پیش‌نویس خودبه‌خود درست‌اند و نسخه‌ی دومی از حقیقت نیست که
--    از اسناد جا بماند (همان استدلالِ `biz_stock_moves` در برابرِ `stock_qty`).
--    پیش‌نویس IMEI را فقط نگه می‌دارد؛ اثرش — مثلِ موجودی — هنگامِ صدور است.
-- ۳. دو ایندکس برای جست‌وجو با IMEI (هر دو ستون).
--
-- ⛔ شاهدِ `migrate.sh`: ایندکسِ آخر (`biz_invoice_lines:idx_biz_lines_imei2`).
-- ایدمپوتنت.

ALTER TABLE `biz_products`
    ADD COLUMN IF NOT EXISTS `has_serial` TINYINT(1) NOT NULL DEFAULT 0 AFTER `track_stock`;

ALTER TABLE `biz_invoice_lines`
    ADD COLUMN IF NOT EXISTS `imei1` VARCHAR(20) NULL DEFAULT NULL AFTER `unit`;
ALTER TABLE `biz_invoice_lines`
    ADD COLUMN IF NOT EXISTS `imei2` VARCHAR(20) NULL DEFAULT NULL AFTER `imei1`;

ALTER TABLE `biz_invoice_lines`
    ADD INDEX IF NOT EXISTS `idx_biz_lines_imei1` (`user_id`, `imei1`);
ALTER TABLE `biz_invoice_lines`
    ADD INDEX IF NOT EXISTS `idx_biz_lines_imei2` (`user_id`, `imei2`);
