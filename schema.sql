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
    -- اختیاری، و فقط برای «ورود با کد پیامکی» که پیش‌فرض خاموش است.
    -- نرمال‌سازی‌اش تنها در SmsLogin::normalizePhone() است.
    `phone` VARCHAR(20) NULL DEFAULT NULL COMMENT 'شماره موبایل نرمال‌شده: 09xxxxxxxxx',
    `password_hash` VARCHAR(255) NOT NULL,
    `role` ENUM('admin', 'user') NOT NULL DEFAULT 'user',
    -- بعد از چند دقیقه بی‌فعالیتی دوباره رمز پرسیده شود. ۰ = هرگز.
    -- مقدارهای مجاز در Auth::SESSION_WINDOWS تعریف شده‌اند.
    `session_minutes` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '0 = بدون مهلت',
    `is_active` TINYINT(1) NOT NULL DEFAULT 1,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_username` (`username`),
    UNIQUE KEY `uq_email` (`email`),
    UNIQUE KEY `uniq_users_phone` (`phone`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_persian_ci;

CREATE TABLE IF NOT EXISTS `categories` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    -- NULL یعنی دسته‌ی پیش‌فرضِ برنامه (مال همه)؛ عدد یعنی دسته‌ی شخصیِ
    -- همان کاربر. جزئیات در migration_user_categories.sql
    `user_id` INT UNSIGNED NULL,
    `name` VARCHAR(100) NOT NULL,
    `type` ENUM('income', 'expense') NOT NULL,
    -- ⚠ این دو ستون را `migration_category_icons.sql` هم می‌سازد (برای
    --   نصب‌های قدیمی)، ولی اینجا هم لازم‌اند: seedِ همین فایل مقدارشان
    --   را می‌نویسد و بدونشان روی نصبِ **تازه** با
    --   «Unknown column 'icon'» می‌مرد و دیتابیس بدونِ هیچ دسته‌بندی‌ای
    --   بالا می‌آمد. تعریفشان باید دقیقاً با همان فایل یکی بماند.
    `icon` VARCHAR(24) NOT NULL DEFAULT 'default',
    `color` VARCHAR(7) NOT NULL DEFAULT '#64748b',
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
--
-- ⛔ این فهرست عمداً **خانوار** است، نه مغازه.
--    نسخه‌ی اول «فروش گوشی»، «فروش لوازم جانبی»، «تبلیغات» و — بدتر از
--    همه — «حقوق» را به‌عنوان *هزینه* داشت. برای یک اپ مالیِ شخصی و
--    خانوادگی این یعنی اولین منویی که خریدار باز می‌کند به او ربطی
--    ندارد: نه خوراک هست، نه قبوض، نه درمان، نه قسط، و «حقوق» — که
--    درآمدِ اصلیِ اغلب خانواده‌هاست — اصلاً در فهرست درآمد نیست.
--    برای نصب‌های موجود، `migration_household_categories.sql`.
INSERT INTO `categories` (`name`, `type`, `icon`, `color`)
SELECT n, t, i, c FROM (
    SELECT 'حقوق' AS n, 'income' AS t, 'salary' AS i, '#059669' AS c
    UNION ALL SELECT 'پاداش و عیدی',    'income',  'gift',      '#a855f7'
    UNION ALL SELECT 'درآمد آزاد',      'income',  'money',     '#10b981'
    UNION ALL SELECT 'سود سپرده',       'income',  'profit',    '#16a34a'
    UNION ALL SELECT 'اجاره‌ی دریافتی', 'income',  'home',      '#0d9488'
    UNION ALL SELECT 'سایر درآمدها',    'income',  'money',     '#10b981'
    UNION ALL SELECT 'خوراک',           'expense', 'food',      '#f97316'
    UNION ALL SELECT 'مسکن و اجاره',    'expense', 'home',      '#ef4444'
    UNION ALL SELECT 'قبوض',            'expense', 'bill',      '#eab308'
    UNION ALL SELECT 'حمل‌ونقل',        'expense', 'transport', '#0ea5e9'
    UNION ALL SELECT 'موبایل و اینترنت','expense', 'phone',     '#06b6d4'
    UNION ALL SELECT 'درمان',           'expense', 'health',    '#ec4899'
    UNION ALL SELECT 'پوشاک',           'expense', 'clothes',   '#f43f5e'
    UNION ALL SELECT 'آموزش',           'expense', 'education', '#6366f1'
    UNION ALL SELECT 'قسط و وام',       'expense', 'money',     '#64748b'
    UNION ALL SELECT 'تفریح و سفر',     'expense', 'fun',       '#d946ef'
    UNION ALL SELECT 'هدیه و مهمانی',   'expense', 'gift',      '#a855f7'
    UNION ALL SELECT 'سایر هزینه‌ها',   'expense', 'default',   '#94a3b8'
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

-- یادآورهای شخصی و مرکزِ اعلان (migration_notifications.sql)
CREATE TABLE IF NOT EXISTS `reminders` (
    `id`               INT AUTO_INCREMENT PRIMARY KEY,
    `user_id`          INT UNSIGNED NOT NULL,
    `title`            VARCHAR(200) NOT NULL,
    `note`             VARCHAR(500) NULL,
    `remind_date`      DATE NOT NULL,
    `amount`           BIGINT NULL,
    -- none | monthly | yearly — بیمه و عوارض سالانه‌اند، اجاره ماهانه.
    `repeat_every`     VARCHAR(16) NOT NULL DEFAULT 'none',
    `is_done`          TINYINT(1) NOT NULL DEFAULT 0,
    -- ⛔ نگهبانِ «یک اعلان در روز» برای همین یادآور. بدونش، هر بازدیدِ
    --    صفحه یک اعلانِ تازه می‌ساخت.
    `last_notified_on` DATE NULL,
    `created_at`       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY `idx_reminders_user_date` (`user_id`, `remind_date`),
    CONSTRAINT `fk_reminders_user` FOREIGN KEY (`user_id`)
        REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_persian_ci;

CREATE TABLE IF NOT EXISTS `notifications` (
    `id`         BIGINT AUTO_INCREMENT PRIMARY KEY,
    `user_id`    INT UNSIGNED NOT NULL,
    -- reminder | due | payment | system
    `kind`       VARCHAR(32) NOT NULL,
    `title`      VARCHAR(200) NOT NULL,
    `body`       VARCHAR(500) NULL,
    `link`       VARCHAR(190) NULL,
    -- ⛔ کلیدِ یکتاییِ رویداد. بدونِ آن، هر بار که صفحه باز شود همان
    --    سررسید دوباره اعلان می‌شد و مرکزِ اعلان پر از تکراری می‌شد —
    --    و کاربر بعد از دو روز دیگر نگاهش نمی‌کرد.
    `dedup_key`  VARCHAR(190) NULL,
    `read_at`    DATETIME NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY `uq_notif_dedup` (`user_id`, `dedup_key`),
    KEY `idx_notif_user` (`user_id`, `id`),
    CONSTRAINT `fk_notifications_user` FOREIGN KEY (`user_id`)
        REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_persian_ci;
