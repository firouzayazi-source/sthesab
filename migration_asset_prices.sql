-- ============================================================
-- ارزشِ روزِ هر نوع دارایی، و عکسِ خالص دارایی
-- ============================================================
--
-- ⛔ چرا: صفحه‌ی «دارایی‌های من» ادعای «نمای کلیِ دارایی» دارد ولی
--    عددی که نشان می‌دهد **بهای خرید** است، نه ارزش. برای کاربر
--    ایرانی طلا و دلار ابزارِ اصلیِ پس‌انداز است؛ اپی که ارزشِ سکه‌ی
--    پارسال را همان عددِ پارسال نشان می‌دهد، در اقتصادِ تورمی در
--    هفته‌ی اول اعتماد را از دست می‌دهد.
--
-- ⚠ نرخ **دستی** است و از هیچ سرویسِ بیرونی نمی‌آید — همان دلیلی که
--   Chart.js را محلی کرد: اینترنتِ داخلی و تحریم. سرویسی که نصفِ
--   روزها در دسترس نباشد، بدتر از نبودنش است.
--
-- ایدمپوتنت است.

-- ---------- نرخِ روز روی نوعِ دارایی ----------
SET @has_price := (
    SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'asset_types' AND column_name = 'current_price'
);
SET @sql := IF(@has_price = 0,
    'ALTER TABLE `asset_types` ADD COLUMN `current_price` BIGINT UNSIGNED DEFAULT NULL',
    'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- تاریخِ به‌روزرسانی لازم است تا بشود گفت «نرخ از ۴۰ روز پیش است».
-- نرخِ کهنه‌ای که تاریخ نداشته باشد، از نداشتنِ نرخ بدتر است: کاربر
-- به عددی اعتماد می‌کند که نمی‌داند چقدر قدیمی است.
SET @has_pdate := (
    SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'asset_types' AND column_name = 'price_updated_at'
);
SET @sql := IF(@has_pdate = 0,
    'ALTER TABLE `asset_types` ADD COLUMN `price_updated_at` DATE DEFAULT NULL',
    'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- ---------- عکسِ خالص دارایی ----------
--
-- ⚠ **اجزا** ذخیره می‌شوند، نه جمع. چون صفحه‌ی دارایی برای هر قلم یک
--   کلیدِ روشن/خاموش دارد؛ اگر فقط جمع ذخیره می‌شد، روندِ «بدون طلا»
--   یا «بدون حساب‌ها» قابل ساختن نبود.
--
-- ⚠ `PRIMARY KEY(user_id, snap_date)` یعنی روزی یک ردیف. نوشتن با
--   `INSERT IGNORE` انجام می‌شود، پس بازدیدِ دوم در همان روز چیزی
--   اضافه نمی‌کند و نیازی به cron نیست (همان الگوی
--   processRecurringTransactions).
CREATE TABLE IF NOT EXISTS `net_worth_snapshots` (
    `user_id`     INT UNSIGNED NOT NULL,
    `snap_date`   DATE NOT NULL,
    `wallets`     BIGINT NOT NULL DEFAULT 0,
    `assets`      BIGINT NOT NULL DEFAULT 0,
    `trades_open` BIGINT NOT NULL DEFAULT 0,
    `cheques_net` BIGINT NOT NULL DEFAULT 0,
    `debts_net`   BIGINT NOT NULL DEFAULT 0,
    `created_at`  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`user_id`, `snap_date`),
    CONSTRAINT `fk_nw_snap_user` FOREIGN KEY (`user_id`)
        REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_persian_ci;
