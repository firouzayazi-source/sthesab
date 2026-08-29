-- ============================================================
-- migration_wallet_kinds.sql — نوع حسابِ دلخواه
--
-- تا حالا نوع حساب یک فهرست ثابت چهارتایی بود: نقدی / حساب بانکی /
-- کارت بانکی / سایر. «کارت بانکی» عملاً همان حساب بانکی بود و «سایر»
-- هیچ اسمی نداشت — کاربر نمی‌توانست بگوید این حساب ارزی است یا صندوق
-- قرض‌الحسنه.
--
-- حالا «سایر» اسم می‌گیرد و اسم‌ها برای دفعات بعد می‌مانند، دقیقاً مثل
-- کاری که برای نام بانک‌ها انجام می‌شود.
--
--   wallet_kinds        فهرست نوع‌های دلخواهِ هر کاربر
--   wallets.kind_label  نوعِ همین حساب وقتی kind = 'other' است
--
-- ستون `kind` عمداً دست‌نخورده می‌ماند (ENUM با مقدار 'card' هم هست):
-- حساب‌هایی که کاربر قبلاً «کارت بانکی» ثبت کرده باید همان‌طور کار کنند،
-- فقط در فهرست انتخاب دیگر پیشنهاد نمی‌شود.
--
-- ایمن و قابل اجرای دوباره.
-- ============================================================

CREATE TABLE IF NOT EXISTS `wallet_kinds` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id` INT UNSIGNED NOT NULL,
    `name` VARCHAR(60) NOT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uniq_user_name` (`user_id`, `name`),
    KEY `idx_user` (`user_id`),
    CONSTRAINT `fk_wallet_kinds_user`
        FOREIGN KEY (`user_id`) REFERENCES `users` (`id`)
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_persian_ci;

SET @c = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'wallets' AND COLUMN_NAME = 'kind_label');
SET @s = IF(@c = 0,
    'ALTER TABLE `wallets` ADD COLUMN `kind_label` VARCHAR(60) NULL AFTER `kind`',
    'SELECT 1');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SELECT 'migration_wallet_kinds با موفقیت اجرا شد.' AS result;
