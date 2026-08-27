<?php
/**
 * فایل تنظیمات دیتابیس
 * این فایل را کپی کرده و به نام config.php ذخیره کنید (در همین پوشه: config/config.php)
 * سپس مقادیر زیر را با اطلاعات هاست خودتان جایگزین کنید
 */

define('DB_HOST', 'localhost');
define('DB_NAME', 'your_database');
define('DB_USER', 'your_username');
define('DB_PASSWORD', 'your_password');
define('DB_CHARSET', 'utf8mb4');

define('APP_NAME', 'دفتر مالی');
define('APP_TIMEZONE', 'Asia/Tehran');
define('APP_CURRENCY', 'تومان');

define('APP_SECRET_KEY', 'CHANGE_THIS_TO_A_RANDOM_SECRET_STRING_1234567890');

define('APP_FORCE_HTTPS', false);

/**
 * اگر پروژه را مستقیم روی ریشه دامنه/ساب‌دامنه نصب کرده‌اید، این مقدار را خالی بگذارید.
 * اگر پروژه داخل یک زیرپوشه نصب شده (مثلاً https://example.com/daftar)، مسیر را اینجا بنویسید: '/daftar'
 * این مقدار برای درست‌کار کردن لینک‌ها و فایل‌های CSS/JS در صفحات پنل مدیریت (admin/) لازم است.
 */
define('APP_BASE_PATH', '');
