-- ============================================================
-- migration_store_share.sql — سهمِ سهامدارِ فروشگاه، خواندنی
-- ============================================================
--
-- **خواسته‌ی مالکِ نصب:** «فقط می‌خوام در حسابلند طرف دارایشو ببینه و
-- سودش رو بعد از معامله که محاسبه شد آنلاین در حسابلند جزو سود روزانه
-- خودکار ثبت بشه، و در بخش دارایی بخش مدیر یک توگل داراییِ کل فروشگاه
-- هم اضافه بشه فقط برای سوپرادمین.»
--
-- ─── سه جدول/ستون، و هر کدام یک کارِ متفاوت ───
--
--   store_shareholders   فهرستِ **اجازه**. مدیر یک سهامدارِ آن سیستم را
--                        به یک کاربرِ اینجا وصل می‌کند. سقفِ پنج نفر.
--   store_sync           آینه‌ی کش‌شده‌ی پاسخِ اندپوینت، با مهرِ زمان.
--   transactions.store_share_ref   شناسه‌ی یکتای سطرِ سهم در سمتِ
--                        فروشگاه، تا همگام‌سازیِ دوباره ردیفِ تکراری
--                        نسازد.
--
-- ⛔ **چرا آینه، و نه خواندنِ زنده در هر بارگذاری:** اپِ حسابداری روی
--    همین سرور با داکر اجرا می‌شود و با هر انتشار ری‌استارت می‌شود. با
--    خواندنِ صرفاً زنده، صفحه‌ی دارایی سهامدار در هر دیپلویِ فروشگاه
--    خالی می‌شد — بی‌هیچ خطایی که کاربر بفهمدش. حالا آخرین نسخه با
--    «آخرین به‌روزرسانی» می‌ماند، همان تورِ نجاتِ سرویس‌ورکر.
--
-- ⛔ **چرا `store_share_ref` یکتاست:** بدونِ آن، هر همگام‌سازی همان سهم
--    را دوباره به‌عنوان درآمد ثبت می‌کرد و درآمدِ آن کاربر هر چند دقیقه
--    باد می‌شد، بی‌هیچ خطایی. همان قاعده‌ی `dedup_key` در `notifications`
--    و `idem_key` در خودِ آن سیستم.
--
-- ⛔ **دسته‌ی سود و زیان پیش‌فرضِ برنامه است (`user_id IS NULL`)** — همان
--    استدلالِ «سود معاملات»: اگر شخصی می‌شد، هر سهامدار یک نسخه‌ی
--    تکراری می‌ساخت.
--
-- ایدمپوتنت است.

