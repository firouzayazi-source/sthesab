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
    -- ⛔ `NULL` تنها معنای «این حساب رمز ندارد» است — حسابی که با
    --    شماره موبایل ساخته شده و هنوز رمزی نگذاشته. ستونِ بولینِ
    --    جدا (`has_password`) عمداً ساخته نشد: دو منبعِ حقیقت دیر یا
    --    زود از هم دور می‌افتند. جزئیات در migration_phone_signup.sql
    -- ⚠ پیش از هر `password_verify()` باید نبودنش سنجیده شود؛ آن تابع
    --   با `null` امروز `Deprecated` می‌دهد و در PHP 9 خطای کشنده.
    `password_hash` VARCHAR(255) NULL DEFAULT NULL,
    -- ⛔ VARCHAR نه ENUM: فهرستِ نقش‌ها در `Auth::ROLES` است و نقشِ
    --    تازه نباید `ALTER TABLE` بخواهد (همان دلیلِ `payments.method`).
    `role` VARCHAR(20) NOT NULL DEFAULT 'user',
    -- بعد از چند دقیقه بی‌فعالیتی دوباره رمز پرسیده شود. ۰ = هرگز.
    -- مقدارهای مجاز در Auth::SESSION_WINDOWS تعریف شده‌اند.
    `session_minutes` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '0 = بدون مهلت',
    -- «دقیقه‌ی اول»: تا وقتی NULL است، کارتِ «موجودی واقعی چقدر است؟» روی
    -- خانه دیده می‌شود. با تنظیم کردن **یا** با «نیازی نیست» پر می‌شود —
    -- شرطِ «همه‌ی حساب‌ها صفرند» به‌تنهایی کاربرِ واقعاً بی‌پول را تا ابد
    -- گیر می‌انداخت.
    `balance_setup_at` DATETIME NULL DEFAULT NULL,
    -- آخرین خروجیِ کاملِ داده. NULL = هرگز. یادآوریِ ماهانه از همین می‌آید.
    `last_backup_at` DATETIME NULL DEFAULT NULL,
    -- بانک‌ها و انواع دارایی پیش‌فرضِ این کاربر ساخته شده‌اند. NULL = هنوز نه.
    -- ⛔ نشانه لازم است: بدونش کاربری که آن‌ها را عمداً پاک کرده، با
    --    بازدیدِ بعدی همه را پس می‌گیرد. و جایش **اینجاست** نه
    --    `app_settings` — آنجا ستونِ `user_id` ندارد، پس با حذفِ کاربر
    --    نشانه‌اش تا ابد جا می‌ماند (`migration_seed_flag`).
    `defaults_seeded_at` DATETIME NULL DEFAULT NULL,
    `is_active` TINYINT(1) NOT NULL DEFAULT 1,
    -- نشست‌های وبِ ساخته‌شده پیش از این لحظه بی‌اعتبارند (Auth::isLoggedIn
    -- می‌سنجد). تنها نویسنده‌اش revokeAllAccessFor() است. NULL = هرگز.
    `access_revoked_at` DATETIME NULL DEFAULT NULL,
    -- قلم‌های **خاموشِ** صفحه‌ی خانه (کلیدهای `HOME_WIDGETS`، با ویرگول).
    -- NULL = همه روشن. جزئیات در migration_home_widgets.sql
    `home_hidden` VARCHAR(255) NULL DEFAULT NULL,
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

