-- ============================================================
-- محیطِ فروشگاهی — مرحله‌ی ۳: اسناد (فاکتور، برگشت، دریافت و پرداخت)
-- و دسته‌بندیِ کالا
-- ============================================================
--
-- **خواسته‌ی مالکِ نصب:** «مدل برنامه‌ی حسابداری، صدور فاکتور خرید و فروش
-- در دسترس باشه و امکاناتِ پایه‌ی فروشگاهی صفر تا صد: خرید، فروش، مرجوعی،
-- ایجادِ دسته‌بندی و …»
--
-- ⛔ فقط افزودنی و فقط `biz_*`. هیچ جدولِ حساب لندِ شخصی دست نمی‌خورد.
--
-- ۱. `biz_categories` — دسته‌ی کالا به‌عنوانِ موجودیتِ واقعی (نه متنِ آزاد).
--    `biz_products.category_id` به آن اشاره می‌کند؛ ستونِ متنیِ قدیمیِ
--    `category` فقط یک بار (با نشانه‌ی `biz_categories_linked`) منتقل
--    می‌شود و از آن به بعد خوانده نمی‌شود — وگرنه اجرای دوباره‌ی این فایل
--    دسته‌ای را که کاربر عمداً از کالا برداشته برمی‌گرداند.
-- ۲. `biz_invoices` + `biz_invoice_lines` — چهار نوعِ سند در یک جدول:
--    `sale`، `purchase`، `sale_return`، `purchase_return`. وضعیت:
--    `draft` (هیچ اثری ندارد) / `issued` (موجودی و مانده را عوض می‌کند) /
--    `void` (اثرش برگشته، ولی شماره و ردش می‌ماند).
--    `paid` کش است و فقط `BizPay::reallocate()` می‌نویسدش.
-- ۳. `biz_payments` + `biz_allocations` — دریافت، پرداخت، هزینه، درآمدِ
--    متفرقه و انتقالِ بینِ صندوق‌ها. موجودیِ صندوق و مانده‌ی طرف‌حساب از
--    همین جمع زده می‌شوند (`BizCash::BALANCE_SQL`، `BizParties::BALANCE_SQL`).
--    تخصیص به فاکتور فقط «تسویه‌شده؟»ِ فاکتور را می‌سازد، نه هیچ مانده‌ای.
--
-- ⚠ `user_id` و کلیدهای خارجی `INT UNSIGNED` (تله‌ی errno 150).
-- ⛔ دو ارجاعِ «برگشت ← اصل» (`ref_invoice_id`، `ref_line_id`) `SET NULL`اند نه
--    `RESTRICT`: سندِ صادرشده هرگز حذف نمی‌شود (فقط پیش‌نویس، و برگشت هرگز به
--    پیش‌نویس اشاره نمی‌کند)، پس تنها حذفی که به آن‌ها می‌رسد **حذفِ کاملِ
--    حساب** است — و با `RESTRICT` همان `DELETE`ِ چندردیفی روی خودِ جدول
--    شکست می‌خورد و `deleteUserAccount()` برای هر فروشگاهی که یک برگشت دارد
--    ناممکن می‌شد (آزموده شد).
-- ⚠ همه‌ی جدول‌ها صریح `utf8mb4_persian_ci`اند، پس JOINِ نامِ دسته بینِ
--   `biz_products.category` و `biz_categories.name` مقایسه‌ی دو ستونِ
--   هم‌collation است و «Illegal mix» نمی‌دهد.
--
-- ⛔ شاهدِ `migrate.sh`: `app_settings~setting_key=biz_categories_linked` —
--    آخرین دستورِ فایل.
-- ایدمپوتنت.

