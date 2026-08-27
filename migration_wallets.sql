-- ============================================================
-- مرحله P0 — کیف پول / حساب مالی + انتقال بین حساب‌ها
--
-- ایمن است: هیچ داده‌ای حذف یا تغییر معنایی نمی‌شود.
-- برای هر کاربر یک «کیف پول نقدی» ساخته می‌شود و تراکنش‌های
-- قبلی به آن نسبت داده می‌شوند تا موجودی‌ها از ابتدا درست باشد.
-- ============================================================

-- ---------- حساب‌ها / کیف پول‌ها ----------
CREATE TABLE IF NOT EXISTS `wallets` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id` INT UNSIGNED NOT NULL,
    `name` VARCHAR(100) NOT NULL,
    `kind` ENUM('cash','bank','card','other') NOT NULL DEFAULT 'cash',
    `bank_name` VARCHAR(100) NULL,
    `card_last4` VARCHAR(4) NULL,
    `initial_balance` BIGINT NOT NULL DEFAULT 0,
    `color` VARCHAR(7) NOT NULL DEFAULT '#64748b',
    `is_active` TINYINT(1) NOT NULL DEFAULT 1,
    `sort_order` SMALLINT NOT NULL DEFAULT 0,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_user_active` (`user_id`, `is_active`),
    CONSTRAINT `fk_wallets_user`
        FOREIGN KEY (`user_id`) REFERENCES `users` (`id`)
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_persian_ci;

-- ---------- انتقال بین حساب‌ها ----------
-- عمداً جدول جداست تا در گزارش درآمد/هزینه شمرده نشود
CREATE TABLE IF NOT EXISTS `transfers` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id` INT UNSIGNED NOT NULL,
    `from_wallet_id` INT UNSIGNED NOT NULL,
    `to_wallet_id` INT UNSIGNED NOT NULL,
    `amount` BIGINT UNSIGNED NOT NULL,
    `fee` BIGINT UNSIGNED NOT NULL DEFAULT 0,
    `note` TEXT NULL,
    `transfer_date` DATE NOT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_user_date` (`user_id`, `transfer_date`),
    KEY `idx_from` (`from_wallet_id`),
    KEY `idx_to` (`to_wallet_id`),
    CONSTRAINT `fk_transfers_user`
        FOREIGN KEY (`user_id`) REFERENCES `users` (`id`)
        ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT `fk_transfers_from`
        FOREIGN KEY (`from_wallet_id`) REFERENCES `wallets` (`id`)
        ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT `fk_transfers_to`
        FOREIGN KEY (`to_wallet_id`) REFERENCES `wallets` (`id`)
        ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_persian_ci;

-- ---------- اتصال تراکنش به حساب ----------
ALTER TABLE `transactions`
    ADD COLUMN `wallet_id` INT UNSIGNED NULL AFTER `category_id`,
    ADD KEY `idx_wallet` (`wallet_id`);

-- ---------- ساخت کیف پول پیش‌فرض برای هر کاربر ----------
INSERT INTO `wallets` (`user_id`, `name`, `kind`, `color`, `sort_order`)
SELECT `id`, 'کیف پول نقدی', 'cash', '#16794f', 0 FROM `users`;

-- ---------- نسبت دادن تراکنش‌های قبلی به کیف پول نقدی همان کاربر ----------
UPDATE `transactions` t
JOIN `wallets` w ON w.user_id = t.user_id AND w.name = 'کیف پول نقدی'
SET t.wallet_id = w.id
WHERE t.wallet_id IS NULL;

-- کلید خارجی بعد از پر شدن داده‌ها اضافه می‌شود
ALTER TABLE `transactions`
    ADD CONSTRAINT `fk_transactions_wallet`
        FOREIGN KEY (`wallet_id`) REFERENCES `wallets` (`id`)
        ON DELETE SET NULL ON UPDATE CASCADE;
