-- هسته‌ی مشترکِ سررسید
--
-- ⛔ معماری‌اش عمداً «ارجاع» است، نه «انتقال»:
--    جدولِ چک، طلب/بدهی و تراکنشِ دوره‌ای **صاحبِ پول و تاریخِ خودشان
--    می‌مانند**. `reminders` فقط قانونِ تکرار و اعلان را نگه می‌دارد و با
--    `source_type` + `source_id` به آن‌ها اشاره می‌کند. اگر تاریخ‌ها را
--    منتقل می‌کردیم، برای هر سررسید دو ردیفِ حقیقت می‌ماند (مثلاً پرداختِ
--    جزئی در `debt_payments` و وضعیت در occurrence) و ناهماهنگیِ بینشان
--    بی‌صدا بود.
--
-- ⛔ تاریخ‌ها **میلادی** ذخیره می‌شوند و شمسی نمایش داده می‌شوند — همان
--    قاعده‌ی همیشگیِ پروژه. با رشته‌ی شمسی، `ORDER BY` و `BETWEEN` و
--    ایندکس غلط می‌شدند و `financialEvents()` و بکاپ و api/v1 باید
--    بازنویسی می‌شدند.
--
-- ایدمپوتنت است: اجرای دوباره چیزی را عوض نمی‌کند.

-- ---------- ۱. قانونِ تکرار روی `reminders` ----------
--
-- ⚠ ستون‌ها یکی‌یکی و با بررسیِ وجود اضافه می‌شوند؛ MariaDB
--   `ADD COLUMN IF NOT EXISTS` را می‌فهمد و همین اجرای دوباره را بی‌خطر
--   می‌کند.
ALTER TABLE `reminders`
    ADD COLUMN IF NOT EXISTS `source_type`  VARCHAR(16) NOT NULL DEFAULT 'custom' AFTER `user_id`,
    ADD COLUMN IF NOT EXISTS `source_id`    BIGINT UNSIGNED NULL AFTER `source_type`,
    ADD COLUMN IF NOT EXISTS `wallet_id`    INT UNSIGNED NULL AFTER `amount`,
    -- once | every_n_months | yearly
    ADD COLUMN IF NOT EXISTS `recurrence_type` VARCHAR(20) NOT NULL DEFAULT 'once' AFTER `repeat_every`,
    ADD COLUMN IF NOT EXISTS `recurrence_n` SMALLINT UNSIGNED NOT NULL DEFAULT 1 AFTER `recurrence_type`,
    -- ⛔ روزِ لنگر: بدون این، ۳۱ فروردین بعد از یک بار افتادن در مهر
    --    (۳۰ روزه) برای همیشه ۳۰ می‌ماند. روزِ اصلی باید نگه داشته شود.
    ADD COLUMN IF NOT EXISTS `anchor_day`   TINYINT UNSIGNED NULL AFTER `recurrence_n`,
    -- fixed_day | last_day_of_month
    ADD COLUMN IF NOT EXISTS `day_rule`     VARCHAR(20) NOT NULL DEFAULT 'fixed_day' AFTER `anchor_day`,
    -- آرایه‌ی JSON مثل [7,3,1]
    ADD COLUMN IF NOT EXISTS `notify_days_before` VARCHAR(64) NOT NULL DEFAULT '[1]' AFTER `day_rule`,
    -- NULL یعنی «از تنظیمِ کاربر»
    ADD COLUMN IF NOT EXISTS `notify_hour`  TINYINT UNSIGNED NULL AFTER `notify_days_before`,
    -- active | paused | finished
    ADD COLUMN IF NOT EXISTS `status`       VARCHAR(16) NOT NULL DEFAULT 'active' AFTER `is_done`;

-- ⛔ یک قانون به‌ازای هر ردیفِ منبع، نه بیشتر. بدونِ این، همگام‌سازی
--    می‌توانست برای یک چک دو قانون بسازد و کاربر دو اعلانِ یکسان بگیرد.
--    (کلیدِ یکتا روی چند NULL حساس نیست، پس یادآورهای دلخواه آزادند.)
ALTER TABLE `reminders`
    ADD UNIQUE KEY IF NOT EXISTS `uq_reminder_source` (`user_id`, `source_type`, `source_id`);

