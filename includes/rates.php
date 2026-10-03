<?php
/**
 * نرخِ روزِ ارز، طلا و سکه — یک لایه برای کلِ برنامه (شخصی و فروشگاهی).
 *
 * **خواسته‌ی مالکِ نصب:** «امکانِ استفاده از API نرخِ دلار و طلا در کلِ
 * پروژه… برای کسانی که دارایی براساسِ دلار یا طلا یا سکه دارن ارزشِ
 * دارایی به‌روزشون حساب بشه و در فروشگاه هم برای تعیینِ قیمتِ کالاها…
 * این بخش رو مختص به این APIها نساز و کلی و دقیق و راحت بساز.»
 *
 * ⛔ **سه لایه، و هیچ‌کدام به منبعِ خاصی بند نیست:**
 *    ۱. `CODES` — فهرستِ بسته‌ی نرخ‌ها (دلار، گرمِ ۱۸، سکه‌ی امامی…). هر جای
 *       برنامه فقط با همین کدها حرف می‌زند، نه با نامِ منبع.
 *    ۲. `PROVIDERS` — هر منبع فقط «آدرس + نگاشتِ نمادهایش به کدهای ما» است؛
 *       سه منبعِ مالکِ نصب (BrsApi، نوسان، بیت‌پین) به‌علاوه‌ی «منبعِ
 *       دلخواه» که مدیر آدرس و مسیرِ هر عدد را خودش می‌دهد. منبعِ تازه یعنی
 *       یک ردیف اینجا — نه migration، نه صفحه‌ی تازه.
 *    ۳. مصرف‌کننده‌ها: `applyToAssets()` (نوعِ داراییِ وصل‌شده) همین‌جا، و
 *       قیمتِ فروشِ کالای ارزی در لایه‌ی فروشگاه (`includes/biz_rates.php`)
 *       که با `Rates::listen()` خودش را ثبت می‌کند. ⛔ این فایل هیچ جدولِ
 *       فروشگاهی را نمی‌شناسد (قاعده ۷۰) — طرفِ شخصی آن را بار می‌کند.
 *
 * ⛔ **نرخ هرگز هنگامِ باز شدنِ صفحه گرفته نمی‌شود** — فقط cron
 *    (`deploy/rates.php`، هر ۶ ساعت) و دکمه‌ی «همین حالا»ِ مدیر. همان
 *    استدلالِ `update_asset_price.php`: سرویسی که نصفِ روزها در دسترس نیست
 *    (سرورِ خارج از ایران، تحریم) نباید صفحه را کند یا خراب کند. منبعی که
 *    جواب نداد، **آخرین نرخِ سالم سرِ جایش می‌ماند** و فقط کهنه علامت می‌خورد.
 *
 * ⛔ **دقت:** عددی که با نرخِ قبلی بیش از `MAX_JUMP` فرق کند پذیرفته
 *    نمی‌شود (ریال/تومانِ قاطی، پاسخِ خراب، نمادِ عوض‌شده) — اشتباهِ ده‌برابری
 *    در ارزشِ دارایی و قیمتِ فروش بی‌صدا می‌نشست. نرخِ دستیِ مدیر (`manual`)
 *    را دریافتِ خودکار هرگز بازنویسی نمی‌کند.
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/crypto.php';
require_once __DIR__ . '/log.php';

final class Rates
{
    /** کد ⇒ [برچسب، واحد، گروه] — تنها مرجعِ نرخ‌ها؛ قیمت همیشه تومان برای یک واحد. */
    public const CODES = [
        'usd'          => ['دلار آمریکا',      'دلار',  'ارز'],
        'eur'          => ['یورو',             'یورو',  'ارز'],
        'aed'          => ['درهم امارات',      'درهم',  'ارز'],
        'usdt'         => ['تتر',              'تتر',   'ارز'],
        'gold18'       => ['طلای ۱۸ عیار',     'گرم',   'طلا'],
        'gold24'       => ['طلای ۲۴ عیار',     'گرم',   'طلا'],
        'mesghal'      => ['مثقال طلای آبشده', 'مثقال', 'طلا'],
        'coin_emami'   => ['سکه امامی',        'عدد',   'سکه'],
        'coin_bahar'   => ['سکه بهار آزادی',   'عدد',   'سکه'],
        'coin_half'    => ['نیم سکه',          'عدد',   'سکه'],
        'coin_quarter' => ['ربع سکه',          'عدد',   'سکه'],
        'coin_gram'    => ['سکه گرمی',         'عدد',   'سکه'],
    ];

    /**
     * منبع‌ها. `key`: 'required' | 'optional' | 'none' — فقط راهنمای صفحه‌ی
     * مدیر است؛ هیچ منبعی به خاطرِ نبودِ کلید **قفل** نمی‌شود («هم با کلید هم
     * بدون کلید امکان‌پذیر باشد»). `urls`: به ترتیب امتحان می‌شوند تا یکی
     * جواب دهد (`{key}` با کلید پر می‌شود). `monthly`: سقفِ درخواستِ ماهانه
     * (۰ = بی‌سقف) — نوسانِ رایگان ۱۲۰.
     * `map`: نمادِ منبع ⇒ کدِ ما. `@ounce` یعنی «قیمتِ یک اونس طلا به تومان»؛
     * گرمِ ۲۴ و ۱۸ از آن ساخته می‌شوند (`derive()`).
     */
    public const PROVIDERS = [
        'brsapi' => [
            'label' => 'BrsApi', 'site' => 'https://brsapi.ir/free-api-gold-currency-webservice/',
            'key' => 'required', 'monthly' => 0, 'parser' => 'symbols',
            'urls' => ['https://BrsApi.ir/Api/Market/Gold_Currency.php?key={key}',
                       'https://Api.BrsApi.ir/Market/Gold_Currency.php?key={key}'],
            'map' => ['USD' => 'usd', 'EUR' => 'eur', 'AED' => 'aed', 'USDT' => 'usdt',
                      'IR_GOLD_18K' => 'gold18', 'IR_GOLD_24K' => 'gold24', 'IR_GOLD_MELTED' => 'mesghal',
                      'IR_COIN_EMAMI' => 'coin_emami', 'IR_COIN_BAHAR' => 'coin_bahar', 'IR_COIN_HALF' => 'coin_half',
                      'IR_COIN_QUARTER' => 'coin_quarter', 'IR_COIN_1G' => 'coin_gram'],
        ],
        'navasan' => [
            'label' => 'نوسان', 'site' => 'https://www.navasan.tech/api/',
            'key' => 'required', 'monthly' => 120, 'parser' => 'keyed',
            // ⚠ راهنمای خودِ نوسان نشانیِ http می‌دهد؛ https اول، و اگر جواب نداد http.
            'urls' => ['https://api.navasan.tech/latest/?api_key={key}', 'http://api.navasan.tech/latest/?api_key={key}'],
            // ⚠ نامِ دوم هر کد (بعدِ اولی) فقط وقتی خوانده می‌شود که اولی در پاسخ
            //   نبود (`??=`): نام‌ها در طرح‌های مختلفِ نوسان یکی نیستند و یک نامِ
            //   ناآشنا یعنی آن نرخ بی‌صدا خالی بماند.
            'map' => ['usd_sell' => 'usd', 'usd' => 'usd', 'eur' => 'eur', 'eur_sell' => 'eur',
                      'aed_sell' => 'aed', 'aed' => 'aed', 'usdt' => 'usdt', 'usdt_sell' => 'usdt',
                      '18ayar' => 'gold18', 'abshodeh' => 'mesghal', 'mesghal' => 'mesghal',
                      'sekkeh' => 'coin_emami', 'emami' => 'coin_emami', 'bahar' => 'coin_bahar',
                      'nim' => 'coin_half', 'rob' => 'coin_quarter', 'gerami' => 'coin_gram'],
        ],
        'bitpin' => [
            'label' => 'بیت‌پین', 'site' => 'https://bitpin.ir/academy/live/',
            'key' => 'none', 'monthly' => 0, 'parser' => 'symbols',
            'urls' => ['https://api.bitpin.ir/api/v1/mkt/tickers/', 'https://api.bitpin.ir/v1/mkt/markets/'],
            'map' => ['USDT_IRT' => 'usdt', 'PAXG_IRT' => '@ounce', 'XAUT_IRT' => '@ounce'],
        ],
        'custom' => [
            'label' => 'منبعِ دلخواه (JSON)', 'site' => '',
            'key' => 'optional', 'monthly' => 0, 'parser' => 'paths',
            'urls' => [], 'map' => [],
        ],
    ];

    /** بیش از این جهش نسبت به نرخِ قبلی (۵۰٪) بی‌صدا پذیرفته نمی‌شود. */
    public const MAX_JUMP = 0.5;
    /** نرخِ قبلی‌ای که از این کهنه‌تر است (روز) مبنای سنجشِ جهش نیست. */
    public const JUMP_MAX_AGE_DAYS = 7;
    /** بعد از این (ساعت) نرخ «کهنه» نشان داده می‌شود — دو برابرِ فاصله‌ی cron. */
    public const STALE_HOURS = 13;
    public const OUNCE_GRAMS = 31.1034768;
    /** قیمتِ مثقالِ آبشده = گرمِ ۱۸ × ۴٫۳۳۱۸ (فرمولِ رایجِ بازار). */
    public const MESGHAL_PER_GRAM18 = 4.3318;

    /** ⛔ فقط برای تستِ خطِ فرمان: جای HTTPِ واقعی. از وب هرگز خوانده نمی‌شود. */
    public static ?Closure $httpForTests = null;

    private static ?array $cache = null;
    /** @var callable[] بعد از هر نوشتنِ نرخ صدا زده می‌شوند (لایه‌ی فروشگاه). */
    private static array $listeners = [];

    /** مصرف‌کننده‌ی دیگری (فروشگاه) که بعد از هر نرخِ تازه باید خبر شود. */
    public static function listen(callable $fn): void { self::$listeners[] = $fn; }

    /** بعد از هر نوشتن: نوعِ دارایی‌ها، بعد هر شنونده. */
    private static function changed(): void
    {
        self::applyToAssets();
        foreach (self::$listeners as $fn) {
            try { $fn(); } catch (Throwable $e) { Log::error('rates.listener', $e); }
        }
    }

    public static function available(): bool
    {
        return tableExists('market_rates') && tableExists('rate_sources');
    }

    public static function label(string $code): string { return self::CODES[$code][0] ?? $code; }
    public static function unit(string $code): string  { return self::CODES[$code][1] ?? ''; }
    public static function isCode(?string $code): bool { return $code !== null && isset(self::CODES[$code]); }

    /** همه‌ی نرخ‌های ذخیره‌شده، کد ⇒ ردیف (یک کوئری در هر درخواست). */
    public static function all(): array
    {
        if (self::$cache !== null) { return self::$cache; }
        if (!self::available()) { return self::$cache = []; }
        $out = [];
        foreach (Database::getConnection()->query('SELECT code, price, prev_price, source, manual, fetched_at FROM market_rates') as $r) {
            if (isset(self::CODES[$r['code']])) { $out[$r['code']] = $r; }
        }
        return self::$cache = $out;
    }

    public static function price(string $code): ?int
    {
        $r = self::all()[$code] ?? null;
        return $r ? (int)$r['price'] : null;
    }

    public static function isStale(?array $row): bool
    {
        return !$row || strtotime((string)$row['fetched_at']) < time() - self::STALE_HOURS * 3600;
    }

    public static function forget(): void { self::$cache = null; }

    /**
     * نام و واحدِ یک نوعِ دارایی ⇒ کدِ نرخ، یا null.
     *
     * **خواسته‌ی مالکِ نصب:** «وقتی دارایی طلا یا دلار می‌ذاره به تومن در لحظه
     * حساب بشه» — یعنی کاربر نباید خودش بداند «وصل به نرخ» چیست. نوعِ «دلار»
     * با واحدِ «دلار» خودش وصل می‌شود.
     * ⛔ **واحد هم باید بخواند**، نه فقط نام: «طلا» با واحدِ «مثقال» یا «سوت» به
     *    گرمِ ۱۸ وصل نمی‌شود — ارزش چهار یا هزار برابر غلط درمی‌آمد. هر چه
     *    مطمئن نیست null است و کاربر دستی وصل می‌کند.
     */
    public static function guessCode(string $name, string $unit): ?string
    {
        $n = str_replace(['ي', 'ك', '‌', ' '], ['ی', 'ک', '', ''], toLatinDigits(mb_strtolower(trim($name))));
        $u = str_replace(['ي', 'ك', '‌', ' '], ['ی', 'ک', '', ''], mb_strtolower(trim($unit)));
        $isGram = in_array($u, ['گرم', 'gr', 'g', 'gram'], true);
        $isCount = in_array($u, ['عدد', 'سکه', 'قطعه', ''], true);
        $has = fn(string ...$w): bool => (bool)array_filter($w, fn($x) => str_contains($n, $x));
        if ($has('دلار', 'usd') && !$has('کانادا', 'استرالیا') && in_array($u, ['دلار', 'usd', '$', 'عدد', ''], true)) { return 'usd'; }
        if ($has('یورو', 'eur') && in_array($u, ['یورو', 'eur', '€', 'عدد', ''], true)) { return 'eur'; }
        if ($has('درهم', 'aed') && in_array($u, ['درهم', 'aed', 'عدد', ''], true)) { return 'aed'; }
        if ($has('تتر', 'usdt') && in_array($u, ['تتر', 'usdt', 'عدد', ''], true)) { return 'usdt'; }
        if ($has('سکه')) {
            if (!$isCount) { return null; }
            if ($has('ربع')) { return 'coin_quarter'; }
            if ($has('نیم')) { return 'coin_half'; }
            if ($has('گرمی')) { return 'coin_gram'; }
            if ($has('بهار')) { return 'coin_bahar'; }
            if ($has('امامی', 'تمام')) { return 'coin_emami'; }
            return null;
        }
        if ($has('طلا', 'gold')) {
            if ($has('آبشده', 'ابشده') || $u === 'مثقال') { return $u === 'مثقال' ? 'mesghal' : null; }
            if (!$isGram) { return null; }
            if ($has('24')) { return 'gold24'; }
            return 'gold18';   // طلای گرمیِ بی‌عیار در بازارِ ایران یعنی ۱۸
        }
        return null;
    }

    /* ---------------------------------------------------------------
       منبع‌ها
       --------------------------------------------------------------- */

    /**
     * تنظیمِ همه‌ی منبع‌ها به ترتیبِ اولویت، با پیش‌فرض برای منبعی که هنوز
     * ردیف ندارد. ⚠ پیش‌فرض فقط بیت‌پین روشن است (کلید نمی‌خواهد)؛ بقیه با
     * گذاشتنِ کلید یا آدرس روشن می‌شوند.
     */
    public static function sources(): array
    {
        $rows = [];
        if (self::available()) {
            foreach (Database::getConnection()->query('SELECT * FROM rate_sources') as $r) { $rows[$r['provider']] = $r; }
        }
        $out = [];
        $i = 0;
        foreach (self::PROVIDERS as $p => $def) {
            $i++;   // ⚠ پیش‌فرضِ ترتیب جای خودِ منبع در فهرست است، نه شمارِ ردیف‌های نداشته
            $r = $rows[$p] ?? null;
            $out[$p] = [
                'provider'    => $p,
                'label'       => $def['label'],
                'site'        => $def['site'],
                'key_need'    => $def['key'],
                'monthly'     => $def['monthly'],
                'enabled'     => $r ? (int)$r['enabled'] === 1 : $p === 'bitpin',
                'priority'    => $r ? (int)$r['priority'] : $i * 10,
                'has_key'     => $r && (string)$r['api_key'] !== '',
                'url'         => $r ? (string)($r['url'] ?? '') : '',
                'paths'       => $r ? (string)($r['paths'] ?? '') : '',
                'in_rial'     => $r ? (int)$r['in_rial'] === 1 : false,
                'last_try_at' => $r['last_try_at'] ?? null,
                'last_ok_at'  => $r['last_ok_at'] ?? null,
                'last_error'  => $r['last_error'] ?? null,
                'month_calls' => $r && ($r['month_key'] ?? '') === date('Y-m') ? (int)$r['month_calls'] : 0,
            ];
        }
        uasort($out, fn($a, $b) => [$a['priority'], $a['provider']] <=> [$b['priority'], $b['provider']]);
        return $out;
    }

    private static function ensureSource(string $p): void
    {
        $def = self::sources()[$p];
        Database::getConnection()->prepare(
            'INSERT IGNORE INTO rate_sources (provider, enabled, priority) VALUES (:p, :e, :o)'
        )->execute(['p' => $p, 'e' => $def['enabled'] ? 1 : 0, 'o' => $def['priority']]);
    }

    /**
     * ذخیره‌ی تنظیمِ یک منبع از صفحه‌ی مدیر.
     * ⚠ کلیدِ خالی یعنی «دست نزن» (همان قاعده‌ی `saveUserEmail()`)؛ برای پاک
     *   کردن `clear_key`. آدرس از سدِ SSRF (`SafeFetch::check()`) می‌گذرد.
     * @return array{ok:bool, message:string}
     */
    public static function saveSource(string $p, array $in): array
    {
        if (!isset(self::PROVIDERS[$p])) { return ['ok' => false, 'message' => 'منبعِ ناشناخته.']; }
        if (!self::available()) { return ['ok' => false, 'message' => 'جدولِ نرخ‌ها نیست؛ migration_rates را اجرا کنید.']; }
        self::ensureSource($p);
        $url = trim((string)($in['url'] ?? ''));
        if ($url !== '') {
            if (mb_strlen($url) > 500) { return ['ok' => false, 'message' => 'آدرس بیش از حد بلند است.']; }
            $chk = self::urlCheck(str_replace('{key}', 'x', $url));
            if (!$chk['ok']) { return ['ok' => false, 'message' => $chk['message']]; }
        }
        if ($p === 'custom' && $url === '' && !empty($in['enabled'])) {
            return ['ok' => false, 'message' => 'برای منبعِ دلخواه آدرس لازم است.'];
        }
        $paths = null;
        if ($p === 'custom') {
            $paths = [];
            foreach ((array)($in['paths'] ?? []) as $code => $path) {
                $path = trim((string)$path);
                if ($path !== '' && isset(self::CODES[$code])) {
                    if (!preg_match('~^[A-Za-z0-9_\-.]{1,120}$~', $path)) {
                        return ['ok' => false, 'message' => 'مسیرِ «' . self::label($code) . '» فقط حرف و عدد و نقطه می‌پذیرد (مثلاً data.usd.price).'];
                    }
                    $paths[$code] = $path;
                }
            }
            $paths = $paths ? json_encode($paths, JSON_UNESCAPED_UNICODE) : null;
        }
        $set = ['enabled = :e', 'priority = :o', 'url = :url', 'in_rial = :r'];
        $par = ['e' => empty($in['enabled']) ? 0 : 1, 'o' => (int)($in['priority'] ?? 0),
                'url' => $url === '' ? null : $url, 'r' => empty($in['in_rial']) ? 0 : 1, 'p' => $p];
        if ($p === 'custom') { $set[] = 'paths = :paths'; $par['paths'] = $paths; }
        $key = self::cleanKey((string)($in['api_key'] ?? ''));
        if (!empty($in['clear_key'])) { $set[] = 'api_key = NULL'; }
        elseif ($key !== '') {
            if (mb_strlen($key) > 300) { return ['ok' => false, 'message' => 'کلید بیش از حد بلند است.'];}
            $set[] = 'api_key = :k'; $par['k'] = Crypto::encrypt($key);
        }
        Database::getConnection()->prepare('UPDATE rate_sources SET ' . implode(', ', $set) . ' WHERE provider = :p')->execute($par);
        return ['ok' => true, 'message' => 'تنظیمِ «' . self::PROVIDERS[$p]['label'] . '» ذخیره شد.'];
    }

    private static function apiKey(string $p): string
    {
        $st = Database::getConnection()->prepare('SELECT api_key FROM rate_sources WHERE provider = :p');
        $st->execute(['p' => $p]);
        $v = $st->fetchColumn();
        // ⚠ هنگامِ خواندن هم پاک می‌شود — کلیدی که پیش از این قاعده ذخیره شده بود هم درست برود.
        return $v ? self::cleanKey((string)(Crypto::decrypt((string)$v) ?? '')) : '';
    }

    /**
     * ⛔ کلید هیچ فاصله و نویسه‌ی نامرئی‌ای ندارد. کلیدِ نوسان از پیامِ تلگرام روی
     *    گوشی کپی می‌شود و کپی از کنارِ متنِ فارسی، نشانه‌ی جهت (U+200F/U+200E)،
     *    نیم‌فاصله یا شکستِ خط را هم با خودش می‌آورد — کلید در خانه درست دیده
     *    می‌شد و سرویس ردش می‌کرد («در پروژه‌ی دیگر با همین کلید کار می‌کند»).
     */
    public static function cleanKey(string $k): string
    {
        return (string)preg_replace('/[\s\p{Cf}]+/u', '', $k);
    }

    /** تکه‌ی کوتاهی از پاسخ برای پیامِ خطا — بی‌برچسب، یک‌خطی، کلید پوشانده. */
    private static function snippet(string $body, string $key): string
    {
        $t = trim((string)preg_replace('~\s+~u', ' ', strip_tags($body)));
        if ($key !== '') { $t = str_replace($key, '••••', $t); }
        return $t === '' ? '' : ' — پاسخ: «' . mb_substr($t, 0, 140) . (mb_strlen($t) > 140 ? '…' : '') . '»';
    }

    /* ---------------------------------------------------------------
       خواندنِ پاسخ — خالص، بی‌شبکه (همین‌ها تست می‌شوند)
       --------------------------------------------------------------- */

    /** عدد از هر شکلی: «۶۰,۵۰۰»، "60500.0"، 60500 — وگرنه null. */
    public static function num($v): ?float
    {
        if (is_int($v) || is_float($v)) { return $v > 0 ? (float)$v : null; }
        if (!is_string($v)) { return null; }
        $s = strtr(trim($v), ['۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4', '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
                               '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4', '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
                               ',' => '', '٬' => '', ' ' => '', '٫' => '.']);
        if (!preg_match('~^\d+(\.\d+)?$~', $s)) { return null; }
        $f = (float)$s;
        return $f > 0 ? $f : null;
    }

    /**
     * پاسخِ خام ⇒ [کد ⇒ تومان]. `@ounce` به گرم تبدیل می‌شود.
     * ⚠ `in_rial` تقسیم بر ۱۰ است، و `unit`ِ «ریال» در خودِ ردیف هم (BrsApi).
     */
    public static function parse(string $p, string $body, bool $inRial = false, array $paths = []): array
    {
        $json = json_decode($body, true);
        if (!is_array($json)) { return []; }
        $def = self::PROVIDERS[$p] ?? null;
        if (!$def) { return []; }
        $raw = [];
        if ($def['parser'] === 'symbols') {
            self::walkSymbols($json, $def['map'], $raw);
        } elseif ($def['parser'] === 'keyed') {
            foreach ($def['map'] as $k => $code) {
                if (!array_key_exists($k, $json)) { continue; }
                $v = $json[$k];
                $n = is_array($v) ? self::num($v['value'] ?? $v['price'] ?? null) : self::num($v);
                if ($n !== null) { $raw[$code] ??= $n; }
            }
        } else {
            foreach ($paths as $code => $path) {
                $v = $json;
                foreach (explode('.', $path) as $seg) {
                    if (!is_array($v) || !array_key_exists($seg, $v)) { $v = null; break; }
                    $v = $v[$seg];
                }
                $n = self::num($v);
                if ($n !== null && isset(self::CODES[$code])) { $raw[$code] = $n; }
            }
        }
        $out = [];
        foreach ($raw as $code => $n) {
            if ($inRial) { $n /= 10; }
            if ($code === '@ounce') {
                $g24 = $n / self::OUNCE_GRAMS;
                $out['@gold24'] = (int)round($g24);
                continue;
            }
            $out[$code] = (int)round($n);
        }
        return array_filter($out, fn($v) => $v > 0);
    }

    /** هر شیءِ دارای «symbol»/«code» و «price»، در هر عمقی. */
    private static function walkSymbols(array $node, array $map, array &$raw): void
    {
        $sym = $node['symbol'] ?? $node['code'] ?? null;
        if (is_string($sym)) {
            $code = $map[strtoupper($sym)] ?? null;
            $n = self::num($node['price'] ?? $node['last'] ?? $node['value'] ?? null);
            if ($code !== null && $n !== null) {
                $unit = (string)($node['unit'] ?? '');
                if (str_contains($unit, 'ریال') || strtolower($unit) === 'rial' || strtolower($unit) === 'irr') { $n /= 10; }
                $raw[$code] ??= $n;
            }
        }
        foreach ($node as $v) {
            if (is_array($v)) { self::walkSymbols($v, $map, $raw); }
        }
    }

    /**
     * نرخ‌های ساختنی از بقیه — فقط وقتی خودِ کد از هیچ منبعی نیامده.
     * منبعِ ساخته‌شده «از …» علامت می‌خورد تا مدیر بداند عدد مستقیم نیست.
     * ⚠ دلار از تتر ساخته نمی‌شود: دو بازارِ جدایند و اختلافشان گاهی چند
     *   درصد است — داراییِ دلاری با عددِ تتر «دقیق» نیست.
     * @param array<string,array{0:int,1:string}> $got
     */
    public static function derive(array &$got): void
    {
        $src = function (string $k) use (&$got): string {
            return preg_replace('~ \(.*$~u', '', $got[$k][1]) . ' (محاسبه‌شده)';
        };
        if (!isset($got['gold24']) && isset($got['@gold24'])) {
            $got['gold24'] = [$got['@gold24'][0], $src('@gold24')];
        }
        unset($got['@gold24']);
        if (!isset($got['gold18']) && isset($got['gold24'])) {
            $got['gold18'] = [(int)round($got['gold24'][0] * 0.75), $src('gold24')];
        }
        if (!isset($got['gold24']) && isset($got['gold18'])) {
            $got['gold24'] = [(int)round($got['gold18'][0] / 0.75), $src('gold18')];
        }
        if (!isset($got['mesghal']) && isset($got['gold18'])) {
            $got['mesghal'] = [(int)round($got['gold18'][0] * self::MESGHAL_PER_GRAM18), $src('gold18')];
        }
    }

    /**
     * ⛔ سدِ دقت. null = پذیرفته؛ وگرنه دلیلِ رد.
     */
    public static function jumpError(int $new, ?array $prev): ?string
    {
        if ($new <= 0) { return 'عددِ نامعتبر'; }
        if (!$prev || (int)$prev['price'] <= 0) { return null; }
        if (strtotime((string)$prev['fetched_at']) < time() - self::JUMP_MAX_AGE_DAYS * 86400) { return null; }
        $ratio = $new / (int)$prev['price'];
        if (abs($ratio - 1) > self::MAX_JUMP) {
            return 'جهشِ مشکوک (' . round(($ratio - 1) * 100) . '٪ نسبت به نرخِ قبلی)'
                 . ($ratio > 8 && $ratio < 12 ? ' — شاید منبع ریال می‌دهد' : '');
        }
        return null;
    }

    /* ---------------------------------------------------------------
       دریافت
       --------------------------------------------------------------- */

    private static function urlCheck(string $url): array
    {
        require_once __DIR__ . '/safe_fetch.php';
        return SafeFetch::check($url);
    }

    /** @return array{ok:bool, body?:string, message?:string} */
    private static function http(string $url): array
    {
        if (self::$httpForTests !== null && PHP_SAPI === 'cli') { return (self::$httpForTests)($url); }
        require_once __DIR__ . '/safe_fetch.php';
        return SafeFetch::get($url, 2 * 1024 * 1024);
    }

    /**
     * آدرسِ جایگزینِ «فقط نشانیِ پایه» ⇒ با ادامه‌ی آدرسِ پیش‌فرضِ همان منبع.
     *
     * ⛔ مالکِ نصب در «آدرسِ جایگزین»ِ نوسان `http://api.navasan.tech` نوشت —
     *    همان نشانیِ پایه‌ای که راهنمای نوسان می‌دهد — و درخواست بی‌`/latest/` و
     *    بی‌کلید به خودِ دامنه می‌رفت و هیچ نرخی برنمی‌گشت. پس آدرسی که نه مسیر
     *    دارد، نه پرس‌وجو، نه `{key}`، پایه فرض می‌شود و ادامه‌اش از آدرسِ پیش‌فرض
     *    می‌آید (`/latest/?api_key={key}`). آدرسِ کامل دست نمی‌خورد.
     */
    public static function expandUrl(string $p, string $url): string
    {
        $url = trim($url);
        $u = parse_url($url);
        $def = self::PROVIDERS[$p]['urls'][0] ?? null;
        if (!$u || !$def || str_contains($url, '{key}') || isset($u['query']) || trim((string)($u['path'] ?? ''), '/') !== '') {
            return $url;
        }
        $d = parse_url($def);
        return rtrim($url, '/') . ($d['path'] ?? '/') . (isset($d['query']) ? '?' . $d['query'] : '');
    }

    /**
     * یک منبع ⇒ [ok, rates, error, url, body]. آدرس‌ها به ترتیب، تا اولی که
     * عددی داد. `body` فقط برای `--probe` است.
     */
    public static function fetchOne(string $p, ?array $src = null): array
    {
        $src ??= self::sources()[$p] ?? null;
        if (!$src) { return ['ok' => false, 'rates' => [], 'error' => 'منبعِ ناشناخته']; }
        $key   = self::apiKey($p);
        $paths = $src['paths'] !== '' ? (json_decode($src['paths'], true) ?: []) : [];
        $urls  = $src['url'] !== '' ? [self::expandUrl($p, $src['url'])] : self::PROVIDERS[$p]['urls'];
        if (!$urls) { return ['ok' => false, 'rates' => [], 'error' => 'آدرس تعیین نشده']; }
        // ⚠ خطای **هر** آدرس گزارش می‌شود، نه فقط آخری: با https و http، آخری
        //   («وصل نشد») خطای مفیدِ اولی («کلید نامعتبر») را می‌پوشاند.
        $errs = [];
        $last = '';
        foreach ($urls as $u) {
            $u = str_replace('{key}', rawurlencode($key), $u);
            $res = self::http($u);
            $scheme = (string)(parse_url($u, PHP_URL_SCHEME) ?? '');
            if (!$res['ok']) {
                $body = (string)($res['body'] ?? '');
                $errs[] = $scheme . ': ' . (string)($res['message'] ?? 'خطا') . self::snippet($body, $key);
                if ($body !== '') { $last = $body; }
                continue;
            }
            $rates = self::parse($p, (string)$res['body'], $src['in_rial'], $paths);
            if ($rates) { return ['ok' => true, 'rates' => $rates, 'error' => null, 'url' => $u, 'body' => (string)$res['body']]; }
            $errs[] = $scheme . ': پاسخ آمد ولی هیچ نرخِ شناخته‌شده‌ای در آن نبود' . self::snippet((string)$res['body'], $key);
            $last = (string)$res['body'];
        }
        $err = $errs ? implode(' · ', array_unique($errs)) : 'پاسخی نیامد';
        if ($key === '' && self::PROVIDERS[$p]['key'] === 'required') { $err = 'کلید وارد نشده — ' . $err; }
        return ['ok' => false, 'rates' => [], 'error' => $err, 'body' => $last];
    }

    /**
     * ⛔ تنها نویسنده‌ی خودکارِ `market_rates`. منبع‌های روشن به ترتیبِ اولویت؛
     *    هر کد از **اولین** منبعی که داشتش. منبعِ بعدی فقط وقتی صدا زده
     *    می‌شود که کدی هنوز خالی است — سقفِ ماهانه‌ی نوسان حرام نمی‌شود.
     * @return array{written:array, rejected:array, report:array}
     */
    public static function refresh(?array $only = null): array
    {
        if (!self::available()) { return ['written' => [], 'rejected' => [], 'report' => ['_' => 'جدولِ نرخ‌ها نیست']]; }
        $pdo = Database::getConnection();
        self::forget();
        $prev = self::all();
        $need = array_keys(array_filter(self::CODES, fn($c, $k) => empty($prev[$k]['manual']), ARRAY_FILTER_USE_BOTH));
        $got = [];
        $report = [];
        foreach (self::sources() as $p => $src) {
            if ($only !== null ? !in_array($p, $only, true) : !$src['enabled']) { continue; }
            $missing = array_diff($need, array_keys($got));
            if (!$missing && $only === null) { $report[$p] = 'لازم نشد'; continue; }
            if ($src['monthly'] > 0 && $src['month_calls'] >= $src['monthly']) {
                $report[$p] = 'سقفِ ماهانه (' . $src['monthly'] . ') پر شده'; continue;
            }
            self::ensureSource($p);
            $r = self::fetchOne($p, $src);
            $mk = date('Y-m');
            $pdo->prepare(
                'UPDATE rate_sources SET last_try_at = NOW(), last_ok_at = IF(:ok, NOW(), last_ok_at), last_error = :e,
                        month_calls = IF(month_key = :mk, month_calls + 1, 1), month_key = :mk2 WHERE provider = :p'
            )->execute(['ok' => $r['ok'] ? 1 : 0, 'e' => $r['ok'] ? null : mb_substr((string)$r['error'], 0, 250),
                        'mk' => $mk, 'mk2' => $mk, 'p' => $p]);
            $report[$p] = $r['ok'] ? count($r['rates']) . ' نرخ' : (string)$r['error'];
            foreach ($r['rates'] as $code => $v) { $got[$code] ??= [$v, $p]; }
        }
        self::derive($got);

        $written = [];
        $rejected = [];
        $up = $pdo->prepare(
            'INSERT INTO market_rates (code, price, prev_price, source, manual, fetched_at)
             VALUES (:c, :p, NULL, :s, 0, NOW())
             ON DUPLICATE KEY UPDATE prev_price = IF(price <> VALUES(price), price, prev_price),
                                     price = VALUES(price), source = VALUES(source), fetched_at = VALUES(fetched_at)'
        );
        foreach ($got as $code => [$v, $source]) {
            if (!in_array($code, $need, true)) { continue; }
            $why = self::jumpError($v, $prev[$code] ?? null);
            if ($why !== null) {
                $rejected[$code] = $why . ' — ' . $source;
                Log::warn('rates.rejected', ['code' => $code, 'value' => $v, 'source' => $source, 'why' => $why]);
                continue;
            }
            $up->execute(['c' => $code, 'p' => $v, 's' => mb_substr($source, 0, 40)]);
            $written[$code] = $v;
        }
        self::forget();
        if ($written) { self::changed(); }
        return ['written' => $written, 'rejected' => $rejected, 'report' => $report];
    }

    /**
     * نرخِ دستیِ مدیر. `null` = «دوباره خودکار» (عدد می‌ماند تا دریافتِ بعد).
     * @return array{ok:bool, message:string}
     */
    public static function setManual(string $code, ?int $price): array
    {
        if (!isset(self::CODES[$code])) { return ['ok' => false, 'message' => 'نرخِ ناشناخته.']; }
        if (!self::available()) { return ['ok' => false, 'message' => 'جدولِ نرخ‌ها نیست؛ migration_rates را اجرا کنید.']; }
        $pdo = Database::getConnection();
        if ($price === null) {
            $pdo->prepare('UPDATE market_rates SET manual = 0 WHERE code = :c')->execute(['c' => $code]);
            self::forget();
            return ['ok' => true, 'message' => '«' . self::label($code) . '» دوباره خودکار شد.'];
        }
        if ($price <= 0) { return ['ok' => false, 'message' => 'نرخ باید بیشتر از صفر باشد.']; }
        $pdo->prepare(
            'INSERT INTO market_rates (code, price, prev_price, source, manual, fetched_at) VALUES (:c, :p, NULL, \'manual\', 1, NOW())
             ON DUPLICATE KEY UPDATE prev_price = IF(price <> VALUES(price), price, prev_price), price = VALUES(price),
                                     source = \'manual\', manual = 1, fetched_at = NOW()'
        )->execute(['c' => $code, 'p' => $price]);
        self::forget();
        self::changed();
        return ['ok' => true, 'message' => 'نرخِ «' . self::label($code) . '» دستی گذاشته شد.'];
    }

    /* ---------------------------------------------------------------
       مصرف‌کننده‌ها
       --------------------------------------------------------------- */

    /**
     * ⛔ نوعِ داراییِ وصل‌شده ⇐ نرخِ روز. `current_price` همان ستونی است که
     *    `assetSummaryRows()` و خالص دارایی از آن می‌خوانند
     *    (`COALESCE(current_price, unit_price)`)، پس هیچ محاسبه‌ی دومی ساخته
     *    نشد. هر کاربر جدا (`WHERE user_id`)، قاعده‌ی ۱.
     * @return int ردیف‌های عوض‌شده
     */
    public static function applyToAssets(?int $userId = null): int
    {
        if (!self::available() || !tableHasColumn('asset_types', 'rate_code')) { return 0; }
        $pdo = Database::getConnection();
        $users = $userId !== null ? [$userId]
            : $pdo->query('SELECT DISTINCT user_id FROM asset_types WHERE rate_code IS NOT NULL')->fetchAll(PDO::FETCH_COLUMN);
        $st = $pdo->prepare(
            'UPDATE asset_types at JOIN market_rates r ON r.code = at.rate_code
                SET at.current_price = r.price, at.price_updated_at = DATE(r.fetched_at)
              WHERE at.user_id = :u AND at.rate_code IS NOT NULL
                AND (at.current_price IS NULL OR at.current_price <> r.price OR at.price_updated_at IS NULL OR at.price_updated_at <> DATE(r.fetched_at))'
        );
        $n = 0;
        foreach ($users as $u) { $st->execute(['u' => (int)$u]); $n += $st->rowCount(); }
        return $n;
    }

    /* ---------------------------------------------------------------
       نمایش
       --------------------------------------------------------------- */

    /**
     * «۱۲ ساعت پیش» — برای کارت‌های نرخ.
     * ⚠ کنارش «·» ننویسید: نقطه‌ی وسط کنارِ رقمِ فارسی عیناً «۰» دیده می‌شود
     *   («· ۲ ساعت» در راست‌به‌چپ «۲۰ ساعت» خوانده شد)؛ «،» بگذارید.
     */
    public static function ago(?string $dt): string
    {
        if (!$dt) { return 'هرگز'; }
        $s = max(0, time() - strtotime($dt));
        if ($s < 3600)  { return toPersianDigits((string)max(1, intdiv($s, 60))) . ' دقیقه پیش'; }
        if ($s < 86400) { return toPersianDigits((string)intdiv($s, 3600)) . ' ساعت پیش'; }
        return toPersianDigits((string)intdiv($s, 86400)) . ' روز پیش';
    }

    /** `<option>`های انتخابِ نرخ، گروه‌بندی‌شده — برای فرمِ دارایی و کالا. */
    public static function optionsHtml(?string $selected, string $none = 'بدونِ نرخِ خودکار'): string
    {
        $rates = self::all();
        $html = '<option value="">' . h($none) . '</option>';
        $groups = [];
        foreach (self::CODES as $code => [$label, $unit, $g]) { $groups[$g][$code] = [$label, $unit]; }
        foreach ($groups as $g => $items) {
            $html .= '<optgroup label="' . h($g) . '">';
            foreach ($items as $code => [$label, $unit]) {
                $p = isset($rates[$code]) ? ' — ' . formatMoney((int)$rates[$code]['price']) . ' هر ' . $unit : ' — هنوز نرخی نیامده';
                $html .= '<option value="' . h($code) . '" data-unit="' . h($unit) . '" data-price="' . (int)($rates[$code]['price'] ?? 0) . '"'
                       . ($selected === $code ? ' selected' : '') . '>' . h($label . $p) . '</option>';
            }
            $html .= '</optgroup>';
        }
        return $html;
    }
}
