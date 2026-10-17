-- ============================================================
-- migration_biz_stock_count.sql — انبارگردانی با اسکن (مهر ۱۴۰۵)
--
-- خواسته‌ی مالکِ نصب: قفسه به قفسه با دوربین یا اسکنرِ دستی بشماریم؛ آخرِ کار «کم است»، «اضافه است» و
-- «IMEIِ ناشناس» بیاید و اصلاح فقط با تأیید انجام شود.
--
-- ⛔ شمارش خودش هیچ اثری بر موجودی ندارد (`BizCount`). اعمال فقط با تأییدِ صریح و فقط از همان مسیرِ
--    انبارگردانیِ موجود (`BizStock::adjustTo()`)؛ گوشیِ IMEIدار هرگز خودکار اصلاح نمی‌شود — فقط گزارش.
-- ایدمپوتنت (IF NOT EXISTS).
-- ============================================================

CREATE TABLE IF NOT EXISTS `biz_stock_counts` (
    `id`           INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id`      INT UNSIGNED NOT NULL,
    `number`       INT UNSIGNED NOT NULL,
    `warehouse_id` INT UNSIGNED NULL DEFAULT NULL COMMENT 'NULL = انبارِ اصلی',
    `status`       VARCHAR(10) NOT NULL DEFAULT 'open' COMMENT 'open | applied | cancelled',
    `note`         VARCHAR(200) NULL DEFAULT NULL,
    `created_by`   INT UNSIGNED NULL DEFAULT NULL,
    `created_at`   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `applied_at`   DATETIME NULL DEFAULT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_biz_count_no` (`user_id`, `number`),
    KEY `idx_biz_count_status` (`user_id`, `status`),
    CONSTRAINT `fk_biz_count_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_persian_ci;

CREATE TABLE IF NOT EXISTS `biz_stock_count_lines` (
    `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id`    INT UNSIGNED NOT NULL,
    `count_id`   INT UNSIGNED NOT NULL,
    `product_id` INT UNSIGNED NULL DEFAULT NULL COMMENT 'NULL = کدِ ناشناس',
    `imei`       VARCHAR(20) NULL DEFAULT NULL,
    `code`       VARCHAR(60) NOT NULL COMMENT 'همان چیزی که اسکن شد',
    `qty`        DECIMAL(14,3) NOT NULL DEFAULT 1,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_biz_count_imei` (`count_id`, `imei`),
    KEY `idx_biz_count_lines` (`count_id`, `product_id`),
    CONSTRAINT `fk_biz_count_line_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT `fk_biz_count_line_count` FOREIGN KEY (`count_id`) REFERENCES `biz_stock_counts` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT `fk_biz_count_line_product` FOREIGN KEY (`product_id`) REFERENCES `biz_products` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_persian_ci;

SELECT 'migration_biz_stock_count با موفقیت اجرا شد.' AS result;
