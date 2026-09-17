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

/**
 * ⛔ تنها جای تعیینِ منطقه‌ی زمانی — و عمداً همین‌جا، نه در `initSession()`.
 *
 * پیش از این فقط `Auth::initSession()` آن را می‌گذاشت، یعنی هر پروسه‌ای
 * که نشست نمی‌سازد با منطقه‌ی زمانیِ **سیستم** کار می‌کرد: همه‌ی
 * تست‌های خط فرمان، و هر اسکریپتِ cron که auth را لود نمی‌کند. پس کلِ
 * دسته‌ی خطاهای «PHP یک تاریخ می‌سازد و MySQL با آن مقایسه می‌کند» —
 * همان که یک بار لینکِ بازیابیِ رمز را بلافاصله «منقضی» کرد — در تست
 * **اصلاً اجرا نمی‌شد**، چون آنجا PHP و MySQL اتفاقاً هر دو UTC بودند.
 *
 * `db.php` را همه لود می‌کنند (صفحه، اندپوینت، cron، تست)، پس گذاشتنش
 * اینجا یعنی هیچ مسیری از قلم نمی‌افتد. `initSession()` هم همین را صدا
 * می‌زند تا دو نسخه از این تصمیم وجود نداشته باشد.
 */
function applyAppTimezone(): void
{
    if (defined('APP_TIMEZONE') && APP_TIMEZONE !== '') {
        date_default_timezone_set(APP_TIMEZONE);
    }
}

applyAppTimezone();

/**
 * ⛔ گیرنده‌ی خطا هم همین‌جا نصب می‌شود، و به همان دلیلِ منطقه‌ی زمانی:
 *    `db.php` تنها فایلی است که **هر** مسیر (صفحه، اندپوینت، cron، تست)
 *    لود می‌کند. با نصب از جای دیگر، همیشه مسیری می‌ماند که از قلم
 *    بیفتد — و آن دقیقاً همان مسیری است که خطایش را کسی نمی‌بیند.
 *
 * ⚠ ثبت در دیتابیس با وجودِ جدول **در زمانِ خطا** سنجیده می‌شود، نه
 *   الان: اینجا هنوز `functions.php` لود نشده و `tableExists()` وجود
 *   ندارد. پس نصبِ گیرنده روی نصبی که migration نخورده هم بی‌خطر است.
 */
require_once __DIR__ . '/log.php';
require_once __DIR__ . '/app_errors.php';
require_once __DIR__ . '/audit.php';
Log::boot();
AppErrors::install();

/**
 * ⛔ زمانِ هر کوئری همین‌جا اندازه گرفته می‌شود، نه با لاگِ کوئریِ
 *    MariaDB روی سرور. `PDO::ATTR_STATEMENT_CLASS` هر `prepare()` را با
 *    این کلاس برمی‌گرداند؛ `DbConnection` هم `query()`/`exec()` را
 *    می‌پوشاند. هزینه‌اش یک `hrtime()` در هر کوئری است (زیرِ یک
 *    میکروثانیه) و نتیجه‌اش این است که خطِ `request` هر درخواست می‌گوید
 *    چند کوئری و چند میلی‌ثانیه، و کوئریِ کند همان لحظه یک خطِ `warn`
 *    می‌گیرد — بدونِ SSH و بدونِ روشن کردنِ چیزی روی سرور.
 *
 * ⚠ خودِ خطای کوئری اینجا لاگ **نمی‌شود**، فقط شمرده می‌شود: استثنا
 *   به فراخواننده می‌رسد و او با `Log::error()` ثبتش می‌کند. وگرنه یک
 *   خطا دو بار نوشته می‌شد — همان «تکرارِ یک خطا در چند لایه».
 */
final class DbStatement extends PDOStatement
{
    protected function __construct() {}

    public function execute(?array $params = null): bool
    {
        // مرحله‌ی «db» فقط در طولِ همین اجرا؛ بعدش به مرحله‌ی قبلی
        // برمی‌گردد تا خطای منطقی بعد از کوئری «db» خوانده نشود. در
        // شکست همان «db» می‌ماند — استثنا از همین‌جا بیرون رفته.
        $prev = Log::currentStage();
        Log::stage('db');
        $t = hrtime(true);
        try {
            $ok = parent::execute($params);
        } catch (Throwable $e) {
            Log::dbQuery($this->queryString, (hrtime(true) - $t) / 1e6, false);
            throw $e;
        }
        Log::dbQuery($this->queryString, (hrtime(true) - $t) / 1e6, (bool)$ok);
        Log::stage($prev);
        return $ok;
    }
}

