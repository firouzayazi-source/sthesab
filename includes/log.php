<?php
/**
 * ⛔ `Log` — تنها لاگرِ پروژه. هر جای دیگری `error_log()` ننویسید.
 *
 * **مسئله‌ی واقعی:** ۶۷ فایل هر کدام `error_log('Xxx Error: ' . $e->getMessage())`
 * می‌زدند و متن به stderr ِ FPM می‌رفت — یک لاگِ **مشترک** با سایت‌های
 * دیگرِ همین سرور، بی‌ساختار، بدونِ شناسه‌ی درخواست، بدونِ سطح، بدونِ
 * زمانِ پاسخ. یعنی وقتی کاربر می‌گفت «خطا داد»، هیچ راهی نبود همان
 * یک درخواست را بینِ هزاران خط پیدا کرد.
 *
 * **چه چیزی می‌نویسد:** یک شیءِ JSON در هر خط (`var/log/web-YYYY-MM-DD.log`
 * برای وب و `var/log/cli-YYYY-MM-DD.log` برای خط فرمان و cron). هر خط
 * حداقل `ts`, `level`, `env`, `svc`, `req`, `event` دارد و در وب `uid`,
 * `route`, `method` هم. خطِ `request` در پایانِ هر درخواست وضعیت، زمان،
 * تعداد و زمانِ کوئری‌ها، حافظه، و آخرین مرحله (`stage`) را می‌گوید.
 *
 * **⛔ دو فایلِ جدا برای وب و خط فرمان سلیقه نیست، همان دامِ `var/sessions`
 * است:** روی سرور PHP-FPM با کاربر `hesab` می‌نویسد و cron با `root`.
 * فایلی که root بسازد را hesab نمی‌تواند باز کند و لاگِ وب **بی‌صدا**
 * می‌مرد. با دو فایل، هر نویسنده مالکِ فایلِ خودش است.
 *
 * **⛔ هرگز رمز، توکن، کوکی، شماره‌ی کارت، شبا یا ایمیل نمی‌نویسد.**
 * `redact()` روی هر `ctx` اجرا می‌شود: کلیدهای حساس با نام شناخته
 * می‌شوند (`REDACT_KEYS`) و مقدارهای حساس با شکلشان (کارت ۱۶ رقمی، شبا،
 * ایمیل). متنِ خطا هم از همان `AppErrors::scrub()` رد می‌شود.
 *
 * **⛔ هرگز استثنا پرتاب نمی‌کند و هرگز چیزی چاپ نمی‌کند.** اگر فایل
 * نوشتنی نباشد، به `error_log()` (لاگِ FPM) برمی‌گردد — لاگر نباید خودش
 * خرابیِ تازه بسازد. همان قاعده‌ی `AppErrors::record()`.
 *
 * **⛔ در هر درخواست یک سقف دارد** (`MAX_LINES_PER_REQUEST`): یک حلقه‌ی
 * خراب نباید دیسک را پر کند — همان استدلالِ `PER_REQUEST_MAX`.
 *
 * **شناسه‌ی درخواست (`req`):** اگر مشتری `X-Request-Id` معتبر بفرستد
 * (۸ تا ۶۴ کاراکترِ `[A-Za-z0-9_-]`) همان نگه داشته می‌شود، وگرنه ۱۶
 * کاراکترِ hex ساخته می‌شود. در پاسخ هم با همان نام برمی‌گردد و در
 * پاکتِ خطای JSON (`request_id`) می‌آید، تا کاربر بتواند «کد پیگیری» را
 * گزارش کند و مالکِ نصب با یک `grep` همان یک درخواست را پیدا کند.
 *
 * **آستانه‌ها اندازه‌گیری شده‌اند، حدس زده نشده‌اند:** رندرِ سنگین‌ترین
 * صفحه‌ها روی ۲۰٬۰۰۰ تراکنش ~۲۰ میلی‌ثانیه است (`CLAUDE.md`، بخشِ
 * ایندکس)، پس درخواستِ بالای ۵۰۰ و کوئریِ بالای ۵۰ میلی‌ثانیه در این
 * معماری یعنی چیزی خراب است، نه «کمی کند».
 */

final class Log
{
    public const LEVELS = ['debug' => 0, 'info' => 1, 'warn' => 2, 'error' => 3, 'fatal' => 4];

    /** چند روز فایلِ لاگ نگه داشته شود — هرسِ روزانه در اولین نوشتنِ روز. */
    public const KEEP_DAYS = 14;

