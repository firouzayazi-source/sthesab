<?php
/**
 * ⛔ محیطِ فروشگاهی — مرحله‌ی ۲: کالا و موجودی، طرف‌حساب، صندوق.
 *
 * هر چهار کلاس فقط روی جدول‌های `biz_*` کار می‌کنند و **هیچ‌کدام** به
 * جدول‌های حساب لندِ شخصی (`transactions`، `wallets`، `debts`، …) دست
 * نمی‌زنند. حسابی که هم حساب لند دارد هم فروشگاه (`both`) دو دفترِ کاملاً
 * جدا دارد؛ «موجودیِ صندوقِ فروشگاه» هرگز در `walletBalances()` نمی‌نشیند.
 *
 * - `BizProducts` — کالا (تعریف، جست‌وجو، فعال/غیرفعال).
 * - `BizStock`    — ⛔ تنها نویسنده‌ی `stock_qty`/`avg_cost` و تنها جای
 *                   حرکتِ انبار. موجودی هرگز منفی نمی‌شود (تصمیمِ مالکِ
 *                   نصب: «فروشِ بیش از موجودی بسته است»).
 * - `BizParties`  — مشتری و تأمین‌کننده.
 * - `BizCash`     — صندوق و حساب‌های بانکیِ خودِ فروشگاه.
 *
 * ⛔ `user_id` همیشه از فراخواننده می‌آید (که آن را از `Auth::userId()`
 *    گرفته) و روی **هر** کوئری هست، از جمله `UPDATE` و `DELETE`.
 */

require_once __DIR__ . '/db.php';

/** ابزارهای مشترکِ این فایل. */
final class BizCommon
{
    /** فرارِ `%` و `_` برای `LIKE … ESCAPE '!'` — همان قاعده‌ی tx_query. */
    public static function like(string $term): string
    {
        return '%' . strtr($term, ['!' => '!!', '%' => '!%', '_' => '!_']) . '%';
    }

    /**
     * عدد به حروف — «مبلغ به حروف»ِ فاکتور. سه‌رقمی‌ها با « و » به هم
     * می‌پیوندند (۱٬۲۰۰٬۰۵۰ → «یک میلیون و دویست هزار و پنجاه»)، و «یکصد»
     * نه «صد»، چون متنِ رسمیِ فاکتور همین را می‌خواهد. کلِ بازه‌ی BIGINT.
     */
    public static function words(int $n): string
    {
        if ($n === 0) { return 'صفر'; }
        static $ones  = ['', 'یک', 'دو', 'سه', 'چهار', 'پنج', 'شش', 'هفت', 'هشت', 'نه'];
        static $teens = ['ده', 'یازده', 'دوازده', 'سیزده', 'چهارده', 'پانزده', 'شانزده', 'هفده', 'هجده', 'نوزده'];
        static $tens  = ['', '', 'بیست', 'سی', 'چهل', 'پنجاه', 'شصت', 'هفتاد', 'هشتاد', 'نود'];
        static $hund  = ['', 'یکصد', 'دویست', 'سیصد', 'چهارصد', 'پانصد', 'ششصد', 'هفتصد', 'هشتصد', 'نهصد'];
        static $scale = ['', 'هزار', 'میلیون', 'میلیارد', 'تریلیون', 'کوادریلیون', 'کوینتیلیون'];
        $three = static function (int $x) use ($ones, $teens, $tens, $hund): string {
            $p = [];
            if ($x >= 100) { $p[] = $hund[intdiv($x, 100)]; $x %= 100; }
            if ($x >= 20)  { $p[] = $tens[intdiv($x, 10)]; $x %= 10; }
            if ($x >= 10)  { $p[] = $teens[$x - 10]; $x = 0; }
            if ($x > 0)    { $p[] = $ones[$x]; }
            return implode(' و ', $p);
        };
        // ⚠ روی رقم‌های رشته، نه `-$n`: قدرِ مطلقِ PHP_INT_MIN در int نمی‌گنجد
        $digits = ltrim((string)$n, '-');
        $parts  = [];
        for ($i = 0, $end = strlen($digits); $end > 0; $i++, $end -= 3) {
            $g = (int)substr($digits, max(0, $end - 3), $end - max(0, $end - 3));
            if ($g === 0) { continue; }
            array_unshift($parts, trim($three($g) . ' ' . $scale[$i]));
        }
        return ($n < 0 ? 'منفی ' : '') . implode(' و ', $parts);
    }

    /** یک‌خطی، بی‌فاصله‌ی اضافه. */
    public static function line(string $v): string
    {
        return trim((string)preg_replace('/\s+/u', ' ', $v));
    }

    /**
     * ⛔ «ي/ى» و «ك»ِ عربی → «ی» و «ک»ِ فارسی. `utf8mb4_persian_ci` این دو را
     *    **یکی نمی‌داند**، پس کالایی که از اکسل آمده با کیبوردِ فارسی پیدا
     *    نمی‌شد (نه در پیشنهاد، نه در تطبیقِ سرور، نه در فهرست). نام‌ها با
     *    همین نوشته و جست‌وجوها با همین خوانده می‌شوند.
     */
    public static function persian(string $v): string
    {
        return strtr($v, ['ي' => 'ی', 'ى' => 'ی', 'ك' => 'ک']);
    }

    /**
     * کلیدِ تطبیقِ «از نظرِ آدم یکی»: حروفِ عربی، ارقامِ فارسی، اعراب و
     * کشیده، نیم‌فاصله و فاصله‌ی اضافه، و بزرگی/کوچکیِ حروفِ لاتین.
     * ⛔ فقط برای **مقایسه‌ی برابری** — هیچ‌جا ذخیره نمی‌شود.
     */
    public static function fold(string $v): string
    {
        // «آیفون» و «ایفون» یکی‌اند: کاربر روی کیبوردِ گوشی اغلب «آ» را نمی‌زند
        $v = strtr(toLatinDigits(self::persian($v)), ['آ' => 'ا', 'أ' => 'ا', 'إ' => 'ا', 'ٱ' => 'ا', 'ؤ' => 'و', 'ة' => 'ه', 'ۀ' => 'ه']);
        $v = (string)preg_replace('/[\x{064B}-\x{065F}\x{0670}\x{0640}]/u', '', $v);
        $v = (string)preg_replace('/[\s\x{200c}\x{200f}\x{200e}]+/u', ' ', $v);
        return mb_strtolower(trim($v));
    }

    /**
     * صفحه‌بندی — `[page, pages, offset]`؛ شماره‌ی بیرون از بازه به
     * نزدیک‌ترین صفحه بریده می‌شود، نه فهرستِ خالی (همان قاعده‌ی
     * `paged_list.php`).
     */
    public static function window(int $total, int $page, int $size): array
    {
        $pages = max(1, (int)ceil($total / max(1, $size)));
        $page  = min(max(1, $page), $pages);
        return [$page, $pages, ($page - 1) * $size];
    }

    /**
     * ⛔ تنها مرجعِ کدهای رسمی — فروشگاه (سربرگ) و طرف‌حساب (خریدار) هر دو از
     *    همین. [کمینه‌ی رقم، بیشینه‌ی رقم، برچسب]. شناسه‌ی ملیِ شرکت ۱۱ و کدِ
     *    ملیِ شخص ۱۰ رقم است؛ کدِ اقتصادیِ قدیمی ۱۲ و تازه ۱۴ (یا همان کدِ ملی).
     */
    public const CODES = [
        'national_id'   => [10, 11, 'شناسه‌ی ملی / کد ملی'],
        'economic_code' => [10, 14, 'کد اقتصادی'],
        'reg_no'        => [1, 20, 'شماره‌ی ثبت'],
        'postal_code'   => [10, 10, 'کد پستی'],
    ];

    /**
     * یک کدِ رسمی → فقط رقمِ لاتین (کیبوردِ فارسی «۱۲۳» و فاصله و خط‌تیره
     * می‌دهد). خالی یعنی «ندارد»، نه خطا.
     * @return array{ok:bool, value:?string, message?:string}
     */
    public static function code(string $key, string $raw): array
    {
        [$min, $max, $label] = self::CODES[$key];
        $v = (string)preg_replace('/[\s\-\/.\x{200c}]+/u', '', toLatinDigits(trim($raw)));
        if ($v === '') { return ['ok' => true, 'value' => null]; }
        if (!preg_match('/^\d+$/', $v) || strlen($v) < $min || strlen($v) > $max) {
            $len = $min === $max ? toPersianDigits((string)$min) : toPersianDigits((string)$min) . ' تا ' . toPersianDigits((string)$max);
            return ['ok' => false, 'value' => null, 'message' => $label . ' باید ' . $len . ' رقم باشد.'];
        }
        return ['ok' => true, 'value' => $v];
    }
}

/* =================================================================
   کالا
   ================================================================= */
final class BizProducts
{
    /**
     * ⛔ تنها مرجعِ واحدها؛ مقدار یعنی «اعشار می‌پذیرد؟». عدد و بسته
     *    نصفه فروخته نمی‌شوند، کیلوگرم و متر می‌شوند.
     */
    public const UNITS = [
        'عدد'     => false,
        'دستگاه'  => false,
        'بسته'    => false,
        'جفت'     => false,
        'کارتن'   => false,
        'کیلوگرم' => true,
        'گرم'     => true,
        'متر'     => true,
        'لیتر'    => true,
    ];

    public const LIMITS = ['name' => 150, 'sku' => 60, 'category' => 80, 'note' => 300];

    /**
     * ⛔ تنها مرجعِ نوعِ کالا (فروشگاهِ موبایل و لوازم جانبی). نوع ستونِ
     *    جدایی ندارد و از دو ستون ساخته می‌شود — `typeOf()`:
     *    خدمت = `track_stock = 0`، گوشی = `has_serial = 1` (هر واحد IMEI
     *    دارد و جداگانه خرید و فروش می‌شود)، بقیه = کالا و لوازم جانبی.
     */
    public const TYPES = [
        'goods'   => 'کالا / لوازم جانبی',
        'phone'   => 'گوشی (با IMEI)',
        'service' => 'خدمت (تعمیر، نصب…)',
    ];

    /** نوعِ یک ردیفِ کالا — تنها جای خواندنِ این تصمیم از دو ستون. */
    public static function typeOf(array $p): string
    {
        if ((int)($p['track_stock'] ?? 1) !== 1) { return 'service'; }
        return (int)($p['has_serial'] ?? 0) === 1 ? 'phone' : 'goods';
    }

    /** صافی‌های فهرست — تنها مرجع؛ ناشناخته به «همه» برمی‌گردد. */
    public const FILTERS = [
        ''         => 'همه',
        'low'      => 'کم‌موجودی',
        'out'      => 'ناموجود',
        'inactive' => 'غیرفعال',
    ];