final class DbConnection extends PDO
{
    public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): PDOStatement|false
    {
        $t = hrtime(true);
        try {
            $r = parent::query($query, $fetchMode, ...$fetchModeArgs);
        } catch (Throwable $e) {
            Log::dbQuery($query, (hrtime(true) - $t) / 1e6, false);
            throw $e;
        }
        Log::dbQuery($query, (hrtime(true) - $t) / 1e6, $r !== false);
        return $r;
    }

    public function exec(string $statement): int|false
    {
        $t = hrtime(true);
        try {
            $r = parent::exec($statement);
        } catch (Throwable $e) {
            Log::dbQuery($statement, (hrtime(true) - $t) / 1e6, false);
            throw $e;
        }
        Log::dbQuery($statement, (hrtime(true) - $t) / 1e6, $r !== false);
        return $r;
    }
}

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
                PDO::ATTR_STATEMENT_CLASS    => [DbStatement::class, []],
            ];

            try {
                self::$instance = new DbConnection($dsn, DB_USER, DB_PASSWORD, $options);
                self::syncTimezone(self::$instance);
            } catch (PDOException $e) {
                // ⚠ فقط فایل، نه `Log::error()`: آن یکی به `app_errors` هم
                //   می‌نویسد و برای آن دوباره به همین اتصالِ شکست‌خورده
                //   نیاز داشت — حلقه. پیامِ PDO رمزِ دیتابیس را ندارد ولی
                //   نامِ کاربر و میزبان را دارد؛ `scrub` روی متن اجرا می‌شود.
                Log::write('fatal', 'db.connect_failed', ['msg' => AppErrors::scrub($e->getMessage())]);

                /**
                 * ⛔ روی خط فرمان **پرتاب** می‌شود، نه `die()` — و این یک
                 *    خرابیِ واقعی و بسیار بی‌صدا را می‌بندد.
                 *
                 * `die()` با کدِ خروجِ **صفر** تمام می‌شود. حدود ۲۵ مجموعه‌ی
                 * تست دور این فراخوانی `try/catch` دارند تا در نبودِ
                 * دیتابیس آبرومندانه رد شوند — ولی چون هرگز استثنایی
                 * پرتاب نمی‌شد، **آن بلوک‌ها کدِ مرده بودند**: پروسه
                 * همان‌جا با کد ۰ می‌مرد، `run.sh` آن را «موفق» می‌خواند،
                 * و ته اجرا می‌نوشت «✅ هر ۴۲ مجموعه تست موفق بود» در حالی
                 * که هیچ‌کدامشان به دیتابیس نرسیده بودند. همین جلسه اتفاق
                 * افتاد و با خواباندنِ MariaDB بازتولید شد (`EXIT=0`).
                 *
                 * روی وب همان پیامِ آبرومند می‌ماند: آنجا کاربر یک صفحه
                 * می‌خواهد، نه یک stack trace.
                 */
                if (PHP_SAPI === 'cli') {
                    throw $e;
                }

                http_response_code(500);
                die('خطا در اتصال به دیتابیس. لطفاً تنظیمات config.php را بررسی کنید.');
            }
        }

        return self::$instance;
    }

    /**
     * ⛔ ساعتِ MySQL را با ساعتِ PHP هم‌تراز می‌کند — یک خرابیِ **واقعی و
     * زنده** را می‌بندد که فقط با اندازه‌گیری پیدا شد.
     *
     * سنجیده شد: `date('Y-m-d H:i:s')` روی ۱۱:۳۰ بود و `SELECT NOW()` روی
     * ۰۸:۰۰ — یعنی **۳ ساعت و نیم اختلاف**، چون PHP روی `APP_TIMEZONE` است
     * و MySQL روی `SYSTEM` (که اینجا UTC است).
     *
     * قاعده‌ی پروژه («زمان را در دیتابیس بساز و در دیتابیس مقایسه کن») این
     * را تا امروز بی‌خطر نگه داشته بود، ولی فقط تا مرزِ **روز**: هر شب بین
     * ۰۰:۰۰ و ۰۳:۳۰ به وقتِ تهران، `today()`ِ PHP و `CURDATE()`ِ دیتابیس دو
     * روزِ متفاوت می‌گفتند. یعنی `remind_date <= today`، کلیدِ یکتای
     * `snap_date`، `last_sent_on` و نگهبانِ «یک بار در روز»ِ تراکنش‌های
     * دوره‌ای در آن پنجره **بی‌صدا** غلط می‌شدند.
     *
     * ⚠ افست عددی فرستاده می‌شود نه نامِ منطقه (`Asia/Tehran`): نام فقط وقتی
     * کار می‌کند که جدول‌های `mysql.time_zone` بارگذاری شده باشند، که روی
     * بیشترِ نصب‌ها نیستند و آن‌وقت این دستور **بی‌صدا** خطا می‌داد.
     *
     * ⚠ و عمداً هر بار از `date()` خوانده می‌شود نه یک ثابت: افستِ ایران
     * ۳:۳۰ است ولی هر کشوری که ساعتِ تابستانی دارد در سال دو بار عوض
     * می‌شود، و مقدارِ ثابت شش ماه بعد یک ساعت غلط می‌بود.
     */
    private static function syncTimezone(PDO $pdo): void
    {
        if (!defined('APP_TIMEZONE') || APP_TIMEZONE === '') { return; }

        $offset = date('P');               // مثل ‎+03:30
        $stmt = $pdo->prepare('SET time_zone = :tz');
        $stmt->execute([':tz' => $offset]);
    }
}