    /** آستانه‌ی «کند» — بالاتر توضیح داده شده. */
    public const SLOW_QUERY_MS   = 50;
    public const SLOW_REQUEST_MS = 500;

    /** سقفِ خط در هر درخواست/پروسه — حلقه‌ی خراب دیسک را پر نمی‌کند. */
    public const MAX_LINES_PER_REQUEST = 200;

    /** کلیدهایی که مقدارشان هرگز نوشته نمی‌شود، هر جای `ctx` که باشند. */
    public const REDACT_KEYS = [
        'password', 'password_confirm', 'pass', 'passwd', 'password_hash', 'current_password',
        'token', 'api_key', 'apikey', 'secret', 'csrf', 'csrf_token', 'session', 'cookie',
        'authorization', 'validator', 'code', 'card_number', 'card', 'iban', 'account_number',
        'sms_api_key', 'sms_pass', 'smtp_pass',
    ];

    public const REDACTED = '[پنهان]';

    private static bool    $booted   = false;
    private static ?string $reqId    = null;
    private static float   $t0       = 0.0;
    private static string  $stage    = 'init';
    private static int     $dbCount  = 0;
    private static float   $dbMs     = 0.0;
    private static int     $dbSlow   = 0;
    private static int     $dbFailed = 0;
    private static int     $lines    = 0;
    private static ?int    $uid      = null;
    private static ?string $route    = null;
    private static ?string $svc      = null;
    private static bool    $fileBad  = false;
    private static ?string $prunedOn = null;

    // -----------------------------------------------------------------
    // راه‌اندازی — از `db.php`، پس هر مسیری آن را دارد
    // -----------------------------------------------------------------

    public static function boot(): void
    {
        if (self::$booted) { return; }
        self::$booted = true;

        self::$t0 = (float)($_SERVER['REQUEST_TIME_FLOAT'] ?? microtime(true));
        self::requestId();

        if (PHP_SAPI !== 'cli' && !headers_sent()) {
            header('X-Request-Id: ' . self::$reqId);
        }

        register_shutdown_function([self::class, 'shutdown']);
    }

    /**
     * شناسه‌ی این درخواست. پذیرشِ مقدارِ مشتری فقط با اعتبارسنجی — وگرنه
     * یک سرآیندِ دست‌ساز می‌توانست خطِ لاگ را با هر چیزی پر کند.
     */
    public static function requestId(): string
    {
        if (self::$reqId !== null) { return self::$reqId; }

        $given = PHP_SAPI === 'cli' ? '' : trim((string)($_SERVER['HTTP_X_REQUEST_ID'] ?? ''));
        if ($given !== '' && preg_match('/^[A-Za-z0-9_-]{8,64}$/', $given)) {
            self::$reqId = $given;
        } else {
            try {
                self::$reqId = bin2hex(random_bytes(8));
            } catch (Throwable $e) {
                self::$reqId = substr(sha1(uniqid('', true)), 0, 16);
            }
            if (PHP_SAPI === 'cli') { self::$reqId = 'cli-' . self::$reqId; }
        }
        return self::$reqId;
    }

    /** `prod` مگر `APP_ENV` چیزِ دیگری بگوید. نبودنِ ثابت = تولید (سمتِ امن). */
    public static function env(): string
    {
        $e = defined('APP_ENV') ? strtolower(trim((string)APP_ENV)) : '';
        return in_array($e, ['dev', 'staging', 'prod'], true) ? $e : 'prod';
    }

    /** کمترین سطحی که نوشته می‌شود — `LOG_LEVEL` در config، وگرنه `info`. */
    public static function minLevel(): int
    {
        $l = defined('LOG_LEVEL') ? strtolower(trim((string)LOG_LEVEL)) : '';
        if ($l === '' && self::env() === 'dev') { $l = 'debug'; }
        return self::LEVELS[$l] ?? self::LEVELS['info'];
    }

    /**
     * مرحله‌ی جاریِ درخواست: `auth` → `csrf` → `validation` → `business`
     * → `db` → `response`. وقتی خطایی ثبت می‌شود، همین می‌گوید **کجا**
     * شکست — کلِ فایده‌اش برای API همین است.
     */
    public static function stage(string $stage): void
    {
        self::$stage = $stage;
    }

    public static function currentStage(): string
    {
        return self::$stage;
    }

    /** کاربرِ این درخواست — نشستِ وب یا توکنِ API (`ApiAuth::user()` صدا می‌زند). */
    public static function setUser(?int $uid): void
    {
        self::$uid = $uid;
    }