    public const PAGE_SIZE = 25;

    /**
     * ⛔ تنها شکلِ خواندنِ کالا — نامِ دسته از `biz_categories` می‌آید
     *    (`migration_biz_docs`)، نه از ستونِ متنیِ قدیمیِ `category` که از آن
     *    migration به بعد همیشه NULL است. ⚠ `c.name AS category` **بعد از**
     *    `p.*` است: در PDO ستونِ هم‌نامِ بعدی برنده است.
     */
    public const SELECT_SQL = 'SELECT p.*, c.name AS category FROM biz_products p LEFT JOIN biz_categories c ON c.id = p.category_id';

    /**
     * ⛔ تنها جای صافی‌های فهرستِ کالا — فهرستِ صفحه‌بندی‌شده و چاپ هر دو
     *    از همین می‌گذرند، وگرنه «کم‌موجودی»ِ روی صفحه با «کم‌موجودی»ِ کاغذ
     *    دو فهرستِ متفاوت می‌شد.
     * @return array{0:string, 1:array}
     */
    private static function where(int $userId, string $q, string $filter, string $category): array
    {
        $where  = ['p.user_id = :u'];
        $params = ['u' => $userId];
        switch (isset(self::FILTERS[$filter]) ? $filter : '') {
            case 'low':
                $where[] = 'p.is_active = 1 AND p.track_stock = 1 AND p.min_stock > 0 AND p.stock_qty <= p.min_stock';
                break;
            case 'out':
                $where[] = 'p.is_active = 1 AND p.track_stock = 1 AND p.stock_qty <= 0';
                break;
            case 'inactive':
                $where[] = 'p.is_active = 0';
                break;
            default:
                $where[] = 'p.is_active = 1';
        }
        if ($q !== '') {
            // ⛔ دو پارامترِ جدا، نه یک نامِ تکراری (EMULATE_PREPARES = false)
            $qi = (string)preg_replace('/[\s\x{200c}\-\/.]+/u', '', toLatinDigits(trim($q)));
            if (preg_match('/^\d{14,17}$/', $qi)) {
                // IMEI (همان قاعده‌ی `BizSerial::valid`) → مدلِ همان گوشی، از ردیف‌های اسناد
                $where[] = '(p.sku = :qs OR p.id IN (SELECT ql.product_id FROM biz_invoice_lines ql
                              WHERE ql.user_id = :qu AND (ql.imei1 = :qi1 OR ql.imei2 = :qi2)))';
                $params['qs'] = $params['qi1'] = $params['qi2'] = $qi;
                $params['qu'] = $userId;
            } else {
                // ⛔ نامِ ذخیره‌شده هم یکسان مقایسه می‌شود (REPLACE با رشته‌ی ثابت، پس
                //    «Illegal mix of collations» ممکن نیست): کالایی که پیش از
                //    `migration_biz_search` با «ي/ك» آمده هم پیدا می‌شود.
                $where[] = "(REPLACE(REPLACE(REPLACE(p.name, 'ي', 'ی'), 'ى', 'ی'), 'ك', 'ک') LIKE :q1 ESCAPE '!' OR p.sku LIKE :q2 ESCAPE '!')";
                $params['q1'] = BizCommon::like(BizCommon::persian($q));
                $params['q2'] = BizCommon::like(toLatinDigits($q));
            }
        }
        if ($category !== '') {
            $where[] = 'c.name = :c';
            $params['c'] = $category;
        }
        return [implode(' AND ', $where), $params];
    }

    /**
     * همه‌ی ردیف‌ها بی‌صفحه‌بندی — برای چاپ. سقف دارد و می‌گوید بریده شده.
     * @return array{rows:array, capped:bool}
     */
    public static function all(int $userId, string $filter = '', string $category = '', int $cap = 3000): array
    {
        [$sqlWhere, $params] = self::where($userId, '', $filter, $category);
        $st = Database::getConnection()->prepare(
            self::SELECT_SQL . " WHERE {$sqlWhere} ORDER BY c.name IS NULL, c.name, p.name, p.id LIMIT :lim"
        );
        foreach ($params as $k => $v) { $st->bindValue($k, $v); }
        $st->bindValue('lim', $cap + 1, PDO::PARAM_INT);
        $st->execute();
        $rows = $st->fetchAll();
        return ['rows' => array_slice($rows, 0, $cap), 'capped' => count($rows) > $cap];
    }

    /** @return array{rows:array, total:int, page:int, pages:int} */
    public static function list(int $userId, string $q = '', string $filter = '', string $category = '', int $page = 1): array
    {
        [$sqlWhere, $params] = self::where($userId, $q, $filter, $category);
        $pdo = Database::getConnection();

        $st = $pdo->prepare("SELECT COUNT(*) FROM biz_products p LEFT JOIN biz_categories c ON c.id = p.category_id WHERE {$sqlWhere}");
        $st->execute($params);
        $total = (int)$st->fetchColumn();
        [$page, $pages, $offset] = BizCommon::window($total, $page, self::PAGE_SIZE);

        $st = $pdo->prepare(
            self::SELECT_SQL . " WHERE {$sqlWhere}
             ORDER BY p.name, p.id LIMIT :lim OFFSET :off"
        );
        foreach ($params as $k => $v) { $st->bindValue($k, $v); }
        $st->bindValue('lim', self::PAGE_SIZE, PDO::PARAM_INT);
        $st->bindValue('off', $offset, PDO::PARAM_INT);
        $st->execute();

        return ['rows' => $st->fetchAll(), 'total' => $total, 'page' => $page, 'pages' => $pages];
    }

    public static function get(int $userId, int $id): ?array
    {
        $st = Database::getConnection()->prepare(self::SELECT_SQL . ' WHERE p.id = :id AND p.user_id = :u LIMIT 1');
        $st->execute(['id' => $id, 'u' => $userId]);
        $r = $st->fetch();
        return $r ?: null;
    }

    /** نامِ دسته‌ها — برای `<datalist>` و صافی؛ از همان `BizCategories`. */
    public static function categories(int $userId): array
    {
        return array_column(BizCategories::list($userId), 'name');
    }

    /** مقدار با قاعده‌ی واحد سازگار است؟ */
    public static function qtyFits(string $unit, float $qty): bool
    {
        return (self::UNITS[$unit] ?? false) || abs($qty - round($qty)) < 0.0005;
    }

    /**
     * ساخت یا ویرایش. کالای تازه می‌تواند موجودیِ اول دوره هم بگیرد
     * (`opening_qty`/`opening_cost`) — در همان تراکنش.
     *
     * @return array{ok:bool, message:string, id?:int}
     */
    public static function save(int $userId, array $in, int $id = 0): array
    {
        $name     = BizCommon::line(BizCommon::persian((string)($in['name'] ?? '')));
        $sku      = BizCommon::line(toLatinDigits((string)($in['sku'] ?? '')));
        $category = BizCommon::line((string)($in['category'] ?? ''));
        $note     = trim((string)($in['note'] ?? ''));
        $unit     = (string)($in['unit'] ?? 'عدد');
        $buy      = sanitizeAmount($in['buy_price'] ?? '');
        $sell     = sanitizeAmount($in['sell_price'] ?? '');
        $minStock = sanitizeQty($in['min_stock'] ?? '');
        // نوع: فرمِ تازه `type` می‌فرستد؛ مسیرهای قدیمی (ورود از فایل) فقط
        // `is_service` — آن‌وقت «گوشی بودنِ» کالای موجود دست نمی‌خورد.
        $type     = isset($in['type']) ? (string)$in['type'] : null;
        if ($type !== null && !isset(self::TYPES[$type])) { return ['ok' => false, 'message' => 'نوعِ کالا معتبر نیست.']; }
        $track    = $type !== null ? ($type === 'service' ? 0 : 1) : (empty($in['is_service']) ? 1 : 0);
        $serial   = $type !== null ? ($type === 'phone' ? 1 : 0) : null;

        if ($name === '') { return ['ok' => false, 'message' => 'نامِ کالا الزامی است.']; }
        foreach (['name' => $name, 'sku' => $sku, 'category' => $category, 'note' => $note] as $k => $v) {
            if (mb_strlen($v) > self::LIMITS[$k]) {
                return ['ok' => false, 'message' => 'متنِ یکی از فیلدها بیش از ' . self::LIMITS[$k] . ' نویسه است.'];
            }
        }
        if (!isset(self::UNITS[$unit])) { return ['ok' => false, 'message' => 'واحدِ کالا معتبر نیست.']; }
        if (!self::qtyFits($unit, $minStock)) {
            return ['ok' => false, 'message' => 'حداقلِ موجودی برای واحدِ «' . $unit . '» باید عددِ صحیح باشد.'];
        }
        // ⛔ هر گوشی یک واحدِ کامل با IMEI خودش است؛ «۲٫۵ گوشی» معنا ندارد.
        if ($serial === 1 && self::UNITS[$unit]) {
            return ['ok' => false, 'message' => 'گوشی با واحدِ «' . $unit . '» ثبت نمی‌شود؛ «دستگاه» یا «عدد» را انتخاب کنید.'];
        }

        $openQty  = sanitizeQty($in['opening_qty'] ?? '');
        $openCost = trim((string)($in['opening_cost'] ?? '')) === '' ? $buy : sanitizeAmount($in['opening_cost']);
        if ($id === 0 && $openQty > 0) {
            if (!$track) { return ['ok' => false, 'message' => 'خدمت موجودی ندارد؛ موجودیِ اول دوره را خالی بگذارید.']; }
            if (!self::qtyFits($unit, $openQty)) {
                return ['ok' => false, 'message' => 'موجودی برای واحدِ «' . $unit . '» باید عددِ صحیح باشد.'];
            }
        }

        $pdo = Database::getConnection();
        if ($id > 0) {
            $cur = self::get($userId, $id);
            if (!$cur) { return ['ok' => false, 'message' => 'کالا پیدا نشد.']; }
            // ⛔ کالایی که موجودی دارد خدمت نمی‌شود — موجودی‌اش بی‌صدا از
            //    ارزشِ انبار بیرون می‌افتاد.
            if (!$track && (float)$cur['stock_qty'] != 0.0) {
                return ['ok' => false, 'message' => 'این کالا موجودی دارد؛ اول موجودی را صفر کنید، بعد آن را خدمت کنید.'];
            }
            if (!self::qtyFits($unit, (float)$cur['stock_qty'])) {
                return ['ok' => false, 'message' => 'موجودیِ فعلی اعشاری است و با واحدِ «' . $unit . '» جور نیست.'];
            }
            if ($serial === null) { $serial = (int)($cur['has_serial'] ?? 0) === 1 && $track === 1 ? 1 : 0; }
        }
        $serial = $track === 1 ? (int)$serial : 0;

        // ⛔ دسته با نام می‌آید (فرم، ورود از فایل) و به شناسه تبدیل می‌شود؛
        //    نامِ تازه دسته‌ی تازه می‌سازد. ستونِ متنیِ قدیمی همیشه NULL.
        $catId = $category === '' ? null : BizCategories::resolve($userId, $category);
        $row = [
            'u' => $userId, 'n' => $name, 's' => $sku === '' ? null : $sku, 'c' => $catId,
            'un' => $unit, 'b' => $buy, 'sp' => $sell, 'm' => $minStock, 't' => $track, 'hs' => $serial,
            'no' => $note === '' ? null : $note,
        ];
        try {
            $pdo->beginTransaction();
            if ($id > 0) {
                $pdo->prepare(
                    'UPDATE biz_products SET name = :n, sku = :s, category_id = :c, category = NULL, unit = :un, buy_price = :b,
                            sell_price = :sp, min_stock = :m, track_stock = :t, has_serial = :hs, note = :no
                     WHERE id = :id AND user_id = :u'
                )->execute($row + ['id' => $id]);
            } else {
                $pdo->prepare(
                    'INSERT INTO biz_products (user_id, name, sku, category_id, unit, buy_price, sell_price, min_stock, track_stock, has_serial, note)
                     VALUES (:u, :n, :s, :c, :un, :b, :sp, :m, :t, :hs, :no)'
                )->execute($row);
                $id = (int)$pdo->lastInsertId();
                if ($openQty > 0) {
                    $res = BizStock::setOpening($userId, $id, $openQty, $openCost, false);
                    if (!$res['ok']) { $pdo->rollBack(); return $res; }
                }
            }
            $pdo->commit();
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) { $pdo->rollBack(); }
            if ((string)$e->getCode() === '23000') {
                return ['ok' => false, 'message' => 'کالای دیگری با همین کد ثبت شده است.'];
            }
            throw $e;
        }
        return ['ok' => true, 'message' => 'کالا ذخیره شد.', 'id' => $id];
    }

    public static function setActive(int $userId, int $id, bool $active): bool
    {
        $st = Database::getConnection()->prepare('UPDATE biz_products SET is_active = :a WHERE id = :id AND user_id = :u');
        $st->execute(['a' => $active ? 1 : 0, 'id' => $id, 'u' => $userId]);
        return $st->rowCount() > 0;
    }

    /**
     * حذف — فقط وقتی هیچ حرکتی به سندی (فاکتور) بند نیست. امروز فاکتوری
     * نیست، پس هر کالایی حذف‌شدنی است؛ از مرحله‌ی ۳، کالای فروخته‌شده
     * فقط غیرفعال می‌شود وگرنه فاکتورِ قدیمی به هیچ‌جا اشاره می‌کرد.
     *
     * @return array{ok:bool, message:string}
     */
    public static function delete(int $userId, int $id): array
    {
        $pdo = Database::getConnection();
        // ⛔ ردیفِ فاکتور — حتی پیش‌نویس — به کالا اشاره می‌کند (کلیدِ خارجیِ
        //    RESTRICT)؛ حذفش فاکتور را بی‌کالا می‌کرد.
        $st  = $pdo->prepare('SELECT (SELECT COUNT(*) FROM biz_stock_moves WHERE product_id = :id AND user_id = :u AND ref_type IS NOT NULL)
                                   + (SELECT COUNT(*) FROM biz_invoice_lines WHERE product_id = :id2 AND user_id = :u2)');
        $st->execute(['id' => $id, 'u' => $userId, 'id2' => $id, 'u2' => $userId]);
        if ((int)$st->fetchColumn() > 0) {
            return ['ok' => false, 'message' => 'این کالا در فاکتور آمده و حذف نمی‌شود؛ غیرفعالش کنید.'];
        }
        $st = $pdo->prepare('DELETE FROM biz_products WHERE id = :id AND user_id = :u');
        $st->execute(['id' => $id, 'u' => $userId]);
        return $st->rowCount() > 0
            ? ['ok' => true, 'message' => 'کالا حذف شد.']
            : ['ok' => false, 'message' => 'کالا پیدا نشد.'];
    }

    /* ------------------------------------------------------------
       پاک‌سازیِ دسته‌جمعیِ کالاهای استفاده‌نشده
       ------------------------------------------------------------ */

    /**
     * ⛔ تنها تعریفِ «استفاده‌نشده» — همان دو شرطِ `delete()`: هیچ ردیفِ
     *    سندی (حتی پیش‌نویس؛ کلیدِ خارجیِ RESTRICT) و هیچ حرکتِ انبارِ
     *    سندداری. موجودیِ اول دوره و انبارگردانی (`ref_type IS NULL`) مانع
     *    نیستند و با CASCADE همراهِ کالا می‌روند.
     *    ⚠ شرطِ دوم امروز افزونه است (حرکتِ سندداری فقط از ردیفِ سند می‌آید و
     *    سندِ صادرشده حذف نمی‌شود) و جهشِ برداشتنش زنده می‌ماند — همان شرطِ
     *    `delete()` است و نگه داشته شد تا دو تعریف از هم دور نیفتند.
     *    ⚠ روی ستون‌های `p` و با پارامترهای `:uu1`/`:uu2` — پارامترِ جدا،
     *    نه یک `:u` تکراری (EMULATE_PREPARES = false).
     */
    private const UNUSED_SQL = 'NOT EXISTS (SELECT 1 FROM biz_invoice_lines ul WHERE ul.product_id = p.id AND ul.user_id = :uu1)
        AND NOT EXISTS (SELECT 1 FROM biz_stock_moves um WHERE um.product_id = p.id AND um.user_id = :uu2 AND um.ref_type IS NOT NULL)';

    /** دامنه‌ی پاک‌سازی — `empty`: فقط بی‌موجودی (پیش‌فرض)، `all`: با موجودیِ اول دوره هم. */
    public const CLEANUP_SCOPES = [
        'empty' => 'فقط کالاهای بی‌موجودی',
        'all'   => 'همه، حتی با موجودیِ اول دوره',
    ];

    /** سقفِ ردیف‌های فهرستِ انتخابی — زیرِ `max_input_vars`ِ پیش‌فرضِ PHP (۱۰۰۰). */
    public const CLEANUP_LIST_MAX = 300;

    /** @return array{0:string, 1:array} */
    private static function unusedWhere(int $userId, string $scope, string $category): array
    {
        $where  = ['p.user_id = :u', self::UNUSED_SQL];
        $params = ['u' => $userId, 'uu1' => $userId, 'uu2' => $userId];
        if (($scope === '' ? 'empty' : $scope) !== 'all') {
            // ⚠ خدمت موجودی ندارد، پس همیشه «بی‌موجودی» است
            $where[] = '(p.track_stock = 0 OR p.stock_qty = 0)';
        }
        if ($category !== '') {
            $where[] = 'c.name = :c';
            $params['c'] = $category;
        }
        return [implode(' AND ', $where), $params];
    }

    /**
     * کالاهای استفاده‌نشده — فهرستِ صفحه‌ی پاک‌سازی، با سقف و شمارِ کل
     * (فهرستِ بریده صریح گفته می‌شود).
     * @return array{rows:array, total:int, capped:bool}
     */
    public static function unused(int $userId, string $scope = 'empty', string $category = ''): array
    {
        [$w, $params] = self::unusedWhere($userId, $scope, $category);
        $pdo = Database::getConnection();
        $st = $pdo->prepare(self::SELECT_SQL . " WHERE {$w} ORDER BY p.name, p.id LIMIT :lim");
        foreach ($params as $k => $v) { $st->bindValue($k, $v); }
        $st->bindValue('lim', self::CLEANUP_LIST_MAX + 1, PDO::PARAM_INT);
        $st->execute();
        $rows   = $st->fetchAll();
        $capped = count($rows) > self::CLEANUP_LIST_MAX;
        $total  = count($rows);
        if ($capped) {
            $st = $pdo->prepare("SELECT COUNT(*) FROM biz_products p LEFT JOIN biz_categories c ON c.id = p.category_id WHERE {$w}");
            $st->execute($params);
            $total = (int)$st->fetchColumn();
        }
        return ['rows' => array_slice($rows, 0, self::CLEANUP_LIST_MAX), 'total' => $total, 'capped' => $capped];
    }

    /**
     * حذفِ دسته‌جمعی. `$ids` = null یعنی «همه‌ی استفاده‌نشده‌های همین دامنه»
     * (فهرستِ بلندتر از سقف هم پاک می‌شود)؛ آرایه یعنی فقط همین شناسه‌ها.
     *
     * ⛔ شرطِ «استفاده‌نشده» روی خودِ `DELETE` است، نه فقط در فهرستی که
     *    کاربر دیده: بینِ دیدنِ صفحه و زدنِ دکمه ممکن است کالایی در
     *    فاکتور آمده باشد، و شناسه‌ها از فرم می‌آیند. کالای کاربرِ دیگر و
     *    کالای استفاده‌شده «رد شده» شمرده می‌شوند، نه حذف.
     * @param int[]|null $ids
     * @return array{ok:bool, message:string, deleted:int, skipped:int}
     */
    public static function deleteUnused(int $userId, ?array $ids, string $scope = 'empty', string $category = ''): array
    {
        if ($ids !== null) {
            $ids = array_values(array_unique(array_filter(array_map('intval', $ids), fn($v) => $v > 0)));
            if (!$ids) {
                return ['ok' => false, 'message' => 'هیچ کالایی انتخاب نشده است.', 'deleted' => 0, 'skipped' => 0];
            }
            // انتخابِ دستی به دامنه بند نیست: کاربر خودش کالای با موجودی را تیک زده
            [$w, $params] = self::unusedWhere($userId, 'all', '');
            $in = [];
            foreach ($ids as $n => $id) { $in[] = ':id' . $n; $params['id' . $n] = $id; }
            $w .= ' AND p.id IN (' . implode(',', $in) . ')';
        } else {
            [$w, $params] = self::unusedWhere($userId, $scope, $category);
        }
        $pdo = Database::getConnection();
        $pdo->beginTransaction();
        try {
            // ⚠ MariaDB در DELETE با زیرکوئری روی جدولِ دیگر مشکلی ندارد؛
            //   JOIN به دسته فقط برای صافیِ نام.
            $st = $pdo->prepare("DELETE p FROM biz_products p LEFT JOIN biz_categories c ON c.id = p.category_id WHERE {$w}");
            $st->execute($params);
            $deleted = $st->rowCount();
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            Log::error('biz.products_cleanup', $e);
            return ['ok' => false, 'message' => 'پاک‌سازی انجام نشد.', 'deleted' => 0, 'skipped' => 0];
        }
        $skipped = $ids !== null ? count($ids) - $deleted : 0;
        if ($deleted === 0) {
            return ['ok' => false, 'message' => 'کالای استفاده‌نشده‌ای برای حذف نبود.', 'deleted' => 0, 'skipped' => $skipped];
        }
        $msg = toPersianDigits((string)$deleted) . ' کالای استفاده‌نشده حذف شد.';
        if ($skipped > 0) {
            $msg .= ' ' . toPersianDigits((string)$skipped) . ' کالا در این فاصله در سندی آمده بود یا پیدا نشد و ماند.';
        }
        return ['ok' => true, 'message' => $msg, 'deleted' => $deleted, 'skipped' => $skipped];
    }

    /**
     * خلاصه‌ی داشبورد — یک کوئری. ارزشِ انبار = موجودی × میانگینِ بها،
     * فقط کالاهای فعال و انبارشدنی.
     *
     * @return array{count:int, value:int, low:int, out:int}
     */
    public static function summary(int $userId): array
    {
        $st = Database::getConnection()->prepare(
            'SELECT COUNT(*) AS cnt,
                    COALESCE(SUM(CASE WHEN track_stock = 1 AND stock_qty > 0 THEN stock_qty * avg_cost ELSE 0 END), 0) AS val,
                    COALESCE(SUM(track_stock = 1 AND min_stock > 0 AND stock_qty <= min_stock), 0) AS low,
                    COALESCE(SUM(track_stock = 1 AND stock_qty <= 0), 0) AS outq
             FROM biz_products WHERE user_id = :u AND is_active = 1'
        );
        $st->execute(['u' => $userId]);
        $r = $st->fetch() ?: [];
        return [
            'count' => (int)($r['cnt'] ?? 0),
            'value' => (int)round((float)($r['val'] ?? 0)),
            'low'   => (int)($r['low'] ?? 0),
            'out'   => (int)($r['outq'] ?? 0),
        ];
    }

    /** کالاهای کم‌موجودی — برای کارتِ داشبورد. */
    public static function lowStock(int $userId, int $limit = 6): array
    {
        $st = Database::getConnection()->prepare(
            'SELECT id, name, unit, stock_qty, min_stock FROM biz_products
             WHERE user_id = :u AND is_active = 1 AND track_stock = 1
               AND (stock_qty <= 0 OR (min_stock > 0 AND stock_qty <= min_stock))
             ORDER BY (stock_qty <= 0) DESC, stock_qty / GREATEST(min_stock, 1), name
             LIMIT :lim'
        );
        $st->bindValue('u', $userId, PDO::PARAM_INT);
        $st->bindValue('lim', $limit, PDO::PARAM_INT);
        $st->execute();
        return $st->fetchAll();
    }

    /** وضعیتِ موجودیِ یک ردیف: `ok` / `low` / `out` / `service`. */
    public static function stockState(array $p): string
    {
        if ((int)$p['track_stock'] !== 1) { return 'service'; }
        $q = (float)$p['stock_qty'];
        if ($q <= 0) { return 'out'; }
        if ((float)$p['min_stock'] > 0 && $q <= (float)$p['min_stock']) { return 'low'; }
        return 'ok';
    }
}