CREATE TABLE IF NOT EXISTS `biz_categories` (
    `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id`    INT UNSIGNED NOT NULL,
    `name`       VARCHAR(80) NOT NULL,
    `sort_order` INT NOT NULL DEFAULT 0,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_biz_categories_name` (`user_id`, `name`),
    CONSTRAINT `fk_biz_categories_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`)
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_persian_ci;

ALTER TABLE `biz_products`
    ADD COLUMN IF NOT EXISTS `category_id` INT UNSIGNED NULL DEFAULT NULL AFTER `category`;

-- ⚠ کلیدِ خارجی جدا، چون `ADD CONSTRAINT IF NOT EXISTS` روی MariaDB 10.11 هست
--   ولی روی نصب‌های قدیمی‌تر نه؛ نامِ ایندکس هم جدا تا اجرای دوباره نخورد.
ALTER TABLE `biz_products`
    ADD INDEX IF NOT EXISTS `idx_biz_products_cat` (`user_id`, `category_id`);
ALTER TABLE `biz_products`
    ADD CONSTRAINT `fk_biz_products_cat` FOREIGN KEY IF NOT EXISTS (`category_id`) REFERENCES `biz_categories` (`id`)
        ON DELETE SET NULL ON UPDATE CASCADE;

CREATE TABLE IF NOT EXISTS `biz_invoices` (
    `id`             INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id`        INT UNSIGNED NOT NULL,
    `kind`           VARCHAR(20) NOT NULL COMMENT 'sale / purchase / sale_return / purchase_return',
    `number`         INT UNSIGNED NULL DEFAULT NULL COMMENT 'هنگامِ صدور داده می‌شود؛ پیش‌نویس ندارد',
    `status`         VARCHAR(12) NOT NULL DEFAULT 'draft' COMMENT 'draft / issued / void',
    `party_id`       INT UNSIGNED NULL DEFAULT NULL COMMENT 'NULL = مشتری/فروشنده‌ی گذری (فقط با تسویه‌ی کامل)',
    `ref_invoice_id` INT UNSIGNED NULL DEFAULT NULL COMMENT 'برگشت: فاکتورِ اصلی',
    `inv_date`       DATE NOT NULL,
    `due_date`       DATE NULL DEFAULT NULL,
    `subtotal`       BIGINT NOT NULL DEFAULT 0 COMMENT 'جمعِ ردیف‌ها پس از تخفیفِ ردیف',
    `discount`       BIGINT NOT NULL DEFAULT 0 COMMENT 'تخفیفِ کلِ فاکتور',
    `extra`          BIGINT NOT NULL DEFAULT 0 COMMENT 'حمل و هزینه‌های دیگر',
    `total`          BIGINT NOT NULL DEFAULT 0,
    `paid`           BIGINT NOT NULL DEFAULT 0 COMMENT 'کش؛ فقط BizPay::reallocate',
    `note`           VARCHAR(300) NULL DEFAULT NULL,
    `issued_at`      DATETIME NULL DEFAULT NULL,
    `voided_at`      DATETIME NULL DEFAULT NULL,
    `created_at`     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_biz_invoices_number` (`user_id`, `kind`, `number`),
    KEY `idx_biz_invoices_list` (`user_id`, `kind`, `status`, `inv_date`),
    KEY `idx_biz_invoices_party` (`party_id`, `status`),
    KEY `idx_biz_invoices_ref` (`ref_invoice_id`),
    CONSTRAINT `fk_biz_invoices_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`)
        ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT `fk_biz_invoices_party` FOREIGN KEY (`party_id`) REFERENCES `biz_parties` (`id`)
        ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT `fk_biz_invoices_ref` FOREIGN KEY (`ref_invoice_id`) REFERENCES `biz_invoices` (`id`)
        ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_persian_ci;

CREATE TABLE IF NOT EXISTS `biz_invoice_lines` (
    `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id`     INT UNSIGNED NOT NULL,
    `invoice_id`  INT UNSIGNED NOT NULL,
    `line_no`     SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    `product_id`  INT UNSIGNED NULL DEFAULT NULL COMMENT 'NULL = شرحِ آزاد (بی‌اثر بر موجودی)',
    `ref_line_id` INT UNSIGNED NULL DEFAULT NULL COMMENT 'برگشت: ردیفِ فاکتورِ اصلی',
    `description` VARCHAR(200) NOT NULL,
    `unit`        VARCHAR(20) NOT NULL DEFAULT 'عدد',
    `qty`         DECIMAL(14,3) NOT NULL,
    `unit_price`  BIGINT NOT NULL DEFAULT 0,
    `line_discount` BIGINT NOT NULL DEFAULT 0,
    `line_total`  BIGINT NOT NULL DEFAULT 0 COMMENT 'qty × price − تخفیفِ ردیف',
    `net_total`   BIGINT NOT NULL DEFAULT 0 COMMENT 'سهمِ ردیف پس از تخفیف/حملِ کلِ فاکتور',
    `unit_cost`   BIGINT NULL DEFAULT NULL COMMENT 'بهای تمام‌شده هنگامِ صدور — برای سودِ ناخالص',
    PRIMARY KEY (`id`),
    KEY `idx_biz_lines_invoice` (`invoice_id`, `line_no`),
    KEY `idx_biz_lines_product` (`user_id`, `product_id`),
    KEY `idx_biz_lines_ref` (`ref_line_id`),
    CONSTRAINT `fk_biz_lines_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`)
        ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT `fk_biz_lines_invoice` FOREIGN KEY (`invoice_id`) REFERENCES `biz_invoices` (`id`)
        ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT `fk_biz_lines_product` FOREIGN KEY (`product_id`) REFERENCES `biz_products` (`id`)
        ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT `fk_biz_lines_ref` FOREIGN KEY (`ref_line_id`) REFERENCES `biz_invoice_lines` (`id`)
        ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_persian_ci;

CREATE TABLE IF NOT EXISTS `biz_payments` (
    `id`             INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id`        INT UNSIGNED NOT NULL,
    `kind`           VARCHAR(12) NOT NULL COMMENT 'receipt / payment / expense / income / transfer',
    `number`         INT UNSIGNED NOT NULL,
    `status`         VARCHAR(8) NOT NULL DEFAULT 'ok' COMMENT 'ok / void',
    `party_id`       INT UNSIGNED NULL DEFAULT NULL,
    `account_id`     INT UNSIGNED NOT NULL COMMENT 'صندوقی که پول از آن/به آن می‌رود',
    `to_account_id`  INT UNSIGNED NULL DEFAULT NULL COMMENT 'فقط انتقال',
    `invoice_id`     INT UNSIGNED NULL DEFAULT NULL COMMENT 'فاکتورِ ترجیحی برای تخصیص',
    `origin_invoice` TINYINT(1) NOT NULL DEFAULT 0 COMMENT '۱ = هنگامِ صدورِ همان فاکتور ساخته شد',
    `amount`         BIGINT NOT NULL,
    `pay_date`       DATE NOT NULL,
    `method`         VARCHAR(12) NOT NULL DEFAULT 'cash' COMMENT 'cash / card / transfer / cheque',
    `title`          VARCHAR(150) NULL DEFAULT NULL COMMENT 'شرحِ هزینه/درآمد',
    `note`           VARCHAR(300) NULL DEFAULT NULL,
    `allocated`      BIGINT NOT NULL DEFAULT 0 COMMENT 'کش؛ فقط BizPay::reallocate',
    `created_at`     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `voided_at`      DATETIME NULL DEFAULT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_biz_payments_number` (`user_id`, `kind`, `number`),
    KEY `idx_biz_payments_list` (`user_id`, `status`, `pay_date`),
    KEY `idx_biz_payments_party` (`party_id`, `status`),
    KEY `idx_biz_payments_account` (`account_id`, `status`),
    KEY `idx_biz_payments_to` (`to_account_id`, `status`),
    KEY `idx_biz_payments_invoice` (`invoice_id`),
    CONSTRAINT `fk_biz_payments_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`)
        ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT `fk_biz_payments_party` FOREIGN KEY (`party_id`) REFERENCES `biz_parties` (`id`)
        ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT `fk_biz_payments_account` FOREIGN KEY (`account_id`) REFERENCES `biz_accounts` (`id`)
        ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT `fk_biz_payments_to` FOREIGN KEY (`to_account_id`) REFERENCES `biz_accounts` (`id`)
        ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT `fk_biz_payments_invoice` FOREIGN KEY (`invoice_id`) REFERENCES `biz_invoices` (`id`)
        ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_persian_ci;

CREATE TABLE IF NOT EXISTS `biz_allocations` (
    `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id`    INT UNSIGNED NOT NULL,
    `payment_id` INT UNSIGNED NOT NULL,
    `invoice_id` INT UNSIGNED NOT NULL,
    `amount`     BIGINT NOT NULL,
    PRIMARY KEY (`id`),
    KEY `idx_biz_alloc_payment` (`payment_id`),
    KEY `idx_biz_alloc_invoice` (`invoice_id`),
    CONSTRAINT `fk_biz_alloc_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`)
        ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT `fk_biz_alloc_payment` FOREIGN KEY (`payment_id`) REFERENCES `biz_payments` (`id`)
        ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT `fk_biz_alloc_invoice` FOREIGN KEY (`invoice_id`) REFERENCES `biz_invoices` (`id`)
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_persian_ci;

-- ⛔ انتقالِ یک‌باره‌ی دسته‌های متنی — فقط اگر نشانه هنوز نیست.
INSERT IGNORE INTO `biz_categories` (`user_id`, `name`)
SELECT DISTINCT p.`user_id`, p.`category`
FROM `biz_products` p
WHERE p.`category` IS NOT NULL AND p.`category` <> ''
  AND NOT EXISTS (SELECT 1 FROM `app_settings` s WHERE s.`setting_key` = 'biz_categories_linked');

UPDATE `biz_products` p
JOIN `biz_categories` c ON c.`user_id` = p.`user_id` AND c.`name` = p.`category`
SET p.`category_id` = c.`id`, p.`category` = NULL
WHERE p.`category_id` IS NULL
  AND NOT EXISTS (SELECT 1 FROM `app_settings` s WHERE s.`setting_key` = 'biz_categories_linked');

INSERT IGNORE INTO `app_settings` (`setting_key`, `setting_value`) VALUES ('biz_categories_linked', '1');