    public static function userId(): ?int
    {
        if (self::$uid !== null) { return self::$uid; }
        if (session_status() === PHP_SESSION_ACTIVE && !empty($_SESSION['user_id'])) {
            return (int)$_SESSION['user_id'];
        }
        return null;
    }

    /** برچسبِ مسیر — برای api/v1 خودِ روتر آن را می‌گذارد (`v1:POST transactions`). */
    public static function setRoute(string $route, ?string $svc = null): void
    {
        self::$route = $route;
        if ($svc !== null) { self::$svc = $svc; }
    }

    public static function route(): string
    {
        if (self::$route !== null) { return self::$route; }
        if (PHP_SAPI === 'cli') {
            return self::relative((string)($_SERVER['SCRIPT_FILENAME'] ?? ($_SERVER['argv'][0] ?? 'cli')));
        }
        return self::relative((string)($_SERVER['SCRIPT_FILENAME'] ?? ''));
    }

    /** `web` / `api` / `api/v1` / `cli` — از روی مسیر، مگر صریح گفته شده باشد. */
    public static function service(): string
    {
        if (self::$svc !== null) { return self::$svc; }
        if (PHP_SAPI === 'cli') { return 'cli'; }
        $r = self::route();
        if (str_starts_with($r, 'api/v1/')) { return 'api/v1'; }
        if (str_starts_with($r, 'api/'))    { return 'api'; }
        return 'web';
    }

    // -----------------------------------------------------------------
    // نوشتن
    // -----------------------------------------------------------------

    public static function debug(string $event, array $ctx = []): bool { return self::write('debug', $event, $ctx); }
    public static function info(string $event, array $ctx = []): bool  { return self::write('info',  $event, $ctx); }
    public static function warn(string $event, array $ctx = []): bool  { return self::write('warn',  $event, $ctx); }

    /**
     * ثبتِ یک خطای **گرفته‌شده** — جایگزینِ همه‌ی `error_log('X: ' . $e->getMessage())`ها.
     *
     * یک فراخوانی، دو مقصد: خطِ کامل (با ردِ پشته و شناسه‌ی درخواست) در
     * فایل، و ردیفِ یکتا (با شمارنده) در `app_errors` برای پنلِ مدیر.
     * هر دو از `AppErrors::record()` می‌گذرند، پس یک خطا **یک بار** ثبت
     * می‌شود نه در هر لایه یک بار.
     */
    public static function error(string $event, $error = '', array $ctx = []): bool
    {
        $msg = $error instanceof Throwable ? get_class($error) . ': ' . $error->getMessage() : (string)$error;
        $file = $error instanceof Throwable ? $error->getFile() : '';
        $line = $error instanceof Throwable ? $error->getLine() : 0;
        $ctx['event'] = $event;
        if ($error instanceof Throwable) { $ctx['trace'] = self::trace($error); }
        return AppErrors::record('error', $msg, $file, $line, $ctx);
    }

    /**
     * خطِ خام. `AppErrors::record()` هم از همین می‌نویسد؛ بقیه از
     * `info()`/`warn()`/`error()` بروند.
     */
    public static function write(string $level, string $event, array $ctx = []): bool
    {
        $lv = self::LEVELS[$level] ?? self::LEVELS['info'];
        if ($lv < self::minLevel()) { return false; }
        if (self::$lines >= self::MAX_LINES_PER_REQUEST) { return false; }
        self::$lines++;

        try {
            $rec = [
                'ts'    => date('c'),
                'level' => $level,
                'env'   => self::env(),
                'svc'   => self::service(),
                'req'   => self::requestId(),
                'event' => $event,
            ];
            $uid = self::userId();
            if ($uid !== null) { $rec['uid'] = $uid; }
            if (PHP_SAPI !== 'cli') {
                $rec['route']  = self::route();
                $rec['method'] = (string)($_SERVER['REQUEST_METHOD'] ?? '');
            }
            $ctx = self::redact($ctx);
            foreach ($ctx as $k => $v) {
                if (!array_key_exists($k, $rec)) { $rec[$k] = $v; }
            }

            $json = json_encode($rec, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR);
            if (!is_string($json)) { return false; }
            if (strlen($json) > 16000) { $json = substr($json, 0, 16000); }

            return self::append($json . "\n");
        } catch (Throwable $e) {
            return false;        // ⛔ لاگر هرگز خرابیِ تازه نمی‌سازد
        }
    }

