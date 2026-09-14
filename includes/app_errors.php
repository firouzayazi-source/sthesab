<?php
/**
 * ⛔ سطحِ دیدنِ خطاهای PHP — چون امروز هیچ‌کس آن‌ها را نمی‌بیند.
 *
 * **مسئله‌ی واقعی، و شمرده شد:** ۶۱ فایلِ این مخزن `error_log()` صدا
 * می‌زنند، ولی `admin/` فقط شش صفحه دارد و **هیچ‌کدام خطا نشان
 * نمی‌دهند**. یعنی هر خطای کشنده، هر کوئریِ شکست‌خورده و هر ایمیلِ
 * نرفته بی‌صدا در لاگِ FPM می‌ماند — لاگی که مالکِ نصب باید SSH بزند تا
 * ببیندش، و در عمل هیچ‌وقت نمی‌بیند. سابقه‌اش هم هست:
 * `assets/manifest.php` مدت‌ها ۵۰۰ می‌داد و «افزودن به صفحه‌ی اصلی»
 * بی‌صدا کار نمی‌کرد؛ فقط تستی که واقعاً صدایش زد پیدایش کرد.
 *
 * **⛔ این یک ردیابیِ کاربر نیست و نباید بشود.** جدولش نه `user_id`
 * دارد، نه آدرسِ صفحه، نه بدنه‌ی درخواست. درباره‌ی **کد** است. اگر روزی
 * وسوسه شدید «کدام کاربر این خطا را دید» را اضافه کنید، بدانید همان
 * لحظه ادعاهای `privacy.php` و قاعده‌ی «هیچ ردیابیِ تازه‌ای» در
 * `admin/insights.php` غلط می‌شوند.
 *
 * **⛔ و متنِ خطا نرمال‌سازی می‌شود، نه خام.** پیامِ خطای PDO می‌تواند
 * مقدارِ واقعیِ یک ردیف را در خودش داشته باشد. اعدادِ بلند و ایمیل با
 * نشانه جایگزین می‌شوند، ولی بخشِ **گویا** («Unknown column 'wallet_id'»)
 * دست‌نخورده می‌ماند — چون بدونِ آن، این جدول فقط می‌گوید «خطایی هست» و
 * آن همان‌قدر بی‌فایده است که هیچ.
 */

final class AppErrors
{
    /** سقفِ ثبت در هر درخواست — یک حلقه‌ی خراب نباید دیتابیس را بکوبد. */
    private const PER_REQUEST_MAX = 5;

    private static bool $installed = false;
    private static int  $recorded  = 0;
    private static ?bool $tableOk  = null;

    /**
     * نصبِ گیرنده‌ها.
     *
     * ⚠ `set_error_handler` عمداً `false` برمی‌گرداند تا رفتارِ عادیِ PHP
     *   (نوشتن در لاگ) دست‌نخورده بماند. این لایه **جایگزینِ** لاگ نیست،
     *   یک نمای خوانا روی آن است.
     */
    public static function install(): void
    {
        if (self::$installed) { return; }
        self::$installed = true;

        set_error_handler(static function (int $no, string $msg, string $file = '', int $line = 0) {
            if ($no & (E_ERROR | E_WARNING | E_USER_ERROR | E_USER_WARNING | E_RECOVERABLE_ERROR)) {
                self::record(self::levelName($no), $msg, $file, $line);
            }
            return false;        // ⛔ کنترل به خودِ PHP برمی‌گردد
        });

        set_exception_handler(static function (Throwable $e) {
            self::record('exception', get_class($e) . ': ' . $e->getMessage(),
                $e->getFile(), $e->getLine());

            // رفتارِ پیش‌فرضِ PHP بازسازی می‌شود: لاگ + کدِ خروجِ ناصفر.
            // بدونِ کدِ ناصفر، یک استثنای گرفته‌نشده روی خط فرمان «موفق»
            // دیده می‌شد — همان خرابی‌ای که در تست‌رانر بسته شد.
            error_log('Uncaught ' . get_class($e) . ': ' . $e->getMessage()
                . ' in ' . $e->getFile() . ':' . $e->getLine());

            // ⚠ روی ماشینِ توسعه (`display_errors` روشن) متن و ردِ پشته
            //   باید همچنان دیده شود. بدونِ این، نصبِ این لایه توسعه‌دهنده
            //   را از تنها چیزی که برای تشخیص دارد محروم می‌کرد — یعنی
            //   ابزارِ دیدنِ خطا، خودش خطا را پنهان می‌کرد.
            if (ini_get('display_errors')) {
                fwrite(PHP_SAPI === 'cli' ? STDERR : fopen('php://output', 'w'),
                    "\nPHP Fatal error:  Uncaught " . get_class($e) . ': ' . $e->getMessage()
                    . ' in ' . $e->getFile() . ':' . $e->getLine()
                    . "\nStack trace:\n" . $e->getTraceAsString() . "\n");
            }

            if (PHP_SAPI !== 'cli' && !headers_sent()) { http_response_code(500); }
            exit(255);
        });

        register_shutdown_function(static function () {
            $e = error_get_last();
            if ($e && ($e['type'] & (E_ERROR | E_PARSE | E_CORE_ERROR | E_COMPILE_ERROR))) {
                self::record('fatal', $e['message'], $e['file'] ?? '', (int)($e['line'] ?? 0));
            }
        });
    }

