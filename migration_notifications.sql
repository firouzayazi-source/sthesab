-- یادآورهای شخصی و مرکزِ اعلان
--
-- ⛔ چرا دو جدول و نه یکی:
--    `reminders` چیزی است که **کاربر می‌سازد** (یک تاریخ و یک عنوان)،
--    و `notifications` چیزی است که **برنامه تولید می‌کند** — از یادآور،
--    از سررسیدِ چک و بدهی، از تأییدِ پرداخت. اگر یکی می‌بودند، پاک کردنِ
--    یک اعلانِ خوانده‌شده یادآورِ خودِ کاربر را هم می‌برد.
--
-- ایدمپوتنت است: اجرای دوباره چیزی را عوض نمی‌کند.

CREATE TABLE IF NOT EXISTS `reminders` (
    `id`               INT AUTO_INCREMENT PRIMARY KEY,
    `user_id`          INT UNSIGNED NOT NULL,
    `title`            VARCHAR(200) NOT NULL,
    `note`             VARCHAR(500) NULL,
    `remind_date`      DATE NOT NULL,
    `amount`           BIGINT NULL,
    -- none | monthly | yearly — بیمه و عوارض سالانه‌اند، اجاره ماهانه.
    `repeat_every`     VARCHAR(16) NOT NULL DEFAULT 'none',
    `is_done`          TINYINT(1) NOT NULL DEFAULT 0,
    -- ⛔ نگهبانِ «یک اعلان در روز» برای همین یادآور. بدونش، هر بازدیدِ
    --    صفحه یک اعلانِ تازه می‌ساخت.
    `last_notified_on` DATE NULL,
    `created_at`       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY `idx_reminders_user_date` (`user_id`, `remind_date`),
    CONSTRAINT `fk_reminders_user` FOREIGN KEY (`user_id`)
        REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_persian_ci;

CREATE TABLE IF NOT EXISTS `notifications` (
    `id`         BIGINT AUTO_INCREMENT PRIMARY KEY,
    `user_id`    INT UNSIGNED NOT NULL,
    -- reminder | due | payment | system
    `kind`       VARCHAR(32) NOT NULL,
    `title`      VARCHAR(200) NOT NULL,
    `body`       VARCHAR(500) NULL,
    `link`       VARCHAR(190) NULL,
    -- ⛔ کلیدِ یکتاییِ رویداد. بدونِ آن، هر بار که صفحه باز شود همان
    --    سررسید دوباره اعلان می‌شد و مرکزِ اعلان پر از تکراری می‌شد —
    --    و کاربر بعد از دو روز دیگر نگاهش نمی‌کرد.
    `dedup_key`  VARCHAR(190) NULL,
    `read_at`    DATETIME NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY `uq_notif_dedup` (`user_id`, `dedup_key`),
    KEY `idx_notif_user` (`user_id`, `id`),
    CONSTRAINT `fk_notifications_user` FOREIGN KEY (`user_id`)
        REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_persian_ci;