    /**
     * از `DbStatement` و `DbConnection` — هر کوئری یک بار. کوئریِ کند
     * همان لحظه یک خطِ `warn` می‌گیرد؛ بقیه فقط شمرده می‌شوند و در
     * خطِ `request` جمع می‌آیند. متنِ SQL پارامتر ندارد (prepare)، پس
     * مقدارِ کاربر در آن نیست.
     */
    public static function dbQuery(string $sql, float $ms, bool $ok): void
    {
        self::$dbCount++;
        self::$dbMs += $ms;
        if (!$ok) { self::$dbFailed++; }
        if ($ms >= self::SLOW_QUERY_MS) {
            self::$dbSlow++;
            self::warn('db.slow_query', [
                'ms'  => round($ms, 1),
                'sql' => mb_substr(preg_replace('/\s+/u', ' ', trim($sql)) ?? '', 0, 200),
            ]);
        }
    }

    public static function dbStats(): array
    {
        return ['n' => self::$dbCount, 'ms' => round(self::$dbMs, 1),
                'slow' => self::$dbSlow, 'failed' => self::$dbFailed];
    }

    /** میلی‌ثانیه از شروعِ درخواست. */
    public static function elapsedMs(): float
    {
        return round((microtime(true) - self::$t0) * 1000, 1);
    }

    /**
     * خطِ پایانِ درخواست — یک خط برای هر درخواستِ وب. سطحش از کدِ وضعیت
     * می‌آید (۵xx → error، ۴xx → warn) و کندی جدا علامت می‌خورد.
     *
     * ⚠ در خط فرمان خطِ `process.end` نوشته می‌شود و فقط وقتی که چیزی
     *   غیرعادی باشد (خطای کشنده یا کوئریِ شکست‌خورده)، وگرنه هر تست و
     *   هر `php -r` یک خط اضافه می‌کرد — همان «لاگِ بی‌هدف».
     */
    public static function shutdown(): void
    {
        try {
            $last  = error_get_last();
            $fatal = $last && ($last['type'] & (E_ERROR | E_PARSE | E_CORE_ERROR | E_COMPILE_ERROR));

            if (PHP_SAPI === 'cli') {
                if ($fatal || self::$dbFailed > 0) {
                    self::write($fatal ? 'error' : 'warn', 'process.end', [
                        'ms' => self::elapsedMs(), 'db' => self::dbStats(), 'fatal' => (bool)$fatal,
                        'mem_kb' => (int)(memory_get_peak_usage(true) / 1024),
                    ]);
                }
                return;
            }

            $status = http_response_code();
            $status = is_int($status) ? $status : 200;
            if ($fatal && $status < 500) { $status = 500; }
            $ms = self::elapsedMs();

            $level = $status >= 500 ? 'error' : ($status >= 400 ? 'warn' : 'info');
            if ($level === 'info' && $ms >= self::SLOW_REQUEST_MS) { $level = 'warn'; }

            $ctx = [
                'status' => $status,
                'ms'     => $ms,
                'db'     => self::dbStats(),
                'mem_kb' => (int)(memory_get_peak_usage(true) / 1024),
                'stage'  => self::$stage,
                'ip'     => (string)($_SERVER['REMOTE_ADDR'] ?? ''),
            ];
            if ($ms >= self::SLOW_REQUEST_MS) { $ctx['slow'] = true; }
            self::write($level, 'request', $ctx);
        } catch (Throwable $e) {
            // سکوت — پایانِ درخواست جای خرابیِ تازه نیست
        }
    }

    // -----------------------------------------------------------------
    // پاک‌سازی
    // -----------------------------------------------------------------

    /**
     * ⛔ کلیدهای حساس با نام و مقدارهای حساس با شکل. بازگشتی، تا
     *    `ctx['user']['password']` هم گرفته شود.
     */
    public static function redact(array $ctx): array
    {
        $out = [];
        foreach ($ctx as $k => $v) {
            $key = is_string($k) ? strtolower($k) : $k;
            if (is_string($key) && in_array($key, self::REDACT_KEYS, true)) {
                $out[$k] = self::REDACTED;
                continue;
            }
            if (is_array($v)) {
                $out[$k] = self::redact($v);
            } elseif (is_string($v)) {
                $out[$k] = self::redactString($v);
            } elseif ($v instanceof Throwable) {
                $out[$k] = get_class($v) . ': ' . self::redactString($v->getMessage());
            } else {
                $out[$k] = $v;
            }
        }
        return $out;
    }

