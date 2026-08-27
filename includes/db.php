<?php
/**
 * فشرده‌سازی خروجی HTML — باید پیش از هر خروجی اجرا شود.
 *
 * نکته‌ی مهم: روی بیشتر هاست‌های اشتراکی output_buffering در php.ini
 * از پیش روشن است. اگر شرط «فقط وقتی بافری فعال نیست» بگذاریم،
 * فشرده‌سازی هرگز اجرا نمی‌شود و صفحه‌ها خام (چند برابر حجم) ارسال می‌شوند.
 * پس اینجا مستقیم zlib را روشن می‌کنیم که با بافر موجود هم کار می‌کند.
 */
function enableOutputCompression(): void
{
    static $done = false;
    if ($done) { return; }
    $done = true;

    if (headers_sent()) { return; }
    if (!extension_loaded('zlib')) { return; }

    $accept = $_SERVER['HTTP_ACCEPT_ENCODING'] ?? '';
    if (stripos($accept, 'gzip') === false) { return; }

    // اگر از قبل روشن است، دست نزن
    if (ini_get('zlib.output_compression')) { return; }

    // روش اول: zlib داخلی PHP (با output_buffering هم سازگار است)
    if (@ini_set('zlib.output_compression', '1') !== false) {
        @ini_set('zlib.output_compression_level', '5');
        return;
    }

    // روش دوم: هندلر دستی
    @ob_start('ob_gzhandler');
}

enableOutputCompression();

require_once __DIR__ . '/../config/config.php';

class Database
{
    private static ?PDO $instance = null;

    private function __construct()
    {
    }

    public static function getConnection(): PDO
    {
        if (self::$instance === null) {
            $dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=' . DB_CHARSET;

            $options = [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
                PDO::ATTR_PERSISTENT         => false,
            ];

            try {
                self::$instance = new PDO($dsn, DB_USER, DB_PASSWORD, $options);
            } catch (PDOException $e) {
                error_log('Database Connection Error: ' . $e->getMessage());
                http_response_code(500);
                die('خطا در اتصال به دیتابیس. لطفاً تنظیمات config.php را بررسی کنید.');
            }
        }

        return self::$instance;
    }
}