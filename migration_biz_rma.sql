-- ============================================================
-- migration_biz_rma.sql — گوشی‌هایی که به گارانتیِ پخش‌کننده فرستاده شده (مهر ۱۴۰۵)
--
-- خواسته‌ی مالکِ نصب: گوشیِ خرابی که به گارانتیِ پخش می‌رود در دفترچه گم نشود — کِی رفت، پیشِ کدام
-- شرکت است، کِی برگشت، تعمیر شد یا تعویض، و هشدار وقتی دیر کرده.
--
-- ⛔ فقط پیگیری است (`BizRma`): نه پول، نه حرکتِ انبار. تعویضِ گوشیِ انبار با IMEIِ تازه همچنان
--    با سندِ خرید/برگشت انجام می‌شود؛ این‌جا فقط IMEIِ تازه یادداشت می‌شود.
-- ایدمپوتنت (IF NOT EXISTS).
-- ============================================================

CREATE TABLE IF NOT EXISTS `biz_rma` (
    `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id`     INT UNSIGNED NOT NULL,
    `number`      INT UNSIGNED NOT NULL,
    `party_id`    INT UNSIGNED NOT NULL COMMENT 'پخش‌کننده / شرکتِ گارانتی',
    `repair_id`   INT UNSIGNED NULL DEFAULT NULL COMMENT 'قبضِ تعمیرِ مشتری، اگر از آن‌جا آمده',
    `source`      VARCHAR(10)  NOT NULL DEFAULT 'customer' COMMENT 'customer | stock',
    `device`      VARCHAR(120) NOT NULL,
    `imei`        VARCHAR(20)  NULL DEFAULT NULL,
    `problem`     VARCHAR(500) NOT NULL,
    `tracking`    VARCHAR(60)  NULL DEFAULT NULL COMMENT 'شماره‌ی پذیرش/رهگیریِ شرکت',
    `status`      VARCHAR(12)  NOT NULL DEFAULT 'sent' COMMENT 'BizRma::STATUSES',
    `sent_on`     DATE NOT NULL,
    `returned_on` DATE NULL DEFAULT NULL,
    `new_imei`    VARCHAR(20)  NULL DEFAULT NULL COMMENT 'IMEIِ دستگاهِ تعویضی',
    `note`        VARCHAR(500) NULL DEFAULT NULL,
    `created_by`  INT UNSIGNED NULL DEFAULT NULL,
    `created_at`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_biz_rma_no` (`user_id`, `number`),
    KEY `idx_biz_rma_status` (`user_id`, `status`, `sent_on`),
    KEY `idx_biz_rma_imei` (`user_id`, `imei`),
    CONSTRAINT `fk_biz_rma_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT `fk_biz_rma_party` FOREIGN KEY (`party_id`) REFERENCES `biz_parties` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_persian_ci;

SELECT 'migration_biz_rma با موفقیت اجرا شد.' AS result;
