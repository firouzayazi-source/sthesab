-- خرید امانی/نسیه و فروش نسیه در «معاملات» — پیوند به «طلب و بدهی».
--
-- «از علی گوشی گرفتم، پولش را هنوز نداده‌ام» یعنی یک **بدهی** به علی؛
-- «به محمد فروختم، پولش را بعداً می‌دهد» یعنی یک **طلب** از محمد. تا امروز
-- تنها گزینه‌ی فرم «بدون برداشت از حساب» بود: پول از هیچ حسابی کم نمی‌شد
-- ولی هیچ‌جا هم نوشته نمی‌شد که به کسی بدهکاریم — تعهد **بی‌صدا** گم می‌شد
-- و «پول قابل خرج» و «خالص دارایی» خوش‌بین می‌ماندند.
--
-- ⛔ دو ستونِ `on_credit` حالتِ صریحِ پرداخت‌اند، نه حدس از «حساب خالی».
--    «بدون برداشت از حساب» یک گزینه‌ی جداست (پولِ نقدی که جای دیگری
--    ثبت شده) و با نسیه یکی نیست.
--
-- ⛔ `debts.trade_id` / `debts.trade_sale_id` کلیدِ خارجیِ واقعی‌اند، نه
--    ستونِ ساده (برخلافِ `cheque_id`): بازگرداندنِ فایلِ بکاپ
--    (`includes/user_import.php`) شناسه‌ها را فقط از روی کلیدهای خارجیِ
--    `information_schema` از نو نگاشت می‌کند؛ ستونِ بی‌کلید با شناسه‌ی
--    قدیمی می‌نشست و طلب به معامله‌ی **کسِ دیگری** اشاره می‌کرد.
--    `ON DELETE SET NULL`: طلب/بدهی‌ای که رویش پرداخت ثبت شده با حذفِ
--    معامله نمی‌رود — پولِ واقعی جابه‌جا شده.
--
-- ⚠ نوع‌ها دقیقاً همان کلیدِ مقصدند (`trades.id` INT UNSIGNED،
--   `trade_sales.id` BIGINT UNSIGNED)، وگرنه errno 150 و migration وسطِ کار.

ALTER TABLE `trades`
    ADD COLUMN IF NOT EXISTS `on_credit` TINYINT(1) NOT NULL DEFAULT 0
        COMMENT '1 = امانی/نسیه — بدهی به فروشنده، بدون برداشت از حساب';

ALTER TABLE `trade_sales`
    ADD COLUMN IF NOT EXISTS `on_credit` TINYINT(1) NOT NULL DEFAULT 0
        COMMENT '1 = نسیه — طلب از خریدار، بدون واریز به حساب';

ALTER TABLE `debts`
    ADD COLUMN IF NOT EXISTS `trade_id` INT UNSIGNED NULL DEFAULT NULL;
ALTER TABLE `debts`
    ADD COLUMN IF NOT EXISTS `trade_sale_id` BIGINT UNSIGNED NULL DEFAULT NULL;

SET @fk := (
    SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
    WHERE CONSTRAINT_SCHEMA = DATABASE()
      AND TABLE_NAME = 'debts' AND CONSTRAINT_NAME = 'fk_debts_trade'
);
SET @sql := IF(@fk = 0,
    'ALTER TABLE `debts`
        ADD CONSTRAINT `fk_debts_trade` FOREIGN KEY (`trade_id`)
        REFERENCES `trades` (`id`) ON DELETE SET NULL ON UPDATE CASCADE',
    'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @fk := (
    SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
    WHERE CONSTRAINT_SCHEMA = DATABASE()
      AND TABLE_NAME = 'debts' AND CONSTRAINT_NAME = 'fk_debts_trade_sale'
);
SET @sql := IF(@fk = 0,
    'ALTER TABLE `debts`
        ADD CONSTRAINT `fk_debts_trade_sale` FOREIGN KEY (`trade_sale_id`)
        REFERENCES `trade_sales` (`id`) ON DELETE SET NULL ON UPDATE CASCADE',
    'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
