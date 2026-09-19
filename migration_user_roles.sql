-- ⛔ نقشِ محدود (پشتیبان) و نقشِ برچسبی (همکار)
--
-- **خواسته‌ی مالکِ نصب:** «امکان اضافه کردن ادمین با دسترسی محدود
-- مثلاً پشتیبانی هم اضافه بشه از خود یوزرهای قبلی قابل انتخاب باشه» و
-- «یک گزینه همکار هم می‌خوام که همین ۴ نفری که حسابداری به حسابشون
-- وصل شده در اون دسته قرار می‌گیرن».
--
-- ⛔ ستون از `ENUM('admin','user')` به `VARCHAR(20)` می‌رود، نه به یک
--    ENUM بزرگ‌تر. همان استدلالِ `payments.method` و
--    `support_tickets.status`: نقشِ تازه‌ی فردا نباید `ALTER TABLE`
--    بخواهد. فهرستِ مجاز در `Auth::ROLES` است و هر مسیرِ نوشتن از
--    همان‌جا رد می‌شود.
--
-- ⚠ ایدمپوتنت: اگر ستون از قبل `VARCHAR` باشد کاری نمی‌کند. بدونِ این
--   شرط، اجرای دوباره روی نصبِ به‌روز فقط هزینه‌ی یک بازسازیِ جدول
--   بود — بی‌ضرر ولی بی‌دلیل.
--
-- ⚠ هیچ ردیفی مقدارش عوض نمی‌شود: `admin` همان `admin` می‌ماند و
--   `user` همان `user`. پس روی نصبِ موجود هیچ‌کس نه دسترسی می‌گیرد نه
--   از دست می‌دهد — و این دقیقاً همان چیزی است که یک migration باید
--   تضمین کند.

SET @has_varchar := (
    SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE()
      AND table_name   = 'users'
      AND column_name  = 'role'
      AND data_type    = 'varchar'
);

SET @sql := IF(@has_varchar = 0,
    'ALTER TABLE `users` MODIFY `role` VARCHAR(20) NOT NULL DEFAULT ''user''',
    'SELECT ''skipped'' AS note, ''users.role از قبل VARCHAR است'' AS reason'
);
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;

SELECT 'migration_user_roles با موفقیت اجرا شد.' AS result;