    /**
     * ثبتِ یک خطا. هرگز استثنا پرتاب نمی‌کند و هرگز چیزی چاپ نمی‌کند —
     * کارِ جانبی است و نباید خودش به خرابیِ تازه تبدیل شود.
     */
    public static function record(string $level, string $message, string $file = '', int $line = 0): bool
    {
        if (self::$recorded >= self::PER_REQUEST_MAX) { return false; }

        try {
            if (!self::available()) { return false; }

            $msg  = self::scrub($message);
            $rel  = self::relative($file);
            $fp   = sha1($level . '|' . $rel . '|' . $line . '|' . $msg);

            $pdo = Database::getConnection();
            $pdo->prepare(
                'INSERT INTO app_errors (fingerprint, level, message, file, line)
                 VALUES (:f, :lv, :m, :fl, :ln)
                 ON DUPLICATE KEY UPDATE hits = hits + 1, last_seen = NOW()'
            )->execute(['f' => $fp, 'lv' => mb_substr($level, 0, 20), 'm' => $msg,
                        'fl' => mb_substr($rel, 0, 255), 'ln' => max(0, $line)]);

            self::$recorded++;
            return true;
        } catch (Throwable $e) {
            return false;        // ⛔ سکوت — وگرنه خطای ثبتِ خطا حلقه می‌شود
        }
    }

    /** آخرین خطاها برای پنل مدیر. */
    public static function recent(int $limit = 8): array
    {
        try {
            if (!self::available()) { return []; }
            $st = Database::getConnection()->prepare(
                'SELECT level, message, file, line, hits, first_seen, last_seen
                 FROM app_errors ORDER BY last_seen DESC LIMIT ' . max(1, min(50, $limit))
            );
            $st->execute();
            return $st->fetchAll();
        } catch (Throwable $e) {
            return [];
        }
    }

    /** شمارشِ خطاهای متمایزِ N روزِ گذشته. */
    public static function countSince(int $days = 7): int
    {
        try {
            if (!self::available()) { return 0; }
            $st = Database::getConnection()->prepare(
                'SELECT COUNT(*) FROM app_errors WHERE last_seen >= DATE_SUB(NOW(), INTERVAL :d DAY)'
            );
            $st->execute(['d' => max(1, $days)]);
            return (int)$st->fetchColumn();
        } catch (Throwable $e) {
            return 0;
        }
    }

    /** پاک کردنِ فهرست — بعد از رسیدگی، مالکِ نصب صفرش می‌کند. */
    public static function clear(): bool
    {
        try {
            if (!self::available()) { return false; }
            Database::getConnection()->exec('DELETE FROM app_errors');
            return true;
        } catch (Throwable $e) {
            return false;
        }
    }

    // -----------------------------------------------------------------

    /**
     * جدول آمده است؟
     *
     * ⚠ نتیجه فقط در همان درخواست کش می‌شود، نه بین درخواست‌ها — همان
     *   درسِ `access_revoked_at`: یک ایستای «جدول نیست» در FPM تا بازیافتِ
     *   کارگر می‌ماند و بعد از migration، ثبت را بی‌صدا خاموش نگه می‌دارد.
     */
    private static function available(): bool
    {
        if (self::$tableOk === null) {
            self::$tableOk = function_exists('tableExists') && tableExists('app_errors');
        }
        return self::$tableOk;
    }

    /**
     * ⛔ مقدارهای احتمالیِ کاربر از متن برداشته می‌شوند.
     *
     * پیامِ PDO می‌تواند شماره کارت یا ایمیل را در خودش داشته باشد. ولی
     * پاک کردنِ **همه‌چیز** هم درست نیست: بدونِ «Unknown column 'wallet_id'»
     * این جدول چیزی نمی‌گوید. پس فقط چیزهایی مخفی می‌شوند که ذاتاً
     * مقدارند: رشته‌ی بلندِ رقمی، و ایمیل.
     */
    public static function scrub(string $message): string
    {
        $m = preg_replace('/[\w.+-]+@[\w-]+\.[\w.-]+/u', '[ایمیل]', $message) ?? $message;
        $m = preg_replace('/\d{4,}/u', '[عدد]', $m) ?? $m;
        $m = preg_replace('/\s+/u', ' ', $m) ?? $m;
        return mb_substr(trim($m), 0, 300);
    }

    /** مسیرِ نسبی به ریشه‌ی پروژه — مسیرِ مطلق فقط عرض می‌گیرد. */
    private static function relative(string $file): string
    {
        $root = dirname(__DIR__) . '/';
        return str_starts_with($file, $root) ? substr($file, strlen($root)) : $file;
    }

    private static function levelName(int $no): string
    {
        switch ($no) {
            case E_ERROR:
            case E_USER_ERROR:          return 'error';
            case E_RECOVERABLE_ERROR:   return 'recoverable';
            case E_WARNING:
            case E_USER_WARNING:        return 'warning';
            default:                    return 'notice';
        }
    }
}
