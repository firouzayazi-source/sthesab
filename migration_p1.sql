-- ============================================================
-- مرحله P1 — بودجه، اهداف پس‌انداز، پرداخت جزئی طلب/بدهی، تراکنش دوره‌ای
-- ایمن است: فقط جدول/ستون اضافه می‌کند، چیزی حذف یا تغییر معنایی نمی‌شود.
-- ============================================================

-- ---------- بودجه‌بندی ----------
CREATE TABLE IF NOT EXISTS `budgets` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id` INT UNSIGNED NOT NULL,
    `category_id` INT UNSIGNED NOT NULL,
    `period_type` ENUM('weekly','monthly','yearly','custom') NOT NULL DEFAULT 'monthly',
    `amount` BIGINT UNSIGNED NOT NULL,
    `start_date` DATE NULL COMMENT 'فقط برای period_type=custom',
    `end_date` DATE NULL COMMENT 'فقط برای period_type=custom',
    `is_active` TINYINT(1) NOT NULL DEFAULT 1,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uniq_user_cat_period` (`user_id`, `category_id`, `period_type`),
    KEY `idx_user_active` (`user_id`, `is_active`),
    CONSTRAINT `fk_budgets_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_budgets_category` FOREIGN KEY (`category_id`) REFERENCES `categories` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_persian_ci;

-- ---------- اهداف پس‌انداز ----------
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

-- مبلغ فعلی هرگز ذخیره نمی‌شود؛ همیشه از جمع همین ردیف‌ها محاسبه می‌شود
-- (دقیقاً همان فلسفه‌ی موجودی کیف پول‌ها — تا هیچ‌وقت عدد نمایشی از واقعیت جدا نیفتد)
CREATE TABLE IF NOT EXISTS `savings_entries` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `goal_id` INT UNSIGNED NOT NULL,
    `user_id` INT UNSIGNED NOT NULL,
    `amount` BIGINT NOT NULL COMMENT 'مثبت=واریز، منفی=برداشت',
    `note` TEXT NULL,
    `entry_date` DATE NOT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_goal` (`goal_id`),
    KEY `idx_user` (`user_id`),
    CONSTRAINT `fk_entries_goal` FOREIGN KEY (`goal_id`) REFERENCES `savings_goals` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_entries_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_persian_ci;

-- ---------- پرداخت جزئی طلب و بدهی ----------
-- افزودنی محض: تا وقتی کسی پرداخت جزئی ثبت نکند، رفتار قبلی (تیک تسویه کامل) دقیقاً همان است
ALTER TABLE `debts` ADD COLUMN `paid_amount` BIGINT UNSIGNED NOT NULL DEFAULT 0 AFTER `amount`;

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

-- ---------- تراکنش دوره‌ای ----------
-- بدون نیاز به Cron: هر بار کاربر وارد صفحه اصلی یا داشبورد شود، سررسیدهای گذشته بررسی می‌شوند
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

-- علامت‌گذاری تراکنش‌های ساخته‌شده توسط قانون دوره‌ای (برای جلوگیری از سردرگمی در گزارش‌ها)
ALTER TABLE `transactions` ADD COLUMN `recurring_id` INT UNSIGNED NULL AFTER `wallet_id`,
    ADD KEY `idx_recurring` (`recurring_id`),
    ADD CONSTRAINT `fk_tx_recurring` FOREIGN KEY (`recurring_id`) REFERENCES `recurring_transactions` (`id`) ON DELETE SET NULL;