/* =================================================================
   دسته‌بندیِ کالا (migration_biz_docs)
   ================================================================= */
final class BizCategories
{
    public const NAME_MAX = 80;

    /** کشِ همین درخواست — فرمِ کالا، صافی و datalist یک فهرست را می‌خوانند. */
    private static array $cache = [];

    /** @return array<int,array{id:int,name:string,sort_order:int,products:int}> */
    public static function list(int $userId): array
    {
        if (isset(self::$cache[$userId])) { return self::$cache[$userId]; }
        $st = Database::getConnection()->prepare(
            'SELECT c.id, c.name, c.sort_order,
                    (SELECT COUNT(*) FROM biz_products p WHERE p.category_id = c.id AND p.user_id = c.user_id AND p.is_active = 1) AS products
             FROM biz_categories c WHERE c.user_id = :u ORDER BY c.sort_order, c.name'
        );
        $st->execute(['u' => $userId]);
        return self::$cache[$userId] = $st->fetchAll();
    }

    public static function forget(int $userId): void
    {
        unset(self::$cache[$userId]);
    }

    /**
     * نام → شناسه؛ نامِ تازه ساخته می‌شود. ⚠ مقایسه با پارامترِ bind‌شده
     * است، نه ستون‌به‌ستون، پس collation ستون را می‌گیرد («ی/ي» و فاصله‌ی
     * اضافه را `line()` یکی می‌کند).
     */
    public static function resolve(int $userId, string $name): ?int
    {
        $name = mb_substr(BizCommon::line(str_replace(['ي', 'ك'], ['ی', 'ک'], $name)), 0, self::NAME_MAX);
        if ($name === '') { return null; }
        $pdo = Database::getConnection();
        $st = $pdo->prepare('SELECT id FROM biz_categories WHERE user_id = :u AND name = :n LIMIT 1');
        $st->execute(['u' => $userId, 'n' => $name]);
        $id = $st->fetchColumn();
        if ($id !== false) { return (int)$id; }
        $pdo->prepare('INSERT INTO biz_categories (user_id, name) VALUES (:u, :n)')->execute(['u' => $userId, 'n' => $name]);
        self::forget($userId);
        return (int)$pdo->lastInsertId();
    }

