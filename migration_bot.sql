-- ============================================================
-- migration_bot.sql — اعلان از راهِ ربات بله یا تلگرام (مهر ۱۴۰۵)
--
-- خواسته‌ی مالکِ نصب: اعلانِ گوشی از ایران پایدار نیست؛ خلاصه‌ی شبانه و هشدارها در بله یا تلگرام برسد.
--
-- ⛔ رباتِ همین اپ، جدا از هر رباتِ دیگرِ سرور (`Bot`): توکن را مدیرِ نصب برای همین کار می‌سازد و رمزشده
--    در `app_settings` می‌ماند؛ این جدول فقط «این کاربر کدام گفت‌وگوست». ربات هیچ‌وقت شروع‌کننده نیست:
--    کاربر کدِ یک‌بارمصرفِ خودش را به ربات می‌فرستد و فقط آن‌وقت وصل می‌شود.
-- ایدمپوتنت (IF NOT EXISTS).
-- ============================================================

CREATE TABLE IF NOT EXISTS `bot_links` (
    `user_id`    INT UNSIGNED NOT NULL,
    `chat_id`    BIGINT NULL DEFAULT NULL COMMENT 'NULL = هنوز وصل نشده',
    `code`       CHAR(6) NULL DEFAULT NULL COMMENT 'کدِ یک‌بارمصرفِ اتصال',
    `code_at`    DATETIME NULL DEFAULT NULL,
    `linked_at`  DATETIME NULL DEFAULT NULL,
    PRIMARY KEY (`user_id`),
    UNIQUE KEY `uq_bot_code` (`code`),
    CONSTRAINT `fk_bot_links_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_persian_ci;

SELECT 'migration_bot با موفقیت اجرا شد.' AS result;
