-- ============================================================
-- migration_biz_bundles.sql — بسته‌ی فروش (مهر ۱۴۰۵)
--
-- خواسته‌ی مالکِ نصب: «پکِ لوازم» (قاب + گلس + شارژر) با یک قیمتِ یک‌جا فروخته شود و موجودیِ هر قلم
-- جدا کم شود.
--
-- ⛔ بسته کالا نیست و موجودی ندارد: فقط الگوست (`BizBundles`). هنگامِ ساختِ فاکتورِ فروش
--    (`BizInvoices::saveDraft()`) ردیفِ بسته به ردیف‌های واقعیِ اجزایش باز می‌شود و قیمتِ یک‌جا میانِ
--    آن‌ها پخش می‌شود — فاکتور، موجودی، سود و برگشت همان مسیرِ همیشه‌اند. گوشیِ IMEIدار جزءِ بسته
--    نمی‌شود (IMEIاش را باید روی همان ردیف نوشت).
-- ایدمپوتنت (IF NOT EXISTS).
-- ============================================================

CREATE TABLE IF NOT EXISTS `biz_bundles` (
    `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id`    INT UNSIGNED NOT NULL,
    `name`       VARCHAR(150) NOT NULL,
    `sku`        VARCHAR(60)  NULL DEFAULT NULL,
    `price`      BIGINT UNSIGNED NOT NULL DEFAULT 0 COMMENT '۰ = جمعِ قیمتِ فروشِ اجزا',
    `is_active`  TINYINT(1) NOT NULL DEFAULT 1,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_biz_bundle_name` (`user_id`, `name`),
    KEY `idx_biz_bundle_sku` (`user_id`, `sku`),
    CONSTRAINT `fk_biz_bundle_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_persian_ci;

CREATE TABLE IF NOT EXISTS `biz_bundle_items` (
    `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id`    INT UNSIGNED NOT NULL,
    `bundle_id`  INT UNSIGNED NOT NULL,
    `product_id` INT UNSIGNED NOT NULL,
    `qty`        DECIMAL(14,3) NOT NULL DEFAULT 1,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_biz_bundle_item` (`bundle_id`, `product_id`),
    CONSTRAINT `fk_biz_bundle_item_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT `fk_biz_bundle_item_bundle` FOREIGN KEY (`bundle_id`) REFERENCES `biz_bundles` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT `fk_biz_bundle_item_product` FOREIGN KEY (`product_id`) REFERENCES `biz_products` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_persian_ci;

SELECT 'migration_biz_bundles با موفقیت اجرا شد.' AS result;