    /** شماره‌ی کارت (۱۳ تا ۱۹ رقم)، شبا، و ایمیل — بی‌توجه به نامِ کلید. */
    public static function redactString(string $s): string
    {
        $s = preg_replace('/\bIR\d{24}\b/i', '[شبا]', $s) ?? $s;
        $s = preg_replace('/(?<!\d)(?:\d[ -]?){13,19}(?!\d)/', '[کارت]', $s) ?? $s;
        $s = preg_replace('/[\w.+-]+@[\w-]+\.[\w.-]+/u', '[ایمیل]', $s) ?? $s;
        return mb_substr($s, 0, 2000);
    }

    /**
     * ردِ پشته بدونِ آرگومان‌ها — `getTraceAsString()` مقدارِ آرگومان‌ها را
     * هم می‌نویسد (مگر `zend.exception_ignore_args` روشن باشد) و آن
     * می‌تواند رمز یا شماره کارت باشد. اینجا فقط فایل:خط و نامِ تابع.
     */
    public static function trace(Throwable $e, int $max = 15): array
    {
        $out = [];
        foreach ($e->getTrace() as $i => $f) {
            if ($i >= $max) { break; }
            $fn = ($f['class'] ?? '') . ($f['type'] ?? '') . ($f['function'] ?? '');
            $out[] = self::relative((string)($f['file'] ?? '')) . ':' . (int)($f['line'] ?? 0) . ' ' . $fn;
        }
        return $out;
    }

    public static function relative(string $file): string
    {
        $root = dirname(__DIR__) . '/';
        return str_starts_with($file, $root) ? substr($file, strlen($root)) : $file;
    }

    // -----------------------------------------------------------------
    // فایل
    // -----------------------------------------------------------------

    public static function dir(): string
    {
        return dirname(__DIR__) . '/var/log';
    }

    /** `web` یا `cli` — دلیلش بالای فایل (مالکیتِ hesab در برابر root). */
    public static function channel(): string
    {
        return PHP_SAPI === 'cli' ? 'cli' : 'web';
    }

    public static function file(?string $day = null): string
    {
        return self::dir() . '/' . self::channel() . '-' . ($day ?? date('Y-m-d')) . '.log';
    }

    private static function append(string $line): bool
    {
        if (self::$fileBad) {
            error_log(rtrim($line));
            return false;
        }

        $dir = self::dir();
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            self::$fileBad = true;
            error_log(rtrim($line));
            return false;
        }

        $path = self::file();
        $new  = !file_exists($path);
        $ok   = @file_put_contents($path, $line, FILE_APPEND | LOCK_EX);
        if ($ok === false) {
            self::$fileBad = true;
            error_log(rtrim($line));
            return false;
        }
        if ($new) {
            @chmod($path, 0664);
            self::prune();
        }
        return true;
    }

    /**
     * فایل‌های کهنه‌تر از `KEEP_DAYS` پاک می‌شوند — فقط فایل‌های همین
     * کانال (root فایلِ hesab را دست نمی‌زند و برعکس)، و فقط با همان
     * الگوی نام. یک بار در هر روز کافی است.
     */
    public static function prune(): int
    {
        $today = date('Y-m-d');
        if (self::$prunedOn === $today) { return 0; }
        self::$prunedOn = $today;

        $n = 0;
        $cut = strtotime('-' . self::KEEP_DAYS . ' days');
        foreach (glob(self::dir() . '/' . self::channel() . '-????-??-??.log') ?: [] as $p) {
            if (!preg_match('/-(\d{4}-\d{2}-\d{2})\.log$/', $p, $m)) { continue; }
            $t = strtotime($m[1]);
            if ($t !== false && $t < $cut && @unlink($p)) { $n++; }
        }
        return $n;
    }

    /** برای آزمون و گزارش — خطوطِ یک فایل به‌صورت آرایه‌ی decode‌شده. */
    public static function readLines(string $path, int $max = 50000): array
    {
        if (!is_readable($path)) { return []; }
        $out = [];
        $fh = fopen($path, 'r');
        if (!$fh) { return []; }
        while (($ln = fgets($fh)) !== false) {
            $d = json_decode($ln, true);
            if (is_array($d)) { $out[] = $d; }
            if (count($out) >= $max) { break; }
        }
        fclose($fh);
        return $out;
    }
}

// `Log::error()` به `AppErrors::record()` می‌رسد و آن دوباره به `Log::write()` —
// دو نیمه‌ی یک چیزند و با هم لود می‌شوند.
require_once __DIR__ . '/app_errors.php';