-- انتخابِ کاربر از اینکه کدام دسته‌ها روی فرمِ ثبت چیپ بگیرند
-- (migration_category_pin.sql).
--
-- ⛔ جدولِ جداست و نه ستونی روی `categories`، چون دسته‌ی پیش‌فرض
--    `user_id IS NULL` دارد و بینِ همه‌ی کاربران مشترک است: یک ستونِ
--    `pinned` روی آن ردیف، انتخابِ یک نفر را به همه تحمیل می‌کرد.
CREATE TABLE IF NOT EXISTS `category_pins` (
    `user_id` INT UNSIGNED NOT NULL,
    `category_id` INT UNSIGNED NOT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`user_id`, `category_id`),
    KEY `idx_catpin_cat` (`category_id`),
    CONSTRAINT `fk_catpin_user` FOREIGN KEY (`user_id`)
        REFERENCES `users` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_catpin_cat` FOREIGN KEY (`category_id`)
        REFERENCES `categories` (`id`) ON DELETE CASCADE
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
    -- شناسه‌ی یکتای سطرِ سهمِ سود در حسابداری فروشگاه
    -- (migration_store_share.sql). خالی برای هر ردیفِ عادی.
    `store_share_ref` VARCHAR(64) NULL DEFAULT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    -- ⛔ یکتا روی خودِ ستون: بدونش هر همگام‌سازی همان سود را دوباره
    --    به‌عنوان درآمد ثبت می‌کرد. MySQL چند `NULL` را می‌پذیرد، پس
    --    ردیف‌های عادی دست‌نخورده می‌مانند.
    UNIQUE KEY `uq_store_share_ref` (`store_share_ref`),
    KEY `idx_user_id` (`user_id`),
    KEY `idx_category_id` (`category_id`),
    KEY `idx_type` (`type`),
    KEY `idx_transaction_date` (`transaction_date`),
    -- این سه با `migration_indexes3.sql` به نصب‌های موجود اضافه می‌شوند و
    -- اینجا هستند تا نصبِ تازه از روزِ اول داشته باشدشان. توضیحِ کامل و
    -- عددهای اندازه‌گیری‌شده در همان فایل است؛ خلاصه: بدونشان فهرستِ
    -- «آخرین تراکنش‌ها» و صفحه‌ی تراکنش‌ها برای چند ردیف کلِ تاریخچه‌ی
    -- کاربر را می‌خوانند و مرتب می‌کنند، و `walletBalances()` برای جمعِ
    -- هر حساب به‌ازای هر ردیف به جدول برمی‌گردد.
    KEY `idx_user_created` (`user_id`, `created_at`),
    KEY `idx_user_date_created` (`user_id`, `transaction_date`, `created_at`, `type`, `amount`),
    -- ⛔ ایندکسِ سومِ آن فایل (`idx_user_wallet_sum`) عمداً **اینجا
    --    نیست**: ستونِ `wallet_id` را `migration_repair.sql` بعداً
    --    اضافه می‌کند، پس نوشتنش در همین `CREATE TABLE` نصبِ **تازه**
    --    را با «Unknown column 'wallet_id'» می‌خواباند — همان دامی که
    --    یک بار سرِ ستون‌های `icon`/`color` افتادیم. روی نصبِ موجود
    --    نامرئی بود و `test_migration_chain` گرفتش.
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
    `due_date` DATE NULL DEFAULT NULL COMMENT 'NULL = سررسید ندارد',
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
    -- «به گوشی فرستاده شد» (migration_push.sql)
    `pushed_at`  DATETIME NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY `uq_notif_dedup` (`user_id`, `dedup_key`),
    KEY `idx_notif_user` (`user_id`, `id`),
    KEY `idx_notif_push` (`pushed_at`, `created_at`),
    CONSTRAINT `fk_notifications_user` FOREIGN KEY (`user_id`)
        REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_persian_ci;

-- اعلان روی گوشی (migration_push.sql)
CREATE TABLE IF NOT EXISTS `push_subscriptions` (
    `id`            INT AUTO_INCREMENT PRIMARY KEY,
    `user_id`       INT UNSIGNED NOT NULL,
    `endpoint`      VARCHAR(700) NOT NULL,
    `endpoint_hash` CHAR(64)     NOT NULL,
    `p256dh`        VARCHAR(120) NOT NULL,
    `auth`          VARCHAR(60)  NOT NULL,
    `device_label`  VARCHAR(120) NULL,
    `created_at`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `last_ok_at`    DATETIME NULL,
    `fail_count`    INT NOT NULL DEFAULT 0,
    UNIQUE KEY `uq_push_endpoint` (`endpoint_hash`),
    KEY `idx_push_user` (`user_id`),
    CONSTRAINT `fk_push_user` FOREIGN KEY (`user_id`)
        REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_persian_ci;

-- هسته‌ی مشترکِ سررسید (migration_schedule.sql)
CREATE TABLE IF NOT EXISTS `reminder_occurrences` (
    `id`          BIGINT AUTO_INCREMENT PRIMARY KEY,
    `reminder_id` INT NOT NULL,
    `user_id`     INT UNSIGNED NOT NULL,
    -- ⛔ میلادی ذخیره می‌شود؛ نمایشِ شمسی کارِ `toJalali()` است.
    `due_date`    DATE NOT NULL,
    -- pending | done | skipped | overdue
    `status`      VARCHAR(16) NOT NULL DEFAULT 'pending',
    `done_at`     DATETIME NULL,
    `note`        VARCHAR(500) NULL,
    `created_at`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    -- ⛔ یک سررسید، یک ردیف. بدونِ این، چند بارِ اجرای تولیدکننده برای
    --    یک تاریخ چند ردیف می‌ساخت و فهرستِ سررسیدها پر از تکراری می‌شد.
    UNIQUE KEY `uq_occurrence` (`reminder_id`, `due_date`),
    KEY `idx_occ_user_due` (`user_id`, `status`, `due_date`),
    CONSTRAINT `fk_occ_reminder` FOREIGN KEY (`reminder_id`)
        REFERENCES `reminders` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_occ_user` FOREIGN KEY (`user_id`)
        REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_persian_ci;

