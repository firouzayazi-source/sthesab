-- ============================================================
-- محیطِ فروشگاهی — لایه‌ی حسابداری (نگاهِ حسابدار)
-- ============================================================
--
-- **خواسته‌ی مالکِ نصب:** «اگه به عنوان یک حسابدار بخونی … چه مشکلاتی
-- می‌بینی؟» ← «همه رو بساز، تست کن.» (مهر ۱۴۰۵)
--
-- نُه شکافِ آن بازبینی، یک‌جا:
--   ۱. مالیات بر ارزش افزوده — نرخِ فروشگاه، معافیتِ کالا، و روی هر سند
--      «عکسِ» نرخ (`vat_rate`) و مبلغ (`tax_total`، `tax_amount`ِ هر ردیف).
--      ⛔ `net_total`ِ ردیف **بی‌مالیات** می‌ماند (درآمد و بهای تمام‌شده‌ی خرید
--         از آن ساخته می‌شوند) و `total`ِ سند **با** مالیات (مانده‌ی طرف‌حساب).
--   ۲. سامانه‌ی مودیان — شناسه‌ی حافظه و شناسه‌ی کالا/خدمتِ پیش‌فرض، و
--      `tax_code`ِ هر کالا.
--   ۳. دفترِ دوطرفه — جدول ندارد: از همین اسناد ساخته می‌شود (`BizLedger`).
--   ۴. آورده و برداشتِ مالک — دو نوعِ تازه‌ی `biz_payments.kind` (ستون
--      VARCHAR(12) است؛ ساختار عوض نمی‌شود).
--   ۵. سرفصلِ هزینه/درآمد — `biz_expense_cats` و `biz_payments.category_id`.
--   ۶. بستنِ سالِ مالی — `biz_year_closes` (عکسِ ترازِ پایانِ سال).
--   ۷. سرگذشتِ سند — `biz_doc_log` (چه کسی، کِی، چه کرد، و عکسِ پیش از آن).
--   ۸. شمارشِ صندوق — `biz_cash_counts` (کسری/اضافه به سرفصلِ سیستمی).
--   ۹. حقوق و مساعده — طرف‌حسابِ نوعِ «کارمند» (`biz_parties.kind` VARCHAR(20)).
--
-- ایدمپوتنت. شاهد: ردیفِ `app_settings` در آخرِ فایل.

ALTER TABLE `biz_settings`
    ADD COLUMN IF NOT EXISTS `vat_rate` DECIMAL(5,2) NOT NULL DEFAULT 0
        COMMENT 'نرخِ مالیات بر ارزش افزوده (٪)؛ ۰ = خاموش — BizVat',
    ADD COLUMN IF NOT EXISTS `moadian_memory_id` VARCHAR(10) NULL DEFAULT NULL
        COMMENT 'شناسه‌ی یکتای حافظه‌ی مالیاتی — BizMoadian',
    ADD COLUMN IF NOT EXISTS `moadian_sstid` VARCHAR(13) NULL DEFAULT NULL
        COMMENT 'شناسه‌ی کالا/خدمتِ پیش‌فرض (۱۳ رقم)';

ALTER TABLE `biz_products`
    ADD COLUMN IF NOT EXISTS `vat_exempt` TINYINT(1) NOT NULL DEFAULT 0
        COMMENT '۱ = معاف از مالیات بر ارزش افزوده',
    ADD COLUMN IF NOT EXISTS `tax_code` VARCHAR(13) NULL DEFAULT NULL
        COMMENT 'شناسه‌ی کالا/خدمتِ سامانه‌ی مودیان';

ALTER TABLE `biz_invoices`
    ADD COLUMN IF NOT EXISTS `vat_rate` DECIMAL(5,2) NOT NULL DEFAULT 0
        COMMENT 'نرخِ همین سند (عکسِ نرخِ فروشگاه هنگامِ ساخت)',
    ADD COLUMN IF NOT EXISTS `tax_total` BIGINT NOT NULL DEFAULT 0
        COMMENT 'جمعِ مالیات؛ total = جمعِ net_total + tax_total';

ALTER TABLE `biz_invoice_lines`
    ADD COLUMN IF NOT EXISTS `tax_amount` BIGINT NOT NULL DEFAULT 0
        COMMENT 'مالیاتِ ردیف — روی net_total';

ALTER TABLE `biz_payments`
    ADD COLUMN IF NOT EXISTS `category_id` INT UNSIGNED NULL DEFAULT NULL
        COMMENT 'سرفصلِ هزینه/درآمد — biz_expense_cats';