    /** ساخت یا تغییرِ نام. @return array{ok:bool, message:string, id?:int} */
    public static function save(int $userId, string $name, int $id = 0): array
    {
        $name = BizCommon::line(str_replace(['ي', 'ك'], ['ی', 'ک'], $name));
        if ($name === '') { return ['ok' => false, 'message' => 'نامِ دسته الزامی است.']; }
        if (mb_strlen($name) > self::NAME_MAX) { return ['ok' => false, 'message' => 'نامِ دسته بیش از ' . self::NAME_MAX . ' نویسه است.']; }
        $pdo = Database::getConnection();
        try {
            if ($id > 0) {
                $st = $pdo->prepare('UPDATE biz_categories SET name = :n WHERE id = :id AND user_id = :u');
                $st->execute(['n' => $name, 'id' => $id, 'u' => $userId]);
                $chk = $pdo->prepare('SELECT COUNT(*) FROM biz_categories WHERE id = :id AND user_id = :u');
                $chk->execute(['id' => $id, 'u' => $userId]);
                if ((int)$chk->fetchColumn() === 0) { return ['ok' => false, 'message' => 'دسته پیدا نشد.']; }
            } else {
                $pdo->prepare('INSERT INTO biz_categories (user_id, name) VALUES (:u, :n)')->execute(['u' => $userId, 'n' => $name]);
                $id = (int)$pdo->lastInsertId();
            }
        } catch (PDOException $e) {
            if ((string)$e->getCode() === '23000') { return ['ok' => false, 'message' => 'دسته‌ای با همین نام هست.']; }
            throw $e;
        }
        self::forget($userId);
        return ['ok' => true, 'message' => 'دسته ذخیره شد.', 'id' => $id];
    }

    /**
     * حذف — کالاهایش **بی‌دسته** می‌شوند (کلیدِ خارجیِ `SET NULL`)، نه حذف.
     * پیام تعدادشان را می‌گوید تا کسی فکر نکند کالاها رفتند.
     * @return array{ok:bool, message:string}
     */
    public static function delete(int $userId, int $id): array
    {
        $pdo = Database::getConnection();
        $st = $pdo->prepare('SELECT COUNT(*) FROM biz_products WHERE category_id = :id AND user_id = :u');
        $st->execute(['id' => $id, 'u' => $userId]);
        $n = (int)$st->fetchColumn();
        $st = $pdo->prepare('DELETE FROM biz_categories WHERE id = :id AND user_id = :u');
        $st->execute(['id' => $id, 'u' => $userId]);
        self::forget($userId);
        if ($st->rowCount() === 0) { return ['ok' => false, 'message' => 'دسته پیدا نشد.']; }
        return ['ok' => true, 'message' => $n > 0
            ? 'دسته حذف شد و ' . toPersianDigits((string)$n) . ' کالایش بی‌دسته شد.'
            : 'دسته حذف شد.'];
    }
}

/* =================================================================
   حرکتِ انبار
   ================================================================= */
final class BizStock
{
    /** برچسبِ نوعِ حرکت؛ نوع‌های فاکتور با مرحله‌ی ۳ اضافه می‌شوند. */
    public const KINDS = [
        'opening'         => 'موجودی اول دوره',
        'adjust'          => 'انبارگردانی',
        'sale'            => 'فروش',
        'purchase'        => 'خرید',
        'sale_return'     => 'برگشت از فروش',
        'purchase_return' => 'برگشت از خرید',
    ];

    /** نوعِ ارجاعِ حرکت‌های سند — تنها مقدار؛ `BizInvoices` هم همین را می‌پرسد. */
    public const REF_INVOICE = 'invoice';

    /** خطای گردِ اعشار — «منفی» یعنی کمتر از این. */
    private const EPS = 0.0005;

    /**
     * ⛔ تنها نویسنده‌ی `stock_qty` و `avg_cost`.
     *
     * حرکت‌ها به ترتیبِ زمان پیموده می‌شوند (موجودیِ اول دوره همیشه اول) و
     * **میانگینِ موزونِ متحرک** ساخته می‌شود: هر ورود با بهای خودش (یا اگر
     * بها ندارد، با میانگینِ جاری) در میانگین می‌نشیند و هر خروج میانگین را
     * عوض نمی‌کند. کمینه‌ی موجودیِ پیموده‌شده هم برگردانده می‌شود تا
     * فراخواننده «منفی شدن در هر لحظه» را رد کند، نه فقط در پایان.
     *
     * @return array{qty:float, avg:float, min:float}
     */
    public static function recalc(int $userId, int $productId): array
    {
        $pdo = Database::getConnection();
        $st  = $pdo->prepare(
            "SELECT qty, unit_cost FROM biz_stock_moves
             WHERE product_id = :p AND user_id = :u
             ORDER BY (kind = 'opening') DESC, move_date, id"
        );
        $st->execute(['p' => $productId, 'u' => $userId]);

        $q = 0.0; $avg = 0.0; $min = 0.0;
        foreach ($st->fetchAll() as $m) {
            $qty = (float)$m['qty'];
            if ($qty > 0) {
                $cost = $m['unit_cost'] !== null ? (float)$m['unit_cost'] : $avg;
                $base = max($q, 0.0);
                $avg  = ($base * $avg + $qty * $cost) / ($base + $qty);
            }
            $q   = round($q + $qty, 3);
            $min = min($min, $q);
        }
        $avg = round($avg, 2);

        $pdo->prepare('UPDATE biz_products SET stock_qty = :q, avg_cost = :a WHERE id = :p AND user_id = :u')
            ->execute(['q' => $q, 'a' => $avg, 'p' => $productId, 'u' => $userId]);

        return ['qty' => $q, 'avg' => $avg, 'min' => $min];
    }

