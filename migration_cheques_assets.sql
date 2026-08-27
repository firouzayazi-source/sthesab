-- ============================================
-- افزودن جدول‌های چک، بانک‌ها و دارایی‌ها
-- این فایل را در phpMyAdmin روی دیتابیس فعلی Import کنید
-- ============================================

-- ---------- بانک‌ها ----------
-- scope='mine'     → بانک‌هایی که خودم دسته‌چک دارم (برای چک‌های صادره)
-- scope='external' → بانک‌های طرف مقابل (برای چک‌های دریافتی)
-- این دو لیست کاملاً از هم جدا هستند
CREATE TABLE IF NOT EXISTS `banks` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id` INT UNSIGNED NOT NULL,
    `scope` ENUM('mine', 'external') NOT NULL,
    `name` VARCHAR(100) NOT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uniq_user_scope_name` (`user_id`, `scope`, `name`),
    KEY `idx_user_scope` (`user_id`, `scope`),
    CONSTRAINT `fk_banks_user`
        FOREIGN KEY (`user_id`) REFERENCES `users` (`id`)
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_persian_ci;

-- ---------- چک‌ها ----------
-- direction='received' → چکی که از کسی گرفته‌ام (طلب)
-- direction='issued'   → چکی که خودم صادر کرده‌ام (بدهی)
CREATE TABLE IF NOT EXISTS `cheques` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id` INT UNSIGNED NOT NULL,
    `direction` ENUM('received', 'issued') NOT NULL,
    `counterparty_name` VARCHAR(150) NOT NULL,
    `amount` BIGINT UNSIGNED NOT NULL,
    `bank_id` INT UNSIGNED NULL,
    `sayadi_number` VARCHAR(30) NULL,
    `cheque_number` VARCHAR(30) NULL,
    `note` TEXT NULL,
    `due_date` DATE NULL,
    `is_settled` TINYINT(1) NOT NULL DEFAULT 0,
    `settled_at` DATETIME NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_user_id` (`user_id`),
    KEY `idx_due_date` (`due_date`),
    KEY `idx_is_settled` (`is_settled`),
    KEY `idx_bank_id` (`bank_id`),
    CONSTRAINT `fk_cheques_user`
        FOREIGN KEY (`user_id`) REFERENCES `users` (`id`)
        ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT `fk_cheques_bank`
        FOREIGN KEY (`bank_id`) REFERENCES `banks` (`id`)
        ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_persian_ci;

-- ---------- انواع دارایی ----------
CREATE TABLE IF NOT EXISTS `asset_types` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id` INT UNSIGNED NOT NULL,
    `name` VARCHAR(100) NOT NULL,
    `unit` VARCHAR(30) NOT NULL DEFAULT 'عدد',
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uniq_user_name` (`user_id`, `name`),
    KEY `idx_user_id` (`user_id`),
    CONSTRAINT `fk_asset_types_user`
        FOREIGN KEY (`user_id`) REFERENCES `users` (`id`)
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_persian_ci;

-- ---------- موجودی دارایی‌ها ----------
CREATE TABLE IF NOT EXISTS `assets` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id` INT UNSIGNED NOT NULL,
    `asset_type_id` INT UNSIGNED NOT NULL,
    `quantity` DECIMAL(18,4) NOT NULL,
    `unit_price` BIGINT UNSIGNED NULL COMMENT 'قیمت واحد به تومان هنگام ثبت (اختیاری)',
    `note` TEXT NULL,
    `entry_date` DATE NOT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_user_id` (`user_id`),
    KEY `idx_asset_type` (`asset_type_id`),
    CONSTRAINT `fk_assets_user`
        FOREIGN KEY (`user_id`) REFERENCES `users` (`id`)
        ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT `fk_assets_type`
        FOREIGN KEY (`asset_type_id`) REFERENCES `asset_types` (`id`)
        ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_persian_ci;
