-- ⛔ انتخابِ کاربر از اینکه کدام دسته‌بندی‌ها روی فرمِ ثبت چیپ بگیرند.
--
-- چرا جدولِ جدا و نه ستونی روی `categories`:
--   دسته‌ی پیش‌فرضِ برنامه `user_id IS NULL` دارد و **بینِ همه‌ی کاربران
--   مشترک** است. ستونِ `pinned` روی همان ردیف یعنی پین کردنِ «خوراکی»
--   توسطِ یک نفر، آن را برای **همه** پین می‌کرد — یک نشتیِ بی‌صدا بینِ
--   کاربرها، دقیقاً همان چیزی که قاعده‌ی «جداسازی کاربران» منع می‌کند.
--   `wallets.pinned` می‌توانست ستون باشد چون هر حساب فقط یک مالک دارد.
--
-- ⚠ کلیدِ اصلی مرکب است، پس پینِ تکراری ممکن نیست و هیچ کدِ مصرف‌کننده‌ای
--   لازم نیست نگرانِ ردیفِ دوتایی باشد.
--
-- ⚠ هر دو کلیدِ خارجی `ON DELETE CASCADE` اند: حذفِ کاربر یا حذفِ دسته
--   پینش را هم می‌برد، وگرنه یک ردیفِ یتیم می‌ماند که به هیچ‌جا اشاره
--   نمی‌کند — همان «حذفی که چیزی جا می‌گذارد».
CREATE TABLE IF NOT EXISTS `category_pins` (
    `user_id`     INT UNSIGNED NOT NULL,
    `category_id` INT UNSIGNED NOT NULL,
    `created_at`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`user_id`, `category_id`),
    KEY `idx_catpin_cat` (`category_id`),
    CONSTRAINT `fk_catpin_user` FOREIGN KEY (`user_id`)
        REFERENCES `users` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_catpin_cat` FOREIGN KEY (`category_id`)
        REFERENCES `categories` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_persian_ci;
