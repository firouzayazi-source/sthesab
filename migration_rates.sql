-- ============================================================
-- نرخِ روزِ ارز، طلا و سکه — برای کلِ برنامه (شخصی و فروشگاهی)
-- ============================================================
--
-- **خواسته‌ی مالکِ نصب:** «امکانِ استفاده از API نرخِ دلار و طلا در کلِ
-- پروژه… برای کسانی که دارایی براساسِ دلار یا طلا یا سکه دارن ارزشِ
-- دارایی به‌روزشون حساب بشه و در فروشگاه هم برای تعیینِ قیمتِ کالاها…
-- این بخش رو مختص به این APIها نساز و کلی و دقیق و راحت بساز.»
--
-- ⛔ نرخ‌ها **سراسری**اند (یک دلار برای همه)، نه مالِ کاربر: `market_rates`
--    ستونِ `user_id` ندارد. فقط cron و مدیر می‌نویسند (`Rates::refresh()`،
--    `Rates::setManual()`)؛ کاربر فقط نوعِ دارایی یا کالای **خودش** را به
--    یک کد وصل می‌کند.
-- ⛔ `rate_sources` تنظیمِ هر منبع است (روشن/خاموش، ترتیب، کلیدِ رمزشده،
--    آدرسِ جایگزین، ریال/تومان) — منبع‌ها در کدند (`Rates::PROVIDERS`)،
--    پس افزودنِ منبعِ تازه migration نمی‌خواهد.
-- ایدمپوتنت.

CREATE TABLE IF NOT EXISTS `market_rates` (
    `code`       VARCHAR(20)  NOT NULL COMMENT 'Rates::CODES',
    `price`      BIGINT UNSIGNED NOT NULL COMMENT 'تومان برای هر واحد',
    `prev_price` BIGINT UNSIGNED NULL,
    `source`     VARCHAR(40)  NOT NULL,
    `manual`     TINYINT(1)   NOT NULL DEFAULT 0 COMMENT '۱ = مدیر دستی گذاشته؛ دریافتِ خودکار رویش نمی‌نویسد',
    `fetched_at` DATETIME     NOT NULL,
    PRIMARY KEY (`code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_persian_ci;

CREATE TABLE IF NOT EXISTS `rate_sources` (
    `provider`    VARCHAR(20)  NOT NULL COMMENT 'Rates::PROVIDERS',
    `enabled`     TINYINT(1)   NOT NULL DEFAULT 0,
    `priority`    INT          NOT NULL DEFAULT 0,
    `api_key`     VARCHAR(600) NULL COMMENT 'رمزشده با Crypto',
    `url`         VARCHAR(500) NULL COMMENT 'آدرسِ جایگزین (واسط یا منبعِ دلخواه)',
    `paths`       TEXT         NULL COMMENT 'منبعِ دلخواه: JSON کد => مسیر',
    `in_rial`     TINYINT(1)   NOT NULL DEFAULT 0,
    `last_try_at` DATETIME     NULL,
    `last_ok_at`  DATETIME     NULL,
    `last_error`  VARCHAR(255) NULL,
    `month_key`   CHAR(7)      NULL,
    `month_calls` INT          NOT NULL DEFAULT 0,
    PRIMARY KEY (`provider`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_persian_ci;

-- نوعِ داراییِ کاربر → نرخِ خودکار (NULL = نرخِ دستیِ خودِ کاربر، مثلِ قبل)
ALTER TABLE `asset_types`
    ADD COLUMN IF NOT EXISTS `rate_code` VARCHAR(20) NULL DEFAULT NULL
        COMMENT 'Rates::CODES — current_price را Rates::applyToAssets() می‌نویسد';

-- کالای فروشگاه → قیمتِ فروش از نرخِ روز
ALTER TABLE `biz_products`
    ADD COLUMN IF NOT EXISTS `rate_code`   VARCHAR(20)   NULL DEFAULT NULL COMMENT 'Rates::CODES',
    ADD COLUMN IF NOT EXISTS `rate_base`   DECIMAL(18,4) NULL DEFAULT NULL COMMENT 'قیمتِ پایه به واحدِ همان نرخ',
    ADD COLUMN IF NOT EXISTS `rate_margin` DECIMAL(7,2)  NULL DEFAULT NULL COMMENT 'درصدِ سود روی پایه';

-- گرد کردنِ قیمتِ ارزی در هر فروشگاه (تومان)
ALTER TABLE `biz_settings`
    ADD COLUMN IF NOT EXISTS `rate_round` INT NOT NULL DEFAULT 1000;