-- ─────────────────── فهرستِ اجازه ───────────────────
--
-- ⛔ `store_contact_id` هم یکتاست، نه فقط `user_id`: یک سهامدارِ
--    فروشگاه نباید به دو کاربرِ اینجا وصل شود، وگرنه سهمِ سودش دو بار
--    در دو دفتر ثبت می‌شد.
CREATE TABLE IF NOT EXISTS `store_shareholders` (
    `id`               INT AUTO_INCREMENT PRIMARY KEY,
    -- ⛔ `INT UNSIGNED` چون `users.id` همان است. با `INT`ِ علامت‌دار،
    --    MySQL کلیدِ خارجی را با «errno 150» رد می‌کند و migration وسطِ
    --    کار می‌ایستد — روی همین دیتابیس واقعاً دیده شد، نه در بازبینی.
    `user_id`          INT UNSIGNED NOT NULL,
    `store_contact_id` INT NOT NULL,
    -- نامِ نمایشیِ همان لحظه‌ی وصل کردن، فقط برای فهرستِ مدیر. مرجع
    -- همیشه `store_contact_id` است؛ نام ممکن است آن‌طرف عوض شود.
    `display_name`     VARCHAR(120) NOT NULL DEFAULT '',
    `is_active`        TINYINT(1) NOT NULL DEFAULT 1,
    `approved_by`      INT UNSIGNED NULL,
    `approved_at`      DATETIME NULL,
    `created_at`       TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY `uq_store_user` (`user_id`),
    UNIQUE KEY `uq_store_contact` (`store_contact_id`),
    KEY `idx_active` (`is_active`),
    CONSTRAINT `fk_store_sh_user` FOREIGN KEY (`user_id`)
        REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_persian_ci;

-- ─────────────────── آینه‌ی پاسخ ───────────────────
--
-- تک‌ردیفی (`id = 1`). پاسخِ خامِ JSON نگه داشته می‌شود نه جدولِ
-- نرمال‌شده: شکلِ آن گزارش ممکن است ستونِ تازه بگیرد و با جدولِ
-- نرمال‌شده هر بار یک migration لازم بود، در حالی که اینجا فقط
-- **نمایش** می‌دهیم و هیچ کوئری‌ای روی اجزایش زده نمی‌شود.
CREATE TABLE IF NOT EXISTS `store_sync` (
    `id`         TINYINT NOT NULL PRIMARY KEY,
    `payload`    LONGTEXT NULL,
    `fetched_at` DATETIME NULL,
    -- آخرین خطای تلاشِ ناموفق. خالی یعنی آخرین تلاش موفق بود.
    `last_error` VARCHAR(255) NULL,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
                 ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_persian_ci;

INSERT IGNORE INTO `store_sync` (`id`) VALUES (1);

-- ─────────────────── پیوندِ ایدمپوتنسی ───────────────────
ALTER TABLE `transactions`
    ADD COLUMN IF NOT EXISTS `store_share_ref` VARCHAR(64) NULL DEFAULT NULL
        COMMENT 'شناسه‌ی یکتای سطرِ سهم در حسابداری فروشگاه';

-- ⛔ یکتا روی خودِ ستون، نه `(user_id, ref)`: شناسه‌ی آن‌طرف سراسری است
--    و `store_contact_id` هم یکتاست، پس یک سهم به بیش از یک کاربر
--    نمی‌رسد. MySQL چند `NULL` را در کلیدِ یکتا می‌پذیرد، پس ردیف‌های
--    عادی دست‌نخورده می‌مانند.
ALTER TABLE `transactions`
    ADD UNIQUE INDEX IF NOT EXISTS `uq_store_share_ref` (`store_share_ref`);

-- ─────────────── سطل‌های تازه‌ی روندِ خالص دارایی ───────────────
--
-- ⛔ دو ستونِ **جدا**، نه یکی، و نه ریختنشان در سطلِ `assets`.
--    `net_worth_snapshots` عمداً اجزا را ذخیره می‌کند نه جمع، چون هر
--    قلمِ صفحه‌ی دارایی یک کلیدِ روشن/خاموش دارد. با ریختنِ این دو در
--    سطلِ `assets`، جمعِ کل درست می‌ماند ولی اجزا غلط — و **بی‌صدا**،
--    چون عددِ روی صفحه فرقی نمی‌کند. همان دامی که راهنما درباره‌ی
--    مفرد نوشتنِ نامِ سطل‌ها هشدار داده.
--
--    و چرا یک ستونِ مشترک نشد: «داراییِ من در فروشگاه» و «خالصِ کلِ
--    فروشگاه» دو چیزِ متفاوت با دو مخاطبِ متفاوت‌اند، و یک نصب می‌تواند
--    کاربری داشته باشد که هم سهامدار است هم مدیر.
ALTER TABLE `net_worth_snapshots`
    ADD COLUMN IF NOT EXISTS `store_share` BIGINT NOT NULL DEFAULT 0
        COMMENT 'مانده‌ی همین کاربر در حسابداری فروشگاه';

ALTER TABLE `net_worth_snapshots`
    ADD COLUMN IF NOT EXISTS `store_total` BIGINT NOT NULL DEFAULT 0
        COMMENT 'خالص داراییِ کل فروشگاه — فقط برای مدیر';

-- ─────────────────── دسته‌ی پیش‌فرض ───────────────────
--
-- ⛔ نشانه‌ی `app_settings` یعنی اگر کاربر این دو دسته را **عمداً** حذف
--    کند، اجرای بعدی زنده‌شان نکند — همان استدلالِ
--    `more_categories_seeded`. (خودِ `LEFT JOIN` ایدمپوتنسی را می‌دهد.)
SET @store_cat_seeded := (
    SELECT COUNT(*) FROM `app_settings` WHERE `setting_key` = 'store_share_seeded'
);

-- ⛔ collation صریح: `JOIN` روی `name` بین دو ستون است و هر دو «قابلیتِ
--    تبدیلِ ۲» دارند، پس هیچ‌کدام به دیگری تبدیل نمی‌شود.
DROP TEMPORARY TABLE IF EXISTS `_store_cat_seed`;
CREATE TEMPORARY TABLE `_store_cat_seed` (
    `name`  VARCHAR(100) NOT NULL,
    `type`  VARCHAR(10)  NOT NULL,
    `icon`  VARCHAR(24)  NOT NULL,
    `color` VARCHAR(7)   NOT NULL
) ENGINE=Memory DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_persian_ci;

INSERT INTO `_store_cat_seed` (`name`, `type`, `icon`, `color`) VALUES
    ('سود سهام فروشگاه', 'income',  'money', '#059669'),
    ('زیان سهام فروشگاه', 'expense', 'money', '#dc2626');

INSERT INTO `categories` (`user_id`, `name`, `type`, `icon`, `color`, `is_active`)
SELECT NULL, s.`name`, s.`type`, s.`icon`, s.`color`, 1
FROM `_store_cat_seed` s
LEFT JOIN `categories` c
       ON c.`name` = s.`name` AND c.`user_id` IS NULL
WHERE c.`id` IS NULL
  AND @store_cat_seeded = 0;

DROP TEMPORARY TABLE IF EXISTS `_store_cat_seed`;

INSERT INTO `app_settings` (`setting_key`, `setting_value`)
VALUES ('store_share_seeded', '1')
ON DUPLICATE KEY UPDATE `setting_value` = '1';

SELECT 'migration_store_share با موفقیت اجرا شد.' AS result;