    /**
     * ⛔ هر تغییرِ انبار از همین می‌گذرد: قفلِ ردیفِ کالا، تغییر، محاسبه‌ی
     *    دوباره، و اگر موجودی در **هیچ** لحظه‌ای منفی شد، rollback. یک
     *    تستِ خوب جلوی `commit` را نمی‌گیرد؛ خودِ کد باید بگیرد.
     *
     * @param callable(PDO, array):?string $change پیامِ خطا یا null
     * @return array{ok:bool, message:string}
     */
    private static function write(int $userId, int $productId, callable $change, bool $ownTx = true): array
    {
        $pdo = Database::getConnection();
        if ($ownTx) { $pdo->beginTransaction(); }
        try {
            $st = $pdo->prepare('SELECT * FROM biz_products WHERE id = :p AND user_id = :u FOR UPDATE');
            $st->execute(['p' => $productId, 'u' => $userId]);
            $product = $st->fetch();
            if (!$product) {
                if ($ownTx) { $pdo->rollBack(); }
                return ['ok' => false, 'message' => 'کالا پیدا نشد.'];
            }
            if ((int)$product['track_stock'] !== 1) {
                if ($ownTx) { $pdo->rollBack(); }
                return ['ok' => false, 'message' => 'این قلم خدمت است و موجودی ندارد.'];
            }
            $err = $change($pdo, $product);
            if ($err !== null) {
                if ($ownTx) { $pdo->rollBack(); }
                return ['ok' => false, 'message' => $err];
            }
            $r = self::recalc($userId, $productId);
            if ($r['min'] < -self::EPS) {
                if ($ownTx) { $pdo->rollBack(); }
                return ['ok' => false, 'message' => 'با این تغییر موجودیِ کالا منفی می‌شود؛ انجام نشد.'];
            }
            if ($ownTx) { $pdo->commit(); }
            return ['ok' => true, 'message' => 'موجودی به‌روز شد.'];
        } catch (Throwable $e) {
            if ($ownTx && $pdo->inTransaction()) { $pdo->rollBack(); }
            throw $e;
        }
    }

    /**
     * موجودیِ اول دوره — یک ردیف برای هر کالا؛ دوباره زدنش همان ردیف را
     * عوض می‌کند و مقدارِ صفر حذفش می‌کند.
     */
    public static function setOpening(int $userId, int $productId, float $qty, int $unitCost, bool $ownTx = true): array
    {
        if ($qty < 0)      { return ['ok' => false, 'message' => 'موجودیِ اول دوره نمی‌تواند منفی باشد.']; }
        if ($unitCost < 0) { return ['ok' => false, 'message' => 'بهای واحد نمی‌تواند منفی باشد.']; }
        return self::write($userId, $productId, function (PDO $pdo, array $p) use ($userId, $productId, $qty, $unitCost): ?string {
            if (!BizProducts::qtyFits((string)$p['unit'], $qty)) {
                return 'موجودی برای واحدِ «' . $p['unit'] . '» باید عددِ صحیح باشد.';
            }
            $pdo->prepare("DELETE FROM biz_stock_moves WHERE product_id = :p AND user_id = :u AND kind = 'opening'")
                ->execute(['p' => $productId, 'u' => $userId]);
            if ($qty > 0) {
                $pdo->prepare(
                    "INSERT INTO biz_stock_moves (user_id, product_id, move_date, kind, qty, unit_cost)
                     VALUES (:u, :p, :d, 'opening', :q, :c)"
                )->execute(['u' => $userId, 'p' => $productId, 'd' => substr((string)$p['created_at'], 0, 10),
                            'q' => $qty, 'c' => $unitCost]);
            }
            return null;
        }, $ownTx);
    }

    /**
     * انبارگردانی — «موجودیِ واقعیِ شمارش‌شده این است»؛ تفاوت یک حرکت
     * می‌شود. ورودِ اضافه به میانگینِ جاری ارزش‌گذاری می‌شود.
     */
    public static function adjustTo(int $userId, int $productId, float $actual, string $note = ''): array
    {
        if ($actual < 0) { return ['ok' => false, 'message' => 'موجودیِ واقعی نمی‌تواند منفی باشد.']; }
        $note = mb_substr(BizCommon::line($note), 0, 200);
        $changed = false;
        $res = self::write($userId, $productId, function (PDO $pdo, array $p) use ($userId, $productId, $actual, $note, &$changed): ?string {
            if (!BizProducts::qtyFits((string)$p['unit'], $actual)) {
                return 'موجودی برای واحدِ «' . $p['unit'] . '» باید عددِ صحیح باشد.';
            }
            $delta = round($actual - (float)$p['stock_qty'], 3);
            if (abs($delta) < 0.0005) { return null; }
            $changed = true;
            $pdo->prepare(
                "INSERT INTO biz_stock_moves (user_id, product_id, move_date, kind, qty, unit_cost, note)
                 VALUES (:u, :p, CURDATE(), 'adjust', :q, NULL, :n)"
            )->execute(['u' => $userId, 'p' => $productId, 'q' => $delta, 'n' => $note === '' ? null : $note]);
            return null;
        });
        if ($res['ok'] && !$changed) { $res['message'] = 'موجودی همان عدد بود؛ چیزی عوض نشد.'; }
        return $res;
    }

    /**
     * ⛔ حرکت‌های یک سند (فاکتور یا برگشت) — تنها راهِ سند به انبار.
     *
     * جایگزینیِ کامل است: برای هر کالا حرکت‌های قبلیِ همین سند پاک و
     * تازه‌ها نوشته می‌شوند، پس «صدور»، «برگشت به پیش‌نویس» و «باطل» یک مسیر
     * دارند (باطل = فهرستِ خالی). هر کالا از `write()` می‌گذرد: قفلِ ردیف،
     * محاسبه‌ی دوباره، و سدِ «موجودی در هیچ لحظه‌ای منفی نشود».
     *
     * ⛔ فقط داخلِ تراکنشِ فراخواننده (`ownTx = false`) — اگر کالای سوم
     *    شکست بخورد، دو کالای اول هم باید برگردند؛ فراخواننده rollback می‌کند.
     * ⚠ کالاها به ترتیبِ شناسه قفل می‌شوند تا دو سندِ هم‌زمان به بن‌بست نخورند.
     *
     * @param array<int, list<array{qty:float, unit_cost:?int, date:string}>> $moves
     * @param int[] $clear کالاهایی که پیش از این حرکتی از همین سند داشتند
     * @return array{ok:bool, message:string, avg?:array<int,float>}
     */
    public static function postDoc(int $userId, string $kind, int $refId, array $moves, array $clear = []): array
    {
        if (!isset(self::KINDS[$kind]) || in_array($kind, ['opening', 'adjust'], true)) {
            return ['ok' => false, 'message' => 'نوعِ سند معتبر نیست.'];
        }
        $ids = array_values(array_unique(array_map('intval', array_merge(array_keys($moves), $clear))));
        sort($ids);
        $avg = [];
        foreach ($ids as $pid) {
            $list = $moves[$pid] ?? [];
            $res = self::write($userId, $pid, function (PDO $pdo, array $p) use ($userId, $pid, $kind, $refId, $list, &$avg): ?string {
                $avg[$pid] = (float)$p['avg_cost'];
                $pdo->prepare('DELETE FROM biz_stock_moves WHERE user_id = :u AND product_id = :p AND ref_type = :rt AND ref_id = :r')
                    ->execute(['u' => $userId, 'p' => $pid, 'rt' => self::REF_INVOICE, 'r' => $refId]);
                $ins = $pdo->prepare(
                    'INSERT INTO biz_stock_moves (user_id, product_id, move_date, kind, qty, unit_cost, ref_type, ref_id)
                     VALUES (:u, :p, :d, :k, :q, :c, :rt, :r)'
                );
                foreach ($list as $m) {
                    if (!BizProducts::qtyFits((string)$p['unit'], abs((float)$m['qty']))) {
                        return 'مقدارِ «' . $p['name'] . '» برای واحدِ «' . $p['unit'] . '» باید عددِ صحیح باشد.';
                    }
                    $ins->execute(['u' => $userId, 'p' => $pid, 'd' => $m['date'], 'k' => $kind, 'q' => round((float)$m['qty'], 3),
                                   'c' => $m['unit_cost'] ?? (int)round((float)$p['avg_cost']), 'rt' => self::REF_INVOICE, 'r' => $refId]);
                }
                return null;
            }, false);
            if (!$res['ok']) {
                $name = self::nameOf($userId, $pid);
                return ['ok' => false, 'message' => ($name !== '' ? '«' . $name . '»: ' : '') . $res['message']];
            }
        }
        return ['ok' => true, 'message' => 'موجودی به‌روز شد.', 'avg' => $avg];
    }

    private static function nameOf(int $userId, int $pid): string
    {
        $st = Database::getConnection()->prepare('SELECT name FROM biz_products WHERE id = :p AND user_id = :u');
        $st->execute(['p' => $pid, 'u' => $userId]);
        return (string)($st->fetchColumn() ?: '');
    }

    /** حذفِ یک حرکتِ انبارگردانی — اگر موجودی در هیچ لحظه‌ای منفی نشود. */
    public static function deleteMove(int $userId, int $moveId): array
    {
        $st = Database::getConnection()->prepare(
            'SELECT product_id, kind FROM biz_stock_moves WHERE id = :id AND user_id = :u LIMIT 1'
        );
        $st->execute(['id' => $moveId, 'u' => $userId]);
        $m = $st->fetch();
        if (!$m) { return ['ok' => false, 'message' => 'حرکت پیدا نشد.']; }
        if ($m['kind'] !== 'adjust') {
            return ['ok' => false, 'message' => 'فقط انبارگردانی از اینجا حذف می‌شود.'];
        }
        return self::write($userId, (int)$m['product_id'], function (PDO $pdo) use ($userId, $moveId): ?string {
            $pdo->prepare('DELETE FROM biz_stock_moves WHERE id = :id AND user_id = :u')
                ->execute(['id' => $moveId, 'u' => $userId]);
            return null;
        });
    }

    /**
     * کاردکسِ کالا — همه‌ی حرکت‌ها به ترتیبِ زمان با موجودیِ جاری؛ همان
     * ترتیبِ `recalc()` (اول دوره همیشه اول)، پس موجودیِ پایانی دقیقاً همان
     * `stock_qty` است.
     * @return array{rows:array, capped:bool}
     */
    public static function ledger(int $userId, int $productId, int $cap = 2000): array
    {
        $st = Database::getConnection()->prepare(
            "SELECT * FROM biz_stock_moves WHERE product_id = :p AND user_id = :u
             ORDER BY (kind = 'opening') DESC, move_date, id LIMIT :lim"
        );
        $st->bindValue('p', $productId, PDO::PARAM_INT);
        $st->bindValue('u', $userId, PDO::PARAM_INT);
        $st->bindValue('lim', $cap + 1, PDO::PARAM_INT);
        $st->execute();
        $rows = $st->fetchAll();
        $capped = count($rows) > $cap;
        $run = 0.0;
        foreach ($rows as &$m) { $run = round($run + (float)$m['qty'], 3); $m['balance'] = $run; }
        unset($m);
        return ['rows' => array_slice($rows, 0, $cap), 'capped' => $capped];
    }

