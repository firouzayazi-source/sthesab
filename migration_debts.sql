-- ============================================
-- افزودن جدول طلب و بدهی به دیتابیس موجود
-- این فایل را در phpMyAdmin روی دیتابیس فعلی Import کنید
-- (نیازی به ایمپورت دوباره schema.sql نیست، فقط همین فایل کافیست)
-- ============================================

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
