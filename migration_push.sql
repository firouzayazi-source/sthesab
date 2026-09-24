-- ============================================================
-- اعلان روی گوشی (Web Push) — «نوتیف داخل اپ روی آیکون و گوشی بیاد»
--
-- ⛔ دو چیز، و هیچ‌کدام دیگری را نمی‌سازد:
--    ۱. push_subscriptions — هر مرورگر/گوشی که اجازه‌ی اعلان داده یک ردیف.
--       کلیدِ خصوصیِ امضا (VAPID) اینجا **نیست**: در var/push/vapid.json
--       است، بیرون از بکاپِ دیتابیس (همان استدلالِ APP_ENCRYPTION_KEY).
--    ۲. notifications.pushed_at — «این اعلان به گوشی فرستاده شد». نگهبانِ
--       «یک بار»؛ بدونش cronِ هر دقیقه همان اعلان را هر دقیقه می‌فرستاد.
--
-- ایدمپوتنت است: IF NOT EXISTS همه‌جا.
-- ============================================================

CREATE TABLE IF NOT EXISTS `push_subscriptions` (
    `id`            INT AUTO_INCREMENT PRIMARY KEY,
    -- ⚠ INT UNSIGNED مثل users.id — با INT خام، کلیدِ خارجی errno 150 می‌دهد.
    `user_id`       INT UNSIGNED NOT NULL,
    `endpoint`      VARCHAR(700) NOT NULL,
    -- یکتایی روی هش است: endpoint بلندتر از سقفِ کلیدِ ایندکس است.
    `endpoint_hash` CHAR(64)     NOT NULL,
    `p256dh`        VARCHAR(120) NOT NULL,
    `auth`          VARCHAR(60)  NOT NULL,
    `device_label`  VARCHAR(120) NULL,
    `created_at`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `last_ok_at`    DATETIME NULL,
    `fail_count`    INT NOT NULL DEFAULT 0,
    UNIQUE KEY `uq_push_endpoint` (`endpoint_hash`),
    KEY `idx_push_user` (`user_id`),
    CONSTRAINT `fk_push_user` FOREIGN KEY (`user_id`)
        REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_persian_ci;

-- ⚠ آخرین کارِ فایل، و شاهدِ migrate.sh همین ستون است.
ALTER TABLE `notifications`
    ADD COLUMN IF NOT EXISTS `pushed_at` DATETIME NULL DEFAULT NULL AFTER `read_at`,
    ADD INDEX IF NOT EXISTS `idx_notif_push` (`pushed_at`, `created_at`);