-- ---------- ۳. نگهبانِ «یک اعلان به‌ازای هر پله» ----------
--
-- ⛔ بدونِ این، کرونِ ساعتی هر ساعت همان اعلانِ «۳ روز مانده» را دوباره
--    می‌ساخت. ایدمپوتنت بودنِ کرون از همین کلید می‌آید، نه از زمان‌بندی.
CREATE TABLE IF NOT EXISTS `reminder_notifications` (
    `id`            BIGINT AUTO_INCREMENT PRIMARY KEY,
    `occurrence_id` BIGINT NOT NULL,
    `user_id`       INT UNSIGNED NOT NULL,
    -- ۰ = روزِ سررسید، عددِ منفی = بعد از سررسید (overdue)
    `days_before`   SMALLINT NOT NULL,
    `sent_at`       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY `uq_occ_step` (`occurrence_id`, `days_before`),
    KEY `idx_rn_user` (`user_id`),
    CONSTRAINT `fk_rn_occurrence` FOREIGN KEY (`occurrence_id`)
        REFERENCES `reminder_occurrences` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_rn_user` FOREIGN KEY (`user_id`)
        REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_persian_ci;


-- ═══════════ سهمِ سهامدارِ حسابداریِ فروشگاه (migration_store_share.sql) ═══════════
--
-- فهرستِ **اجازه**: مدیر یک سهامدارِ آن سیستم را به یک کاربرِ اینجا وصل
-- می‌کند. سقفِ پنج نفر در `StoreShare::MAX_LINKS` است، نه در دیتابیس —
-- چون پیام دادنِ روشن کارِ کد است نه کارِ کلیدِ یکتا.
--
-- ⛔ `store_contact_id` هم یکتاست، نه فقط `user_id`: یک سهامدارِ فروشگاه
--    نباید به دو کاربرِ اینجا وصل شود، وگرنه سهمِ سودش دو بار در دو
--    دفتر ثبت می‌شد.
CREATE TABLE IF NOT EXISTS `store_shareholders` (
    `id`               INT AUTO_INCREMENT PRIMARY KEY,
    -- ⛔ `INT UNSIGNED` چون `users.id` همان است. با `INT`ِ علامت‌دار،
    --    MySQL کلیدِ خارجی را با «errno 150» رد می‌کند و migration وسطِ
    --    کار می‌ایستد — روی همین دیتابیس واقعاً دیده شد، نه در بازبینی.
    `user_id`          INT UNSIGNED NOT NULL,
    `store_contact_id` INT NOT NULL,
    `display_name`     VARCHAR(120) NOT NULL DEFAULT '',
    `is_active`        TINYINT(1) NOT NULL DEFAULT 1,
    `approved_by`      INT UNSIGNED NULL,
    `approved_at`      DATETIME NULL,
    `created_at`       TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY `uq_store_user` (`user_id`),
    UNIQUE KEY `uq_store_contact` (`store_contact_id`),
    KEY `idx_active` (`is_active`),
    CONSTRAINT `fk_store_sh_user` FOREIGN KEY (`user_id`)
        REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_persian_ci;

-- آینه‌ی تک‌ردیفیِ پاسخِ اندپوینت. پاسخِ خامِ JSON نگه داشته می‌شود نه
-- جدولِ نرمال‌شده: اینجا فقط **نمایش** می‌دهیم و هیچ کوئری‌ای روی
-- اجزایش زده نمی‌شود، پس هر ستونِ تازه‌ی آن گزارش یک migration لازم
-- نداشته باشد.
CREATE TABLE IF NOT EXISTS `store_sync` (
    `id`         TINYINT NOT NULL PRIMARY KEY,
    `payload`    LONGTEXT NULL,
    `fetched_at` DATETIME NULL,
    `last_error` VARCHAR(255) NULL,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
                 ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_persian_ci;

INSERT IGNORE INTO `store_sync` (`id`) VALUES (1);