ALTER TABLE `reminders`
    ADD KEY IF NOT EXISTS `idx_reminders_status` (`user_id`, `status`, `remind_date`);

-- ---------- ۲. هر سررسید، یک ردیف ----------
CREATE TABLE IF NOT EXISTS `reminder_occurrences` (
    `id`          BIGINT AUTO_INCREMENT PRIMARY KEY,
    `reminder_id` INT NOT NULL,
    `user_id`     INT UNSIGNED NOT NULL,
    -- ⛔ میلادی ذخیره می‌شود؛ نمایشِ شمسی کارِ `toJalali()` است.
    `due_date`    DATE NOT NULL,
    -- pending | done | skipped | overdue
    `status`      VARCHAR(16) NOT NULL DEFAULT 'pending',
    `done_at`     DATETIME NULL,
    `note`        VARCHAR(500) NULL,
    `created_at`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    -- ⛔ یک سررسید، یک ردیف. بدونِ این، چند بارِ اجرای تولیدکننده برای
    --    یک تاریخ چند ردیف می‌ساخت و فهرستِ سررسیدها پر از تکراری می‌شد.
    UNIQUE KEY `uq_occurrence` (`reminder_id`, `due_date`),
    KEY `idx_occ_user_due` (`user_id`, `status`, `due_date`),
    CONSTRAINT `fk_occ_reminder` FOREIGN KEY (`reminder_id`)
        REFERENCES `reminders` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_occ_user` FOREIGN KEY (`user_id`)
        REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_persian_ci;

-- ---------- ۳. نگهبانِ «یک اعلان به‌ازای هر پله» ----------
--
-- ⛔ بدونِ این، کرونِ ساعتی هر ساعت همان اعلانِ «۳ روز مانده» را دوباره
--    می‌ساخت. ایدمپوتنت بودنِ کرون از همین کلید می‌آید، نه از زمان‌بندی.
CREATE TABLE IF NOT EXISTS `reminder_notifications` (
    `id`            BIGINT AUTO_INCREMENT PRIMARY KEY,
    `occurrence_id` BIGINT NOT NULL,
    `user_id`       INT UNSIGNED NOT NULL,
    -- ۰ = روزِ سررسید، عددِ منفی = بعد از سررسید (overdue)
    `days_before`   SMALLINT NOT NULL,
    `sent_at`       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY `uq_occ_step` (`occurrence_id`, `days_before`),
    KEY `idx_rn_user` (`user_id`),
    CONSTRAINT `fk_rn_occurrence` FOREIGN KEY (`occurrence_id`)
        REFERENCES `reminder_occurrences` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_rn_user` FOREIGN KEY (`user_id`)
        REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_persian_ci;

-- ---------- ۴. ساعتِ پیش‌فرضِ یادآوری برای هر کاربر ----------
--
-- ⚠ کنارِ بقیه‌ی ترجیحاتِ اعلان می‌نشیند، نه در جدولِ تازه: نبودِ ردیف
--   یعنی «کاربر تصمیم نگرفته» و همان معنایی است که این جدول از اول
--   داشت.
ALTER TABLE `notification_prefs`
    ADD COLUMN IF NOT EXISTS `notify_hour` TINYINT UNSIGNED NOT NULL DEFAULT 9 AFTER `days_before`;

-- ---------- ۵. مهاجرتِ یادآورهای موجود به قانونِ تازه ----------
--
-- ستون قدیمی `repeat_every` دست‌نخورده می‌ماند (مثل `users.session_hours`)
-- ولی از این به بعد خوانده نمی‌شود. `anchor_day` از روزِ **شمسیِ** خودِ
-- تاریخ می‌آید و در PHP پر می‌شود؛ اینجا فقط نوعِ تکرار نگاشت می‌شود.
UPDATE `reminders`
   SET `recurrence_type` = CASE `repeat_every`
                                WHEN 'monthly' THEN 'every_n_months'
                                WHEN 'yearly'  THEN 'yearly'
                                ELSE 'once'
                           END,
       `recurrence_n`    = 1,
       `status`          = CASE WHEN `is_done` = 1 THEN 'finished' ELSE 'active' END
 WHERE `recurrence_type` = 'once' AND `repeat_every` <> 'none';
