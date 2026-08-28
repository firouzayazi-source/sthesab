-- ============================================
-- دیتابیس مدیریت درآمد و هزینه
-- Engine: InnoDB | Charset: utf8mb4
-- ============================================

SET FOREIGN_KEY_CHECKS = 0;
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS `users` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `full_name` VARCHAR(100) NOT NULL,
    `username` VARCHAR(50) NOT NULL,
    `email` VARCHAR(190) NULL COMMENT 'برای بازیابی رمز',
    `password_hash` VARCHAR(255) NOT NULL,
    `role` ENUM('admin', 'user') NOT NULL DEFAULT 'user',
    `is_active` TINYINT(1) NOT NULL DEFAULT 1,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_username` (`username`),
    UNIQUE KEY `uq_email` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_persian_ci;

CREATE TABLE IF NOT EXISTS `categories` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `name` VARCHAR(100) NOT NULL,
    `type` ENUM('income', 'expense') NOT NULL,
    `is_active` TINYINT(1) NOT NULL DEFAULT 1,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_persian_ci;

CREATE TABLE IF NOT EXISTS `transactions` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id` INT UNSIGNED NOT NULL,
    `category_id` INT UNSIGNED NULL,
    `type` ENUM('income', 'expense') NOT NULL,
    `amount` BIGINT UNSIGNED NOT NULL,
    `currency` VARCHAR(10) NOT NULL DEFAULT 'TOMAN',
    `title` VARCHAR(255) NOT NULL,
    `note` TEXT NULL,
    `transaction_date` DATE NOT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_user_id` (`user_id`),
    KEY `idx_category_id` (`category_id`),
    KEY `idx_type` (`type`),
    KEY `idx_transaction_date` (`transaction_date`),
    CONSTRAINT `fk_transactions_user`
        FOREIGN KEY (`user_id`) REFERENCES `users` (`id`)
        ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT `fk_transactions_category`
        FOREIGN KEY (`category_id`) REFERENCES `categories` (`id`)
        ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_persian_ci;

-- دسته‌بندی‌های پیش‌فرض — فقط وقتی جدول خالی است.
-- بدون این شرط، اجرای دوباره‌ی schema.sql روی دیتابیسی که داده دارد
-- (مثلاً بعد از ایمپورت بکاپ هاست) همه‌ی این‌ها را تکراری اضافه می‌کند.
INSERT INTO `categories` (`name`, `type`)
SELECT n, t FROM (
    SELECT 'فروش گوشی' AS n, 'income' AS t
    UNION ALL SELECT 'خدمات', 'income'
    UNION ALL SELECT 'فروش لوازم جانبی', 'income'
    UNION ALL SELECT 'سایر درآمدها', 'income'
    UNION ALL SELECT 'خرید کالا', 'expense'
    UNION ALL SELECT 'اجاره', 'expense'
    UNION ALL SELECT 'حقوق', 'expense'
    UNION ALL SELECT 'تبلیغات', 'expense'
    UNION ALL SELECT 'حمل‌ونقل', 'expense'
    UNION ALL SELECT 'قبوض', 'expense'
    UNION ALL SELECT 'سایر هزینه‌ها', 'expense'
) AS seed
WHERE NOT EXISTS (SELECT 1 FROM `categories` LIMIT 1);

CREATE TABLE IF NOT EXISTS `debts` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id` INT UNSIGNED NOT NULL,
    `direction` ENUM('receivable', 'payable') NOT NULL COMMENT 'receivable=طلب من از دیگران, payable=بدهی من به دیگران',
    `counterparty_name` VARCHAR(150) NOT NULL,
    `amount` BIGINT UNSIGNED NOT NULL,
    `note` TEXT NULL,
    `entry_date` DATE NOT NULL,
    `due_date` DATE NOT NULL,
    `is_settled` TINYINT(1) NOT NULL DEFAULT 0,
    `settled_at` DATETIME NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_user_id` (`user_id`),
    KEY `idx_due_date` (`due_date`),
    KEY `idx_is_settled` (`is_settled`),
    CONSTRAINT `fk_debts_user`
        FOREIGN KEY (`user_id`) REFERENCES `users` (`id`)
        ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_persian_ci;

CREATE TABLE IF NOT EXISTS `app_settings` (
    `setting_key` VARCHAR(50) NOT NULL,
    `setting_value` VARCHAR(255) NOT NULL,
    PRIMARY KEY (`setting_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_persian_ci;

INSERT IGNORE INTO `app_settings` (`setting_key`, `setting_value`) VALUES ('require_full_login', '0');

SET FOREIGN_KEY_CHECKS = 1;