    public static function moves(int $userId, int $productId, int $limit = 50): array
    {
        $st = Database::getConnection()->prepare(
            "SELECT * FROM biz_stock_moves WHERE product_id = :p AND user_id = :u
             ORDER BY (kind = 'opening'), move_date DESC, id DESC LIMIT :lim"
        );
        $st->bindValue('p', $productId, PDO::PARAM_INT);
        $st->bindValue('u', $userId, PDO::PARAM_INT);
        $st->bindValue('lim', $limit, PDO::PARAM_INT);
        $st->execute();
        return $st->fetchAll();
    }
}

/* =================================================================
   طرف‌حساب (مشتری و تأمین‌کننده)
   ================================================================= */
final class BizParties
{
    public const KINDS = [
        'customer' => 'مشتری',
        'supplier' => 'تأمین‌کننده',
        'both'     => 'مشتری و تأمین‌کننده',
    ];

    public const FILTERS = [
        ''         => 'همه',
        'customer' => 'مشتری‌ها',
        'supplier' => 'تأمین‌کننده‌ها',
        'debtor'   => 'بدهکاران',
        'creditor' => 'طلبکاران',
        'inactive' => 'غیرفعال',
    ];

    public const LIMITS = ['name' => 150, 'phone' => 40, 'address' => 300, 'note' => 300];
    public const PAGE_SIZE = 25;

    /** کدهای رسمیِ خریدار — قاعده‌شان `BizCommon::CODES`. */
    public const CODE_KEYS = ['national_id', 'economic_code', 'postal_code'];

    /** ستون‌های کد آمده‌اند؟ (نصبِ migration‌نخورده نباید بشکند) */
    public static function hasCodes(): bool
    {
        return function_exists('tableHasColumn') && tableHasColumn('biz_parties', 'economic_code');
    }

