-- ============================================================
-- کد تخفیف، با سقفِ تعداد
-- ============================================================
--
-- دو کارِ جدا با یک جدول، و تفاوتشان فقط در درصد است:
--
--   percent = 100 → «دسترسی کامل، رایگان» برای `months` ماه.
--                   همان چیزی که برای «۱۰۰ نفر اول» لازم است: کاربر
--                   کد را وارد می‌کند و همان لحظه Pro می‌شود، بدون
--                   هیچ پرداختی و بدون دخالتِ مدیر.
--   percent < 100 → تخفیف روی قیمتِ خرید. کد همراهِ اعلامِ پرداخت
--                   ثبت می‌شود و مدیر مبلغِ تخفیف‌خورده را می‌بیند.
--
-- ⛔ چرا `used_count` یک ستون است و از روی `payments` شمرده نمی‌شود:
--    «۱۰ نفر اول» یعنی این عدد باید **دقیق** باشد، حتی وقتی دو نفر
--    هم‌زمان کد را می‌زنند. با شمردنِ ردیف‌های `payments` داخلِ تراکنش،
--    اسنپ‌شاتِ REPEATABLE READ می‌توانست ردیفِ تازه‌ی نفرِ دیگر را
--    نبیند و هر دو رد شوند — یعنی سقف بی‌صدا شکسته می‌شد. با یک
--    شمارنده روی همین ردیف، قفلِ `SELECT ... FOR UPDATE` روی خودِ کد
--    کافی است و هیچ ابهامی نمی‌ماند.
--
-- ⛔ و به همین دلیل، ردِ یک پرداختِ کددار شمارنده را **پس می‌دهد**
--    (`releaseDiscountUse()`). بدونش کدی که ۱۰ ظرفیت دارد با ۱۰
--    پرداختِ ردشده تمام می‌شد، بی‌آنکه حتی یک نفر چیزی گرفته باشد.
--
-- ⚠ `payments.discount_code` عمداً یک **رشته** است نه کلیدِ خارجی به
--   `discount_codes.id`: تاریخچه‌ی پرداخت باید بگوید کاربر «کدام کد»
--   را زده، حتی اگر مدیر بعداً آن کد را عوض کند. همان استدلالی که
--   `counterparty_name` را به‌جای کلیدِ `people` نگه داشت.
--
-- ⚠ مقایسه‌ی ستون‌به‌ستونِ `payments.discount_code` با
--   `discount_codes.code` هیچ‌جا انجام نمی‌شود (نه JOIN، نه IN):
--   دو ستون هر دو «قابلیتِ تبدیلِ ۲» دارند و اگر نصبی collation
--   متفاوتی داشته باشد، روی سرور با «Illegal mix of collations»
--   می‌مرد — همان چیزی که یک بار `FIND_IN_SET(name, @var)` را کشت.
--   کد همیشه به‌صورت پارامترِ bind شده مقایسه می‌شود.
--
-- ایدمپوتنت است: اجرای دوباره هیچ اثری ندارد.

CREATE TABLE IF NOT EXISTS `discount_codes` (
    `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    -- همیشه نرمال‌شده ذخیره می‌شود: لاتین، بزرگ، بدون فاصله
    -- (`normalizeDiscountCode()` تنها جای این کار است).
    `code`        VARCHAR(40) NOT NULL,
    -- ۱ تا ۱۰۰. مقدار ۱۰۰ یعنی رایگان.
    `percent`     TINYINT UNSIGNED NOT NULL DEFAULT 100,
    -- فقط برای کدِ ۱۰۰٪: چند ماه دسترسی. ۰ = مادام‌العمر
    -- (همان معنایی که `PLAN_GRANT_PERIODS` دارد).
    `months`      SMALLINT UNSIGNED NOT NULL DEFAULT 1,
    -- ۰ = بی‌نهایت. «۱۰ نفر اول» یعنی ۱۰.
    `max_uses`    INT UNSIGNED NOT NULL DEFAULT 0,
    `used_count`  INT UNSIGNED NOT NULL DEFAULT 0,
    `expires_at`  DATE NULL,
    `is_active`   TINYINT(1) NOT NULL DEFAULT 1,
    `note`        VARCHAR(255) NULL,
    `created_by`  INT UNSIGNED NULL,
    `created_at`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    -- ⛔ یکتایی روی خودِ کد، وگرنه دو ردیف با یک کد یعنی سقف دو برابر
    --    می‌شد و کدام‌یک اعمال می‌شود هم به ترتیبِ ردیف‌ها بند بود.
    UNIQUE KEY `uniq_discount_code` (`code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_persian_ci;

-- ---------- payments.discount_code ----------
-- ⚠ این **آخرین** کارِ فایل است و شاهدِ migration هم همین است: اگر
--   ستون باشد یعنی کلِ فایل اجرا شده، نه فقط نیمه‌ی اولش.
ALTER TABLE `payments`
    ADD COLUMN IF NOT EXISTS `discount_code` VARCHAR(40) NULL
        COMMENT 'کدِ تخفیفِ به‌کاررفته، به‌صورت نرمال‌شده' AFTER `method`;

SELECT 'migration_discount_codes با موفقیت اجرا شد.' AS result;
