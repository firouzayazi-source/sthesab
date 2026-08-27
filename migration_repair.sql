-- ============================================================
-- migration_repair.sql — نسخه‌ی ایمن و خوداصلاح‌گر
--
-- این فایل جایگزین migration_wallets.sql و migration_p1.sql است.
-- هر جای کارت که مانده باشی (هیچ‌کدام اجرا نشده، یکی‌شان نصفه مانده،
-- یا هردو کامل شده) بدون خطا تمام می‌شود — چون قبل از هر تغییر،
-- اول چک می‌کند که آن تغییر از قبل انجام شده یا نه.
--
-- کاملاً بی‌خطر برای اجرای دوباره است.
-- ============================================================

-- ---------- ۱. کیف پول‌ها و انتقال (اگر نساخته شده) ----------
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
    CONSTRAINT `fk_wallets_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_persian_ci;

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
    CONSTRAINT `fk_transfers_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT `fk_transfers_from` FOREIGN KEY (`from_wallet_id`) REFERENCES `wallets` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT `fk_transfers_to` FOREIGN KEY (`to_wallet_id`) REFERENCES `wallets` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_persian_ci;

-- ---------- ۲. اتصال تراکنش به حساب (اگر ستون نبود اضافه کن) ----------
SET @col_exists = (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'transactions' AND COLUMN_NAME = 'wallet_id'
);
SET @sql = IF(@col_exists = 0,
    'ALTER TABLE `transactions` ADD COLUMN `wallet_id` INT UNSIGNED NULL AFTER `category_id`, ADD KEY `idx_wallet` (`wallet_id`)',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- کیف پول نقدی پیش‌فرض برای کاربرانی که هنوز ندارند
INSERT INTO `wallets` (`user_id`, `name`, `kind`, `color`, `sort_order`)
SELECT u.id, 'کیف پول نقدی', 'cash', '#16794f', 0
FROM `users` u
WHERE NOT EXISTS (SELECT 1 FROM `wallets` w WHERE w.user_id = u.id);

-- تراکنش‌های بدون حساب را به کیف پول نقدی همان کاربر نسبت بده
UPDATE `transactions` t
JOIN `wallets` w ON w.user_id = t.user_id AND w.name = 'کیف پول نقدی'
SET t.wallet_id = w.id
WHERE t.wallet_id IS NULL;

-- کلید خارجی (اگر قبلاً اضافه نشده)
SET @fk_exists = (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLE_CONSTRAINTS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'transactions' AND CONSTRAINT_NAME = 'fk_transactions_wallet'
);
SET @sql = IF(@fk_exists = 0,
    'ALTER TABLE `transactions` ADD CONSTRAINT `fk_transactions_wallet` FOREIGN KEY (`wallet_id`) REFERENCES `wallets` (`id`) ON DELETE SET NULL ON UPDATE CASCADE',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ---------- ۳. بودجه‌بندی ----------
CREATE TABLE IF NOT EXISTS `budgets` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id` INT UNSIGNED NOT NULL,
    `category_id` INT UNSIGNED NOT NULL,
    `period_type` ENUM('weekly','monthly','yearly','custom') NOT NULL DEFAULT 'monthly',
    `amount` BIGINT UNSIGNED NOT NULL,
    `start_date` DATE NULL,
    `end_date` DATE NULL,
    `is_active` TINYINT(1) NOT NULL DEFAULT 1,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uniq_user_cat_period` (`user_id`, `category_id`, `period_type`),
    KEY `idx_user_active` (`user_id`, `is_active`),
    CONSTRAINT `fk_budgets_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_budgets_category` FOREIGN KEY (`category_id`) REFERENCES `categories` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_persian_ci;

-- ---------- ۴. اهداف پس‌انداز ----------
CREATE TABLE IF NOT EXISTS `savings_goals` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id` INT UNSIGNED NOT NULL,
    `title` VARCHAR(150) NOT NULL,
    `target_amount` BIGINT UNSIGNED NOT NULL,
    `target_date` DATE NULL,
    `color` VARCHAR(7) NOT NULL DEFAULT '#16794f',
    `is_archived` TINYINT(1) NOT NULL DEFAULT 0,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_user` (`user_id`, `is_archived`),
    CONSTRAINT `fk_goals_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_persian_ci;

CREATE TABLE IF NOT EXISTS `savings_entries` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `goal_id` INT UNSIGNED NOT NULL,
    `user_id` INT UNSIGNED NOT NULL,
    `amount` BIGINT NOT NULL,
    `note` TEXT NULL,
    `entry_date` DATE NOT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_goal` (`goal_id`),
    KEY `idx_user` (`user_id`),
    CONSTRAINT `fk_entries_goal` FOREIGN KEY (`goal_id`) REFERENCES `savings_goals` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_entries_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_persian_ci;

-- ---------- ۵. پرداخت جزئی طلب و بدهی ----------
SET @col_exists = (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'debts' AND COLUMN_NAME = 'paid_amount'
);
SET @sql = IF(@col_exists = 0,
    'ALTER TABLE `debts` ADD COLUMN `paid_amount` BIGINT UNSIGNED NOT NULL DEFAULT 0 AFTER `amount`',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

CREATE TABLE IF NOT EXISTS `debt_payments` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `debt_id` BIGINT UNSIGNED NOT NULL,
    `user_id` INT UNSIGNED NOT NULL,
    `amount` BIGINT UNSIGNED NOT NULL,
    `note` TEXT NULL,
    `payment_date` DATE NOT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_debt` (`debt_id`),
    CONSTRAINT `fk_paym_debt` FOREIGN KEY (`debt_id`) REFERENCES `debts` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_paym_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_persian_ci;

-- ---------- ۶. تراکنش دوره‌ای (حالا که wallets قطعاً وجود دارد) ----------
CREATE TABLE IF NOT EXISTS `recurring_transactions` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id` INT UNSIGNED NOT NULL,
    `wallet_id` INT UNSIGNED NULL,
    `category_id` INT UNSIGNED NULL,
    `type` ENUM('income','expense') NOT NULL,
    `amount` BIGINT UNSIGNED NOT NULL,
    `title` VARCHAR(255) NOT NULL,
    `note` TEXT NULL,
    `frequency` ENUM('daily','weekly','monthly','yearly') NOT NULL,
    `interval_count` SMALLINT UNSIGNED NOT NULL DEFAULT 1,
    `start_date` DATE NOT NULL,
    `end_date` DATE NULL,
    `next_due_date` DATE NOT NULL,
    `mode` ENUM('auto','remind','confirm') NOT NULL DEFAULT 'remind',
    `is_active` TINYINT(1) NOT NULL DEFAULT 1,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_user_active_due` (`user_id`, `is_active`, `next_due_date`),
    CONSTRAINT `fk_recur_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_recur_wallet` FOREIGN KEY (`wallet_id`) REFERENCES `wallets` (`id`) ON DELETE SET NULL,
    CONSTRAINT `fk_recur_category` FOREIGN KEY (`category_id`) REFERENCES `categories` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_persian_ci;

SET @col_exists = (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'transactions' AND COLUMN_NAME = 'recurring_id'
);
SET @sql = IF(@col_exists = 0,
    'ALTER TABLE `transactions` ADD COLUMN `recurring_id` INT UNSIGNED NULL AFTER `wallet_id`, ADD KEY `idx_recurring` (`recurring_id`)',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @fk_exists = (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLE_CONSTRAINTS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'transactions' AND CONSTRAINT_NAME = 'fk_tx_recurring'
);
SET @sql = IF(@fk_exists = 0,
    'ALTER TABLE `transactions` ADD CONSTRAINT `fk_tx_recurring` FOREIGN KEY (`recurring_id`) REFERENCES `recurring_transactions` (`id`) ON DELETE SET NULL',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ---------- پایان — این پیام یعنی همه‌چیز موفق تمام شد ----------
SELECT 'همه‌ی جدول‌ها و ستون‌ها با موفقیت آماده شدند.' AS result;
