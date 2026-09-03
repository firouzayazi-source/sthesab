-- ============================================================
-- طرحِ اشتراک و ثبتِ پرداخت
-- ============================================================
--
-- ⛔ چرا `pro_until` تاریخ است و نه یک کلیدِ روشن/خاموش: اشتراک
--    منقضی می‌شود. با یک بولین، اولین باری که کسی تمدید نکند باید
--    یک کارِ زمان‌بندی‌شده خاموشش کند — و آن کار روزی از کار می‌افتد
--    و همه بی‌سروصدا Pro می‌مانند. با تاریخ، انقضا خودش اتفاق
--    می‌افتد و هیچ cron ای لازم نیست.
--
-- ⛔ چرا ستونِ `plan` هم هست با اینکه از `pro_until` می‌شود فهمید:
--    برای طرح‌های آینده (مثلاً «خانواده») که تاریخ به‌تنهایی
--    نمی‌گویدشان. امروز `isPro()` هر دو را با هم می‌سنجد.
--
-- پرداخت‌ها **حذف نمی‌شوند**، فقط وضعیتشان عوض می‌شود: تاریخچه‌ی مالی
-- بین شما و کاربر باید بماند، حتی پرداختِ ردشده.
--
-- ایدمپوتنت است: اجرای دوباره هیچ اثری ندارد.

-- ---------- users.plan ----------
SET @c = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'plan');
SET @s = IF(@c = 0,
    "ALTER TABLE `users` ADD COLUMN `plan` VARCHAR(20) NOT NULL DEFAULT 'free'",
    'SELECT 1');
PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ---------- users.pro_until ----------
SET @c = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'pro_until');
SET @s = IF(@c = 0,
    'ALTER TABLE `users` ADD COLUMN `pro_until` DATE NULL AFTER `plan`',
    'SELECT 1');
PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ---------- payments ----------
CREATE TABLE IF NOT EXISTS `payments` (
    `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id`     INT UNSIGNED NOT NULL,
    -- مبلغ به تومان، مثل بقیه‌ی اپ
    `amount`      BIGINT NOT NULL DEFAULT 0,
    `months`      SMALLINT UNSIGNED NOT NULL DEFAULT 1,
    -- فعلاً فقط 'card' (کارت‌به‌کارت). درگاه که آمد، مقدارِ تازه
    -- اضافه می‌شود بی‌آنکه ستون عوض شود.
    `method`      VARCHAR(20) NOT NULL DEFAULT 'card',
    -- شماره‌ی پیگیری/ارجاعی که کاربر وارد می‌کند
    `reference`   VARCHAR(120) NULL,
    `note`        VARCHAR(255) NULL,
    `status`      ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
    `reviewed_by` INT UNSIGNED NULL,
    `reviewed_at` DATETIME NULL,
    `created_at`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_payments_user`   (`user_id`),
    KEY `idx_payments_status` (`status`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_persian_ci;

SELECT 'migration_plans با موفقیت اجرا شد.' AS result;