    /**
     * ⛔ تنها تعریفِ «مانده‌ی یک طرف‌حساب» — فهرست، صافیِ بدهکار/طلبکار،
     *    داشبورد، صورت‌حساب و چاپ همه از همین.
     *    مثبت = او به فروشگاه بدهکار است؛ منفی = فروشگاه به او بدهکار است.
     *
     *    مانده‌ی اول دوره
     *    + فروش − برگشت از فروش − خرید + برگشت از خرید   (فقط صادرشده)
     *    − دریافت از او + پرداخت به او                   (فقط باطل‌نشده)
     *
     * ⚠ تخصیصِ دریافت به فاکتور (`biz_allocations`) اینجا نیست و نباید باشد:
     *   تخصیص فقط می‌گوید کدام فاکتور تسویه شده، نه اینکه چه کسی چقدر بدهکار
     *   است. پولی که هنوز به فاکتوری نخورده همچنان از مانده کم می‌شود.
     */
    public const BALANCE_SQL = "(p.opening_balance
        + COALESCE((SELECT SUM(CASE bi.kind WHEN 'sale' THEN bi.total WHEN 'purchase_return' THEN bi.total ELSE -bi.total END)
                    FROM biz_invoices bi WHERE bi.party_id = p.id AND bi.user_id = p.user_id AND bi.status = 'issued'), 0)
        + COALESCE((SELECT SUM(CASE bp.kind WHEN 'receipt' THEN -bp.amount WHEN 'payment' THEN bp.amount ELSE 0 END)
                    FROM biz_payments bp WHERE bp.party_id = p.id AND bp.user_id = p.user_id AND bp.status = 'ok'), 0))";

    /** ⛔ تنها جای صافی‌های فهرستِ طرف‌حساب (صفحه و چاپ). @return array{0:string, 1:array} */
    private static function where(int $userId, string $q, string $filter): array
    {
        $bal    = self::BALANCE_SQL;
        $where  = ['p.user_id = :u'];
        $params = ['u' => $userId];
        switch (isset(self::FILTERS[$filter]) ? $filter : '') {
            case 'customer': $where[] = "p.is_active = 1 AND p.kind IN ('customer','both')"; break;
            case 'supplier': $where[] = "p.is_active = 1 AND p.kind IN ('supplier','both')"; break;
            case 'debtor':   $where[] = "p.is_active = 1 AND {$bal} > 0"; break;
            case 'creditor': $where[] = "p.is_active = 1 AND {$bal} < 0"; break;
            case 'inactive': $where[] = 'p.is_active = 0'; break;
            default:         $where[] = 'p.is_active = 1';
        }
        if ($q !== '') {
            $where[] = "(p.name LIKE :q1 ESCAPE '!' OR p.phone LIKE :q2 ESCAPE '!')";
            $params['q1'] = BizCommon::like($q);
            $params['q2'] = BizCommon::like(toLatinDigits($q));
        }
        return [implode(' AND ', $where), $params];
    }

    /**
     * همه‌ی ردیف‌ها برای چاپ و منوها. @return array{rows:array, capped:bool}
     *
     * ⛔ `$withBalance = false` برای منویی که مانده را نشان نمی‌دهد (فروشِ
     *    سریع، مشتریِ پیش‌فرض، صافیِ گزارش): `BALANCE_SQL` دو زیرکوئریِ
     *    همبسته به‌ازای **هر** طرف‌حساب است و یک منوی ساده را به کُندترین
     *    کوئریِ صفحه تبدیل می‌کرد. کلیدِ `balance` در آن حالت `null` است، نه
     *    صفر — صفر یک مانده‌ی واقعی است.
     */
    public static function all(int $userId, string $filter = '', int $cap = 3000, bool $withBalance = true): array
    {
        $bal = $withBalance ? self::BALANCE_SQL : 'NULL';
        [$sqlWhere, $params] = self::where($userId, '', $filter);
        $st = Database::getConnection()->prepare(
            "SELECT p.*, {$bal} AS balance FROM biz_parties p WHERE {$sqlWhere} ORDER BY p.name, p.id LIMIT :lim"
        );
        foreach ($params as $k => $v) { $st->bindValue($k, $v); }
        $st->bindValue('lim', $cap + 1, PDO::PARAM_INT);
        $st->execute();
        $rows = $st->fetchAll();
        return ['rows' => array_slice($rows, 0, $cap), 'capped' => count($rows) > $cap];
    }

    /**
     * صورت‌حسابِ یک طرف‌حساب: ردیف‌های بدهکار/بستانکار با مانده‌ی جاری.
     * ⛔ مانده‌ی پایانی باید با `BALANCE_SQL` یکی باشد (تست همین را
     *    می‌سنجد): همان سه منبع، با همان شرطِ «صادرشده/باطل‌نشده».
     * @return array{party:array, lines:array, debit:int, credit:int, balance:int}|null
     */
    public static function statement(int $userId, int $id): ?array
    {
        $party = self::get($userId, $id);
        if (!$party) { return null; }
        $lines = [];
        $ob = (int)$party['opening_balance'];
        if ($ob !== 0) {
            $lines[] = ['date' => substr((string)$party['created_at'], 0, 10), 'desc' => 'مانده‌ی اول دوره',
                        'debit' => max($ob, 0), 'credit' => max(-$ob, 0), 'sort' => '0'];
        }
        $pdo = Database::getConnection();
        $docNames = ['sale' => 'فاکتور فروش', 'purchase' => 'فاکتور خرید', 'sale_return' => 'برگشت از فروش', 'purchase_return' => 'برگشت از خرید'];
        $st = $pdo->prepare("SELECT id, kind, number, inv_date, total, created_at FROM biz_invoices
                             WHERE party_id = :p AND user_id = :u AND status = 'issued' ORDER BY inv_date, id");
        $st->execute(['p' => $id, 'u' => $userId]);
        foreach ($st->fetchAll() as $r) {
            $plus = in_array($r['kind'], ['sale', 'purchase_return'], true);
            $lines[] = ['date' => (string)$r['inv_date'], 'desc' => ($docNames[$r['kind']] ?? '') . ' شماره‌ی ' . toPersianDigits((string)$r['number']),
                        'debit' => $plus ? (int)$r['total'] : 0, 'credit' => $plus ? 0 : (int)$r['total'],
                        'sort' => $r['inv_date'] . ' 1 ' . $r['created_at'], 'invoice_id' => (int)$r['id']];
        }
        $st = $pdo->prepare("SELECT id, kind, number, pay_date, amount, method, created_at, invoice_id, origin_invoice FROM biz_payments
                             WHERE party_id = :p AND user_id = :u AND status = 'ok' AND kind IN ('receipt','payment') ORDER BY pay_date, id");
        $st->execute(['p' => $id, 'u' => $userId]);
        foreach ($st->fetchAll() as $r) {
            $rec = $r['kind'] === 'receipt';
            $lines[] = ['date' => (string)$r['pay_date'], 'desc' => ($rec ? 'دریافت' : 'پرداخت') . ' شماره‌ی ' . toPersianDigits((string)$r['number']),
                        'debit' => $rec ? 0 : (int)$r['amount'], 'credit' => $rec ? (int)$r['amount'] : 0,
                        'sort' => $r['pay_date'] . ' 2 ' . $r['created_at'], 'payment_id' => (int)$r['id'],
                        'origin_of' => (int)$r['origin_invoice'] === 1 ? (int)$r['invoice_id'] : 0];
        }
        usort($lines, fn($a, $b) => strcmp($a['sort'], $b['sort']));
        $run = 0; $dr = 0; $cr = 0;
        foreach ($lines as &$l) {
            $run += $l['debit'] - $l['credit'];
            $dr += $l['debit']; $cr += $l['credit'];
            $l['balance'] = $run;
        }
        unset($l);
        return ['party' => $party, 'lines' => $lines, 'debit' => $dr, 'credit' => $cr, 'balance' => $run];
    }

    /**
     * مانده‌ی طرف‌حساب **پیش و پس** از یک فاکتورِ صادرشده — برای «مانده‌ی
     * قبلی / مانده‌ی کل» روی فاکتور و برگه‌ی چاپ.
     *
     * ⛔ از همان `statement()` ساخته می‌شود، نه یک حسابِ دوم: «قبلی» مانده‌ی
     *    جاریِ صورت‌حساب درست پیش از ردیفِ همین فاکتور است، پس با صفحه‌ی
     *    طرف‌حساب و برگه‌ی صورت‌حساب همیشه یک عدد می‌گوید.
     * ⛔ «پس از» = قبلی + این فاکتور ± فقط دریافت/پرداختِ **همراهِ همین
     *    فاکتور** (`origin_invoice = 1`) — نه `paid`ِ فاکتور: تخصیصِ FIFO
     *    پیش‌پرداختی را هم دارد که از قبل در «قبلی» کم شده، و آن‌وقت همان
     *    پول دو بار کم می‌شد.
     * @return array{prev:int, doc:int, paid:int, after:int}|null  مثبت = او بدهکار است
     */
    public static function balanceAround(int $userId, int $partyId, int $invoiceId): ?array
    {
        $s = self::statement($userId, $partyId);
        if ($s === null) { return null; }
        $prev = 0; $doc = null; $paid = 0;
        foreach ($s['lines'] as $l) {
            if (($l['origin_of'] ?? 0) === $invoiceId) { $paid += $l['debit'] - $l['credit']; }
            if ($doc !== null) { continue; }
            if (($l['invoice_id'] ?? 0) === $invoiceId) { $doc = $l['debit'] - $l['credit']; continue; }
            $prev = $l['balance'];
        }
        if ($doc === null) { return null; }                      // پیش‌نویس یا باطل — در صورت‌حساب نیست
        return ['prev' => $prev, 'doc' => $doc, 'paid' => $paid, 'after' => $prev + $doc + $paid];
    }

    /** برچسبِ جهتِ یک مانده (مثبت = او بدهکار است). */
    public static function sideLabel(int $bal): string
    {
        return $bal > 0 ? 'بدهکار' : ($bal < 0 ? 'بستانکار' : 'تسویه');
    }

    public static function list(int $userId, string $q = '', string $filter = '', int $page = 1): array
    {
        $bal = self::BALANCE_SQL;
        [$sqlWhere, $params] = self::where($userId, $q, $filter);
        $pdo = Database::getConnection();

        $st = $pdo->prepare("SELECT COUNT(*) FROM biz_parties p WHERE {$sqlWhere}");
        $st->execute($params);
        $total = (int)$st->fetchColumn();
        [$page, $pages, $offset] = BizCommon::window($total, $page, self::PAGE_SIZE);

        $st = $pdo->prepare(
            "SELECT p.*, {$bal} AS balance FROM biz_parties p WHERE {$sqlWhere}
             ORDER BY p.name, p.id LIMIT :lim OFFSET :off"
        );
        foreach ($params as $k => $v) { $st->bindValue($k, $v); }
        $st->bindValue('lim', self::PAGE_SIZE, PDO::PARAM_INT);
        $st->bindValue('off', $offset, PDO::PARAM_INT);
        $st->execute();
        return ['rows' => $st->fetchAll(), 'total' => $total, 'page' => $page, 'pages' => $pages];
    }

    public static function get(int $userId, int $id): ?array
    {
        $bal = self::BALANCE_SQL;
        $st  = Database::getConnection()->prepare(
            "SELECT p.*, {$bal} AS balance FROM biz_parties p WHERE p.id = :id AND p.user_id = :u LIMIT 1"
        );
        $st->execute(['id' => $id, 'u' => $userId]);
        $r = $st->fetch();
        return $r ?: null;
    }

    /**
     * `opening_side`: `they` (او بدهکار است) یا `we` (فروشگاه بدهکار است).
     * مبلغ همیشه مثبت تایپ می‌شود و جهت جدا انتخاب می‌شود — علامتِ منفی
     * روی کیبوردِ موبایل گم می‌شود و `sanitizeAmount()` هم آن را می‌اندازد.
     *
     * @return array{ok:bool, message:string, id?:int}
     */
    public static function save(int $userId, array $in, int $id = 0): array
    {
        $name    = BizCommon::line((string)($in['name'] ?? ''));
        $kind    = (string)($in['kind'] ?? 'customer');
        $phone   = BizCommon::line(toLatinDigits((string)($in['phone'] ?? '')));
        $address = BizCommon::line((string)($in['address'] ?? ''));
        $note    = trim((string)($in['note'] ?? ''));
        $amount  = sanitizeAmount($in['opening_amount'] ?? '');
        $side    = (string)($in['opening_side'] ?? 'they');

        if ($name === '') { return ['ok' => false, 'message' => 'نامِ طرف‌حساب الزامی است.']; }
        if (!isset(self::KINDS[$kind])) { return ['ok' => false, 'message' => 'نوعِ طرف‌حساب معتبر نیست.']; }
        if (!in_array($side, ['they', 'we'], true)) { return ['ok' => false, 'message' => 'جهتِ مانده معتبر نیست.']; }
        if ($phone !== '' && !preg_match('/^[0-9+\-\s()]{3,40}$/', $phone)) {
            return ['ok' => false, 'message' => 'شماره‌ی تلفن معتبر نیست.'];
        }
        foreach (['name' => $name, 'phone' => $phone, 'address' => $address, 'note' => $note] as $k => $v) {
            if (mb_strlen($v) > self::LIMITS[$k]) {
                return ['ok' => false, 'message' => 'متنِ یکی از فیلدها بیش از ' . self::LIMITS[$k] . ' نویسه است.'];
            }
        }
        $opening = $side === 'we' ? -$amount : $amount;
        // کدهای رسمیِ خریدار (migration_biz_business_info) — فقط رقم
        $codes = [];
        foreach (self::CODE_KEYS as $ck) {
            $c = BizCommon::code($ck, (string)($in[$ck] ?? ''));
            if (!$c['ok']) { return ['ok' => false, 'message' => $c['message']]; }
            $codes[$ck] = $c['value'];
        }

        $pdo = Database::getConnection();
        $row = ['u' => $userId, 'n' => $name, 'k' => $kind, 'ph' => $phone === '' ? null : $phone,
                'a' => $address === '' ? null : $address, 'no' => $note === '' ? null : $note, 'ob' => $opening];
        if ($id > 0) {
            if (!self::get($userId, $id)) { return ['ok' => false, 'message' => 'طرف‌حساب پیدا نشد.']; }
            $pdo->prepare(
                'UPDATE biz_parties SET name = :n, kind = :k, phone = :ph, address = :a, note = :no, opening_balance = :ob
                 WHERE id = :id AND user_id = :u'
            )->execute($row + ['id' => $id]);
        } else {
            $pdo->prepare(
                'INSERT INTO biz_parties (user_id, name, kind, phone, address, note, opening_balance)
                 VALUES (:u, :n, :k, :ph, :a, :no, :ob)'
            )->execute($row);
            $id = (int)$pdo->lastInsertId();
        }
        // ⚠ فقط اگر فرم کدها را فرستاده باشد: مسیرِ ورود از فایل آن‌ها را ندارد
        //   و نباید کدِ ثبت‌شده را پاک کند.
        if (self::hasCodes() && array_intersect_key($in, array_flip(self::CODE_KEYS))) {
            $pdo->prepare('UPDATE biz_parties SET national_id = :ni, economic_code = :ec, postal_code = :pc WHERE id = :id AND user_id = :u')
                ->execute(['ni' => $codes['national_id'], 'ec' => $codes['economic_code'], 'pc' => $codes['postal_code'], 'id' => $id, 'u' => $userId]);
        }
        return ['ok' => true, 'message' => 'طرف‌حساب ذخیره شد.', 'id' => $id];
    }

    public static function setActive(int $userId, int $id, bool $active): bool
    {
        $st = Database::getConnection()->prepare('UPDATE biz_parties SET is_active = :a WHERE id = :id AND user_id = :u');
        $st->execute(['a' => $active ? 1 : 0, 'id' => $id, 'u' => $userId]);
        return $st->rowCount() > 0;
    }

    /** حذف — طرف‌حسابی که فاکتور یا دریافت/پرداخت دارد فقط غیرفعال می‌شود. */
    public static function delete(int $userId, int $id): array
    {
        $chk = Database::getConnection()->prepare(
            'SELECT (SELECT COUNT(*) FROM biz_invoices WHERE party_id = :id AND user_id = :u)
                  + (SELECT COUNT(*) FROM biz_payments WHERE party_id = :id2 AND user_id = :u2)'
        );
        $chk->execute(['id' => $id, 'u' => $userId, 'id2' => $id, 'u2' => $userId]);
        if ((int)$chk->fetchColumn() > 0) {
            return ['ok' => false, 'message' => 'این طرف‌حساب سند دارد و حذف نمی‌شود؛ غیرفعالش کنید.'];
        }
        $st = Database::getConnection()->prepare('DELETE FROM biz_parties WHERE id = :id AND user_id = :u');
        $st->execute(['id' => $id, 'u' => $userId]);
        return $st->rowCount() > 0
            ? ['ok' => true, 'message' => 'طرف‌حساب حذف شد.']
            : ['ok' => false, 'message' => 'طرف‌حساب پیدا نشد.'];
    }

    /** @return array{count:int, receivable:int, payable:int, debtors:int, creditors:int} */
    public static function summary(int $userId): array
    {
        // ⛔ مانده **یک بار** برای هر طرف‌حساب حساب می‌شود و جمع در PHP است.
        //    نسخه‌ی قبلی `BALANCE_SQL` را چهار بار در یک `SELECT` می‌نوشت
        //    (طلب، بدهی، شمارِ بدهکار، شمارِ بستانکار) و MariaDB هر چهار را
        //    — هر کدام دو زیرکوئریِ همبسته — جدا اجرا می‌کرد: روی ۸۰ طرف‌حساب
        //    و ۱۵۰۰ فاکتور **۲۳ میلی‌ثانیه** و ۱۴٬۲۱۴ ردیف، روی داشبورد و
        //    فهرستِ طرف‌حساب‌ها هر دو. اندازه‌گیری شد (صفحه‌ی داشبورد را کُند
        //    کرده بود)؛ حالا ~۴ ms.
        $st = Database::getConnection()->prepare(
            'SELECT ' . self::BALANCE_SQL . ' AS b FROM biz_parties p WHERE p.user_id = :u AND p.is_active = 1'
        );
        $st->execute(['u' => $userId]);
        $out = ['count' => 0, 'receivable' => 0, 'payable' => 0, 'debtors' => 0, 'creditors' => 0];
        foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $b) {
            $b = (int)$b;
            $out['count']++;
            if ($b > 0) { $out['receivable'] += $b; $out['debtors']++; }
            elseif ($b < 0) { $out['payable'] -= $b; $out['creditors']++; }
        }
        return $out;
    }
}

/* =================================================================
   صندوق و حساب‌های فروشگاه
   ================================================================= */
final class BizCash
{
    public const KINDS = [
        'cash' => 'صندوق نقدی',
        'bank' => 'حساب بانکی',
        'pos'  => 'کارت‌خوان',
        'cheque_in'  => 'چک‌های دریافتیِ در جریان',
        'cheque_out' => 'چک‌های پرداختیِ در جریان',
    ];