CREATE TABLE IF NOT EXISTS `biz_expense_cats` (
    `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id`    INT UNSIGNED NOT NULL,
    `kind`       VARCHAR(8)   NOT NULL DEFAULT 'expense' COMMENT 'expense / income',
    `name`       VARCHAR(80)  NOT NULL,
    `sys_key`    VARCHAR(20)  NULL DEFAULT NULL COMMENT 'سرفصلِ سیستمی (حقوق، کسری/اضافه‌ی صندوق) — BizExpCats::SYSTEM',
    `sort_order` INT NOT NULL DEFAULT 0,
    `is_active`  TINYINT(1) NOT NULL DEFAULT 1,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_biz_expense_cats_name` (`user_id`, `kind`, `name`),
    KEY `idx_biz_expense_cats_sys` (`user_id`, `sys_key`),
    CONSTRAINT `fk_biz_expense_cats_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_persian_ci;

-- ⚠ دو ستونِ ارجاع با کلیدِ خارجی، نه «نوع + شناسه»: بازگرداندنِ بکاپ
--   شناسه‌ها را از نو می‌سازد و فقط ستونِ کلیدِ خارجی‌دار را نگاشت می‌کند
--   (`user_import.php`)؛ ارجاعِ چندریختی بعد از بازگرداندن به سندِ دیگری می‌خورد.
CREATE TABLE IF NOT EXISTS `biz_doc_log` (
    `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id`    INT UNSIGNED NOT NULL,
    `invoice_id` INT UNSIGNED NULL DEFAULT NULL,
    `payment_id` INT UNSIGNED NULL DEFAULT NULL,
    `action`     VARCHAR(20)  NOT NULL COMMENT 'BizLog::ACTIONS',
    `actor_id`   INT UNSIGNED NULL DEFAULT NULL,
    `amount`     BIGINT NULL DEFAULT NULL COMMENT 'مبلغِ سند پس از این کار',
    `snapshot`   MEDIUMTEXT NULL DEFAULT NULL COMMENT 'عکسِ سند پیش از این کار (JSON)',
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_biz_doc_log_inv` (`invoice_id`, `id`),
    KEY `idx_biz_doc_log_pay` (`payment_id`, `id`),
    KEY `idx_biz_doc_log_user` (`user_id`, `id`),
    CONSTRAINT `fk_biz_doc_log_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT `fk_biz_doc_log_inv` FOREIGN KEY (`invoice_id`) REFERENCES `biz_invoices` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT `fk_biz_doc_log_pay` FOREIGN KEY (`payment_id`) REFERENCES `biz_payments` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_persian_ci;

CREATE TABLE IF NOT EXISTS `biz_year_closes` (
    `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id`    INT UNSIGNED NOT NULL,
    `jyear`      SMALLINT UNSIGNED NOT NULL COMMENT 'سالِ شمسی',
    `from_date`  DATE NOT NULL,
    `to_date`    DATE NOT NULL,
    `profit`     BIGINT NOT NULL DEFAULT 0,
    `snapshot`   MEDIUMTEXT NULL DEFAULT NULL COMMENT 'تراز و ترازنامه‌ی پایانِ سال (JSON)',
    `closed_at`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_biz_year_closes` (`user_id`, `jyear`),
    CONSTRAINT `fk_biz_year_closes_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_persian_ci;

CREATE TABLE IF NOT EXISTS `biz_cash_counts` (
    `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id`     INT UNSIGNED NOT NULL,
    `account_id`  INT UNSIGNED NOT NULL,
    `count_date`  DATE NOT NULL,
    `system_balance` BIGINT NOT NULL,
    `counted`     BIGINT NOT NULL,
    `payment_id`  INT UNSIGNED NULL DEFAULT NULL COMMENT 'سندِ کسری/اضافه؛ NULL = برابر بود',
    `note`        VARCHAR(300) NULL DEFAULT NULL,
    `created_at`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_biz_cash_counts_acc` (`user_id`, `account_id`, `count_date`),
    CONSTRAINT `fk_biz_cash_counts_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT `fk_biz_cash_counts_acc` FOREIGN KEY (`account_id`) REFERENCES `biz_accounts` (`id`) ON UPDATE CASCADE,
    CONSTRAINT `fk_biz_cash_counts_pay` FOREIGN KEY (`payment_id`) REFERENCES `biz_payments` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_persian_ci;

-- کلیدِ خارجیِ سرفصل (بازگرداندنِ بکاپ شناسه را با همین نگاشت می‌کند). ⚠ MariaDB
-- `ADD CONSTRAINT IF NOT EXISTS` را برای کلیدِ خارجی نمی‌شناسد؛ پس با سنجش.
SET @fk := (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
            WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'biz_payments'
              AND CONSTRAINT_NAME = 'fk_biz_payments_cat');
SET @sql := IF(@fk = 0,
    'ALTER TABLE `biz_payments` ADD CONSTRAINT `fk_biz_payments_cat` FOREIGN KEY (`category_id`) REFERENCES `biz_expense_cats` (`id`) ON DELETE SET NULL ON UPDATE CASCADE',
    'SELECT 1');
PREPARE fkst FROM @sql; EXECUTE fkst; DEALLOCATE PREPARE fkst;

INSERT INTO `app_settings` (`setting_key`, `setting_value`)
VALUES ('biz_accounting_seeded', '1')
ON DUPLICATE KEY UPDATE `setting_key` = `setting_key`;

SELECT 'migration_biz_accounting با موفقیت اجرا شد.' AS result;
