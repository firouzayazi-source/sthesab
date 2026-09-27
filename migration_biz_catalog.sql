-- ============================================================
-- محیطِ فروشگاهی — مرحله‌ی ۲: کالا و موجودی، طرف‌حساب، صندوق
-- ============================================================
--
-- ⛔ فقط افزودنی. هیچ جدولِ شخصی (transactions، wallets، debts، …) دست
--    نمی‌خورد: پولِ فروشگاه دفترِ خودش را دارد (`biz_accounts`) و هرگز در
--    `walletBalances()` نمی‌نشیند — وگرنه کاربری که هم حساب لند دارد هم
--    فروشگاه، پولِ مغازه و خانه‌اش را در یک عدد می‌دید.
--
-- ۱. `biz_products` — کالا. `stock_qty` و `avg_cost` **مشتق‌اند**: فقط
--    `BizStock::recalc()` می‌نویسدشان، از روی `biz_stock_moves`. نگه
--    داشتنشان روی ردیف یعنی فهرستِ کالا بدونِ JOIN و بدونِ جمع خوانده
--    می‌شود؛ تست برابریِ کش با محاسبه‌ی دوباره را می‌سنجد.
-- ۲. `biz_stock_moves` — تنها منبعِ حقیقتِ موجودی. هر ورود و خروج یک
--    ردیفِ علامت‌دار است (`opening`، `adjust`، و از مرحله‌ی ۳ فاکتور).
--    `ref_type`/`ref_id` برای فاکتورهای مرحله‌ی بعد، از حالا.
-- ۳. `biz_parties` — مشتری و تأمین‌کننده. `opening_balance` علامت‌دار:
--    مثبت یعنی او به فروشگاه بدهکار است، منفی یعنی فروشگاه به او.
-- ۴. `biz_accounts` — صندوق و حساب‌های بانکیِ **خودِ فروشگاه**.
--
-- ⚠ `user_id` همه‌جا `INT UNSIGNED` است، هم‌نوعِ `users.id` (تله‌ی
--   errno 150). مقدار و تعداد `DECIMAL(14,3)` است چون کیلوگرم و متر هم
--   فروخته می‌شوند.
--
-- ⛔ شاهدِ `migrate.sh` جدولِ `biz_accounts` است — آخرین دستورِ فایل.
-- ایدمپوتنت: اجرای دوباره هیچ کاری نمی‌کند.

CREATE TABLE IF NOT EXISTS `biz_products` (
    `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id`    INT UNSIGNED NOT NULL,
    `name`       VARCHAR(150) NOT NULL,
    `sku`        VARCHAR(60)  NULL DEFAULT NULL COMMENT 'کد یا بارکد؛ یکتا در هر فروشگاه',
    `category`   VARCHAR(80)  NULL DEFAULT NULL,
    `unit`       VARCHAR(20)  NOT NULL DEFAULT 'عدد',
    `buy_price`  BIGINT NOT NULL DEFAULT 0,
    `sell_price` BIGINT NOT NULL DEFAULT 0,
    `min_stock`  DECIMAL(14,3) NOT NULL DEFAULT 0 COMMENT 'هشدارِ کم‌موجودی؛ ۰ یعنی خاموش',
    `track_stock` TINYINT(1) NOT NULL DEFAULT 1 COMMENT '۰ = خدمت، موجودی ندارد',
    `stock_qty`  DECIMAL(14,3) NOT NULL DEFAULT 0 COMMENT 'مشتق؛ فقط BizStock::recalc',
    `avg_cost`   DECIMAL(18,2) NOT NULL DEFAULT 0 COMMENT 'میانگینِ موزون متحرک؛ فقط BizStock::recalc',
    `note`       VARCHAR(300) NULL DEFAULT NULL,
    `is_active`  TINYINT(1) NOT NULL DEFAULT 1,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_biz_products_sku` (`user_id`, `sku`),
    KEY `idx_biz_products_user_name` (`user_id`, `is_active`, `name`),
    CONSTRAINT `fk_biz_products_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`)
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_persian_ci;

CREATE TABLE IF NOT EXISTS `biz_stock_moves` (
    `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id`    INT UNSIGNED NOT NULL,
    `product_id` INT UNSIGNED NOT NULL,
    `move_date`  DATE NOT NULL,
    `kind`       VARCHAR(20) NOT NULL COMMENT 'opening / adjust / (مرحله‌ی ۳: purchase, sale, …)',
    `qty`        DECIMAL(14,3) NOT NULL COMMENT 'علامت‌دار: ورود مثبت، خروج منفی',
    `unit_cost`  BIGINT NULL DEFAULT NULL COMMENT 'بهای واحدِ ورودی؛ NULL یعنی به میانگینِ جاری',
    `note`       VARCHAR(200) NULL DEFAULT NULL,
    `ref_type`   VARCHAR(20) NULL DEFAULT NULL,
    `ref_id`     INT UNSIGNED NULL DEFAULT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_biz_moves_product` (`product_id`, `move_date`, `id`),
    KEY `idx_biz_moves_user` (`user_id`, `move_date`),
    CONSTRAINT `fk_biz_moves_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`)
        ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT `fk_biz_moves_product` FOREIGN KEY (`product_id`) REFERENCES `biz_products` (`id`)
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_persian_ci;

CREATE TABLE IF NOT EXISTS `biz_parties` (
    `id`              INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id`         INT UNSIGNED NOT NULL,
    `name`            VARCHAR(150) NOT NULL,
    `kind`            VARCHAR(20) NOT NULL DEFAULT 'customer' COMMENT 'customer / supplier / both',
    `phone`           VARCHAR(40)  NULL DEFAULT NULL,
    `address`         VARCHAR(300) NULL DEFAULT NULL,
    `note`            VARCHAR(300) NULL DEFAULT NULL,
    `opening_balance` BIGINT NOT NULL DEFAULT 0 COMMENT 'مثبت = او بدهکار است، منفی = فروشگاه بدهکار است',
    `is_active`       TINYINT(1) NOT NULL DEFAULT 1,
    `created_at`      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_biz_parties_user` (`user_id`, `is_active`, `name`),
    CONSTRAINT `fk_biz_parties_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`)
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_persian_ci;

CREATE TABLE IF NOT EXISTS `biz_accounts` (
    `id`              INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id`         INT UNSIGNED NOT NULL,
    `name`            VARCHAR(80) NOT NULL,
    `kind`            VARCHAR(20) NOT NULL DEFAULT 'cash' COMMENT 'cash / bank / pos',
    `opening_balance` BIGINT NOT NULL DEFAULT 0,
    `sort_order`      INT NOT NULL DEFAULT 0,
    `is_active`       TINYINT(1) NOT NULL DEFAULT 1,
    `created_at`      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_biz_accounts_user` (`user_id`, `is_active`, `sort_order`),
    CONSTRAINT `fk_biz_accounts_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`)
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_persian_ci;