    /**
     * ⛔ نوع‌هایی که کاربر خودش می‌سازد. دو نوعِ چک را فقط
     *    `chequeAccount()` می‌سازد: «پولی» که در آن‌هاست هنوز نقد نیست — در
     *    جمعِ نقدِ فروشگاه نمی‌آید و در فهرستِ انتخابِ صندوق هم نیست.
     */
    public const USER_KINDS = ['cash', 'bank', 'pos'];
    public const CHEQUE_KINDS = ['in' => 'cheque_in', 'out' => 'cheque_out'];

    public const NAME_MAX = 80;

    /**
     * صندوقِ نگه‌دارنده‌ی چک — `in` دریافتی، `out` پرداختی. اگر نیست ساخته و
     * اگر غیرفعال شده دوباره فعال می‌شود (دریافتِ چک به صندوقِ غیرفعال رد می‌شد).
     */
    public static function chequeAccount(PDO $pdo, int $userId, string $dir): int
    {
        $kind = self::CHEQUE_KINDS[$dir] ?? 'cheque_in';
        $st = $pdo->prepare('SELECT id, is_active FROM biz_accounts WHERE user_id = :u AND kind = :k ORDER BY id LIMIT 1 FOR UPDATE');
        $st->execute(['u' => $userId, 'k' => $kind]);
        $row = $st->fetch();
        if ($row) {
            if ((int)$row['is_active'] !== 1) {
                $pdo->prepare('UPDATE biz_accounts SET is_active = 1 WHERE id = :id AND user_id = :u')->execute(['id' => (int)$row['id'], 'u' => $userId]);
            }
            return (int)$row['id'];
        }
        $pdo->prepare('INSERT INTO biz_accounts (user_id, name, kind, sort_order) VALUES (:u, :n, :k, 90)')
            ->execute(['u' => $userId, 'n' => self::KINDS[$kind], 'k' => $kind]);
        return (int)$pdo->lastInsertId();
    }

    public static function isCheque(array $a): bool
    {
        return in_array((string)$a['kind'], self::CHEQUE_KINDS, true);
    }

    /**
     * ⛔ تنها تعریفِ «موجودیِ یک صندوق» — داشبورد، فهرستِ صندوق‌ها و گردشِ
     *    حساب همه از همین. موجودیِ اولیه + دریافت و درآمد − پرداخت و هزینه،
     *    و انتقال از این صندوق منفی و به این صندوق مثبت؛ فقط باطل‌نشده‌ها.
     *    ⛔ هیچ‌چیز از `walletBalances()` (دفترِ شخصی) اینجا نیست.
     */
    public const BALANCE_SQL = "(a.opening_balance
        + COALESCE((SELECT SUM(CASE bp.kind WHEN 'receipt' THEN bp.amount WHEN 'income' THEN bp.amount ELSE -bp.amount END)
                    FROM biz_payments bp WHERE bp.account_id = a.id AND bp.user_id = a.user_id AND bp.status = 'ok'), 0)
        + COALESCE((SELECT SUM(bp.amount) FROM biz_payments bp
                    WHERE bp.to_account_id = a.id AND bp.user_id = a.user_id AND bp.kind = 'transfer' AND bp.status = 'ok'), 0))";

    /**
     * فهرستِ صندوق‌ها با موجودی. اگر فروشگاه هنوز هیچ صندوقی ندارد، یک
     * «صندوق» ساخته می‌شود — فروشگاهِ بی‌صندوق جایی برای نشستنِ پول ندارد.
     * ⚠ این هرگز چیزی را که کاربر حذف کرده زنده نمی‌کند: آخرین صندوقِ فعال
     *   غیرفعال‌شدنی نیست (`setActive()`)، پس «صفر ردیف» فقط بارِ اول است.
     */
    public static function list(int $userId, bool $activeOnly = false): array
    {
        $rows = self::fetch($userId, $activeOnly);
        if (!$rows && !$activeOnly) {
            Database::getConnection()->prepare(
                "INSERT INTO biz_accounts (user_id, name, kind) VALUES (:u, 'صندوق', 'cash')"
            )->execute(['u' => $userId]);
            $rows = self::fetch($userId, false);
        }
        return $rows;
    }

    private static function fetch(int $userId, bool $activeOnly): array
    {
        $bal = self::BALANCE_SQL;
        $st  = Database::getConnection()->prepare(
            "SELECT a.*, {$bal} AS balance FROM biz_accounts a WHERE a.user_id = :u"
            . ($activeOnly ? ' AND a.is_active = 1' : '')
            . ' ORDER BY a.is_active DESC, a.sort_order, a.id'
        );
        $st->execute(['u' => $userId]);
        return $st->fetchAll();
    }

    /** @return array{ok:bool, message:string, id?:int} */
    public static function save(int $userId, array $in, int $id = 0): array
    {
        $name = BizCommon::line((string)($in['name'] ?? ''));
        $kind = (string)($in['kind'] ?? 'cash');
        $ob   = sanitizeAmount($in['opening_balance'] ?? '');
        if ($name === '') { return ['ok' => false, 'message' => 'نامِ صندوق یا حساب الزامی است.']; }
        if (mb_strlen($name) > self::NAME_MAX) { return ['ok' => false, 'message' => 'نام بیش از ' . self::NAME_MAX . ' نویسه است.']; }
        if (!in_array($kind, self::USER_KINDS, true)) { return ['ok' => false, 'message' => 'نوعِ حساب معتبر نیست.']; }

        $pdo = Database::getConnection();
        if ($id > 0) {
            // ⛔ صندوقِ چک از فرم ویرایش نمی‌شود — نوعش تنها نشانه‌ی آن است
            $ck = $pdo->prepare('SELECT kind FROM biz_accounts WHERE id = :id AND user_id = :u');
            $ck->execute(['id' => $id, 'u' => $userId]);
            if (in_array((string)$ck->fetchColumn(), self::CHEQUE_KINDS, true)) {
                return ['ok' => false, 'message' => 'صندوقِ چک را خودِ برنامه نگه می‌دارد و ویرایش نمی‌شود.'];
            }
            $st = $pdo->prepare('UPDATE biz_accounts SET name = :n, kind = :k, opening_balance = :o WHERE id = :id AND user_id = :u');
            $st->execute(['n' => $name, 'k' => $kind, 'o' => $ob, 'id' => $id, 'u' => $userId]);
            $chk = $pdo->prepare('SELECT COUNT(*) FROM biz_accounts WHERE id = :id AND user_id = :u');
            $chk->execute(['id' => $id, 'u' => $userId]);
            if ((int)$chk->fetchColumn() === 0) { return ['ok' => false, 'message' => 'حساب پیدا نشد.']; }
        } else {
            $pdo->prepare('INSERT INTO biz_accounts (user_id, name, kind, opening_balance) VALUES (:u, :n, :k, :o)')
                ->execute(['u' => $userId, 'n' => $name, 'k' => $kind, 'o' => $ob]);
            $id = (int)$pdo->lastInsertId();
        }
        return ['ok' => true, 'message' => 'حساب ذخیره شد.', 'id' => $id];
    }

    /** ⛔ آخرین صندوقِ فعال غیرفعال نمی‌شود. */
    public static function setActive(int $userId, int $id, bool $active): array
    {
        $pdo = Database::getConnection();
        $ck  = $pdo->prepare('SELECT kind FROM biz_accounts WHERE id = :id AND user_id = :u');
        $ck->execute(['id' => $id, 'u' => $userId]);
        if (in_array((string)$ck->fetchColumn(), self::CHEQUE_KINDS, true)) {
            return ['ok' => false, 'message' => 'صندوقِ چک را خودِ برنامه نگه می‌دارد.'];
        }
        if (!$active) {
            // ⛔ صندوقِ چک «صندوق» نیست — با شمردنش آخرین صندوقِ نقدی غیرفعال‌شدنی می‌شد
            $st = $pdo->prepare("SELECT COUNT(*) FROM biz_accounts WHERE user_id = :u AND is_active = 1 AND id <> :id AND kind NOT IN ('cheque_in','cheque_out')");
            $st->execute(['u' => $userId, 'id' => $id]);
            if ((int)$st->fetchColumn() === 0) {
                return ['ok' => false, 'message' => 'فروشگاه دست‌کم یک صندوقِ فعال لازم دارد.'];
            }
        }
        $st = $pdo->prepare('UPDATE biz_accounts SET is_active = :a WHERE id = :id AND user_id = :u');
        $st->execute(['a' => $active ? 1 : 0, 'id' => $id, 'u' => $userId]);
        return $st->rowCount() > 0
            ? ['ok' => true, 'message' => $active ? 'حساب فعال شد.' : 'حساب غیرفعال شد.']
            : ['ok' => false, 'message' => 'حساب پیدا نشد.'];
    }

    /** ⛔ جمعِ **نقد** — چکِ در جریان هنوز پول نیست و اینجا نمی‌آید. */
    public static function total(array $rows): int
    {
        $t = 0;
        foreach ($rows as $r) {
            if ((int)$r['is_active'] === 1 && !self::isCheque($r)) { $t += (int)$r['balance']; }
        }
        return $t;
    }
}

/* =================================================================
   نمایش — تکه‌های مشترکِ صفحه‌های store/
   ================================================================= */
final class BizView
{
    /** مقدار با واحد. */
    public static function qty($q, string $unit): string
    {
        return formatQty($q) . ' ' . $unit;
    }

    /**
     * نوارِ صفحه‌بندی — لینک است نه جاوااسکریپت، و بقیه‌ی پارامترها را نگه
     * می‌دارد (همان قاعده‌ی `pagedUrl()`). با یک صفحه رندر نمی‌شود.
     */
    public static function pager(string $page, array $params, int $cur, int $pages, int $total): string
    {
        if ($pages <= 1) { return ''; }
        $url = function (int $p) use ($page, $params): string {
            $params['page'] = $p;
            return Biz::url($page) . '?' . http_build_query(array_filter($params, fn($v) => $v !== '' && $v !== null));
        };
        $out = '<nav class="st-pager" aria-label="صفحه‌بندی">';
        $out .= $cur > 1 ? '<a class="st-pager-btn" href="' . h($url($cur - 1)) . '">قبلی</a>' : '<span class="st-pager-btn is-off">قبلی</span>';
        $out .= '<span class="st-pager-info">صفحه‌ی ' . toPersianDigits((string)$cur) . ' از ' . toPersianDigits((string)$pages)
              . ' · ' . toPersianDigits((string)$total) . ' ردیف</span>';
        $out .= $cur < $pages ? '<a class="st-pager-btn" href="' . h($url($cur + 1)) . '">بعدی</a>' : '<span class="st-pager-btn is-off">بعدی</span>';
        return $out . '</nav>';
    }
}
