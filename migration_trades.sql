-- ============================================================
-- migration_trades.sql — بخش معاملات (خرید و فروش)
--
-- دفتری جدا از درآمد/هزینه برای خرید و فروش کالا (گوشی، ماشین،
-- سکه، ...). عمداً به جدول transactions وصل نیست: معامله نه درآمد
-- است نه هزینه؛ فقط سودش اهمیت دارد و آن هم در صفحه‌ی خودش.
--
-- تنها نقطه‌ی تماس با بقیه‌ی اپ، موجودی حساب‌هاست: اگر معامله به
-- کیف‌پول وصل شده باشد، خرید از آن کم و فروش به آن اضافه می‌شود
-- (در walletBalances جمع می‌شود، نه با ردیف تراکنش).
--
-- ایمن و قابل اجرای دوباره.
-- ============================================================

-- هر کاربر خودش تصمیم می‌گیرد این بخش را ببیند یا نه؛ پیش‌فرض خاموش
SET @col_exists = (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'trades_enabled'
);
SET @sql = IF(@col_exists = 0,
    'ALTER TABLE `users` ADD COLUMN `trades_enabled` TINYINT(1) NOT NULL DEFAULT 0 AFTER `session_hours`',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- یک خرید. qty اعشاری است چون طلا/ارز با گرم و واحد کسری معامله می‌شود.
-- side_costs (کارمزد، حمل، تعمیر) فقط در محاسبه‌ی سود لحاظ می‌شود،
-- نه در موجودی حساب — چون معلوم نیست کی و از کجا پرداخت شده.
CREATE TABLE IF NOT EXISTS `trades` (
    `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id`       INT UNSIGNED NOT NULL,
    `title`         VARCHAR(150) NOT NULL,
    `qty`           DECIMAL(14,3) NOT NULL DEFAULT 1,
    `buy_total`     BIGINT UNSIGNED NOT NULL,
    `side_costs`    BIGINT UNSIGNED NOT NULL DEFAULT 0,
    `buy_date`      DATE NOT NULL,
    `buy_wallet_id` INT UNSIGNED NULL,
    `notes`         TEXT NULL,
    `created_at`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_trades_user_date` (`user_id`, `buy_date`),
    CONSTRAINT `fk_trades_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`)
        ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT `fk_trades_wallet` FOREIGN KEY (`buy_wallet_id`) REFERENCES `wallets` (`id`)
        ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_persian_ci;

-- فروش‌ها جدا هستند تا فروش جزئی ممکن باشد: ۵ سکه بخر، ۲ تا را
-- امروز بفروش، ۳ تا را ماه بعد. user_id تکرار شده تا قاعده‌ی
-- «هر کوئری WHERE user_id» بدون JOIN رعایت شود.
CREATE TABLE IF NOT EXISTS `trade_sales` (
    `id`          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `trade_id`    INT UNSIGNED NOT NULL,
    `user_id`     INT UNSIGNED NOT NULL,
    `qty`         DECIMAL(14,3) NOT NULL,
    `sale_total`  BIGINT UNSIGNED NOT NULL,
    `sale_date`   DATE NOT NULL,
    `wallet_id`   INT UNSIGNED NULL,
    `notes`       VARCHAR(500) NULL,
    `created_at`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_tsales_trade` (`trade_id`),
    KEY `idx_tsales_user_date` (`user_id`, `sale_date`),
    CONSTRAINT `fk_tsales_trade` FOREIGN KEY (`trade_id`) REFERENCES `trades` (`id`)
        ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT `fk_tsales_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`)
        ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT `fk_tsales_wallet` FOREIGN KEY (`wallet_id`) REFERENCES `wallets` (`id`)
        ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_persian_ci;

SELECT 'migration_trades با موفقیت اجرا شد.' AS result;
