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

/**
 * آدرس مطلق اپ — برای لینک‌هایی که در ایمیل می‌روند.
 *
 * ⚠️ این را حتماً پر کنید. اگر خالی بماند، لینک بازیابی رمز از روی
 * سرآیند Host درخواست ساخته می‌شود و آن سرآیند را خودِ درخواست‌کننده
 * تعیین می‌کند — یعنی کسی می‌تواند کاری کند که لینک بازیابیِ حساب شما
 * به سایت او اشاره کند.
 */
define('APP_URL', 'https://example.com');

/**
 * ارسال ایمیل — برای بازیابی رمز.
 *
 * MAIL_METHOD یکی از این‌ها:
 *   ''      خاموش. صفحه‌ی بازیابی می‌گوید در دسترس نیست.
 *   'mail'  تابع mail() خود PHP. نیاز به MTA روی سرور دارد و روی
 *           VPS تازه معمولاً یا نصب نیست یا ایمیلش به اسپم می‌رود.
 *   'smtp'  اتصال به یک سرور SMTP. قابل اتکاتر.
 */
define('MAIL_METHOD', '');

define('MAIL_FROM', 'no-reply@example.com');
define('MAIL_FROM_NAME', 'دفتر مالی');

// فقط وقتی MAIL_METHOD = 'smtp'
define('SMTP_HOST', '');
define('SMTP_PORT', 587);
define('SMTP_SECURE', 'tls');   // tls | ssl | none
define('SMTP_USER', '');
define('SMTP_PASS', '');
define('SMTP_EHLO', 'example.com');
