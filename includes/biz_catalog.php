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

    /** یک‌خطی، بی‌فاصله‌ی اضافه. */
    public static function line(string $v): string
    {
        return trim((string)preg_replace('/\s+/u', ' ', $v));
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

    /** صافی‌های فهرست — تنها مرجع؛ ناشناخته به «همه» برمی‌گردد. */
    public const FILTERS = [
        ''         => 'همه',
        'low'      => 'کم‌موجودی',
        'out'      => 'ناموجود',
        'inactive' => 'غیرفعال',
    ];

    public const PAGE_SIZE = 25;

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
            $where[] = "(p.name LIKE :q1 ESCAPE '!' OR p.sku LIKE :q2 ESCAPE '!')";
            $params['q1'] = $params['q2'] = BizCommon::like($q);
        }
        if ($category !== '') {
            $where[] = 'p.category = :c';
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
            "SELECT p.* FROM biz_products p WHERE {$sqlWhere} ORDER BY p.category IS NULL, p.category, p.name, p.id LIMIT :lim"
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

        $st = $pdo->prepare("SELECT COUNT(*) FROM biz_products p WHERE {$sqlWhere}");
        $st->execute($params);
        $total = (int)$st->fetchColumn();
        [$page, $pages, $offset] = BizCommon::window($total, $page, self::PAGE_SIZE);

        $st = $pdo->prepare(
            "SELECT p.* FROM biz_products p WHERE {$sqlWhere}
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
        $st = Database::getConnection()->prepare('SELECT * FROM biz_products WHERE id = :id AND user_id = :u LIMIT 1');
        $st->execute(['id' => $id, 'u' => $userId]);
        $r = $st->fetch();
        return $r ?: null;
    }

    /** دسته‌های به‌کاررفته — برای `<datalist>`، نه یک فهرستِ جدا. */
    public static function categories(int $userId): array
    {
        $st = Database::getConnection()->prepare(
            "SELECT DISTINCT category FROM biz_products
             WHERE user_id = :u AND category IS NOT NULL AND category <> '' ORDER BY category"
        );
        $st->execute(['u' => $userId]);
        return $st->fetchAll(PDO::FETCH_COLUMN);
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
        $name     = BizCommon::line((string)($in['name'] ?? ''));
        $sku      = BizCommon::line(toLatinDigits((string)($in['sku'] ?? '')));
        $category = BizCommon::line((string)($in['category'] ?? ''));
        $note     = trim((string)($in['note'] ?? ''));
        $unit     = (string)($in['unit'] ?? 'عدد');
        $buy      = sanitizeAmount($in['buy_price'] ?? '');
        $sell     = sanitizeAmount($in['sell_price'] ?? '');
        $minStock = sanitizeQty($in['min_stock'] ?? '');
        $track    = empty($in['is_service']) ? 1 : 0;

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
        }

        $row = [
            'u' => $userId, 'n' => $name, 's' => $sku === '' ? null : $sku, 'c' => $category === '' ? null : $category,
            'un' => $unit, 'b' => $buy, 'sp' => $sell, 'm' => $minStock, 't' => $track, 'no' => $note === '' ? null : $note,
        ];
        try {
            $pdo->beginTransaction();
            if ($id > 0) {
                $pdo->prepare(
                    'UPDATE biz_products SET name = :n, sku = :s, category = :c, unit = :un, buy_price = :b,
                            sell_price = :sp, min_stock = :m, track_stock = :t, note = :no
                     WHERE id = :id AND user_id = :u'
                )->execute($row + ['id' => $id]);
            } else {
                $pdo->prepare(
                    'INSERT INTO biz_products (user_id, name, sku, category, unit, buy_price, sell_price, min_stock, track_stock, note)
                     VALUES (:u, :n, :s, :c, :un, :b, :sp, :m, :t, :no)'
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
        $st  = $pdo->prepare('SELECT COUNT(*) FROM biz_stock_moves WHERE product_id = :id AND user_id = :u AND ref_type IS NOT NULL');
        $st->execute(['id' => $id, 'u' => $userId]);
        if ((int)$st->fetchColumn() > 0) {
            return ['ok' => false, 'message' => 'این کالا در فاکتور آمده و حذف نمی‌شود؛ غیرفعالش کنید.'];
        }
        $st = $pdo->prepare('DELETE FROM biz_products WHERE id = :id AND user_id = :u');
        $st->execute(['id' => $id, 'u' => $userId]);
        return $st->rowCount() > 0
            ? ['ok' => true, 'message' => 'کالا حذف شد.']
            : ['ok' => false, 'message' => 'کالا پیدا نشد.'];
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
   حرکتِ انبار
   ================================================================= */
final class BizStock
{
    /** برچسبِ نوعِ حرکت؛ نوع‌های فاکتور با مرحله‌ی ۳ اضافه می‌شوند. */
    public const KINDS = [
        'opening' => 'موجودی اول دوره',
        'adjust'  => 'انبارگردانی',
    ];

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

    /**
     * ⛔ تنها تعریفِ «مانده‌ی یک طرف‌حساب». امروز فقط مانده‌ی اول دوره است؛
     *    فاکتور و دریافت/پرداختِ مرحله‌ی ۳ همین‌جا اضافه می‌شوند، پس فهرست،
     *    صافی و داشبورد خودبه‌خود درست می‌مانند.
     *    مثبت = او به فروشگاه بدهکار است؛ منفی = فروشگاه به او بدهکار است.
     */
    public const BALANCE_SQL = 'p.opening_balance';

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

    /** همه‌ی ردیف‌ها برای چاپ. @return array{rows:array, capped:bool} */
    public static function all(int $userId, string $filter = '', int $cap = 3000): array
    {
        $bal = self::BALANCE_SQL;
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
     *    می‌سنجد) — امروز فقط مانده‌ی اول دوره است و فاکتور و
     *    دریافت/پرداختِ مرحله‌ی ۳ همین‌جا ردیف اضافه می‌کنند.
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
                        'debit' => max($ob, 0), 'credit' => max(-$ob, 0)];
        }
        $run = 0; $dr = 0; $cr = 0;
        foreach ($lines as &$l) {
            $run += $l['debit'] - $l['credit'];
            $dr += $l['debit']; $cr += $l['credit'];
            $l['balance'] = $run;
        }
        unset($l);
        return ['party' => $party, 'lines' => $lines, 'debit' => $dr, 'credit' => $cr, 'balance' => $run];
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
        return ['ok' => true, 'message' => 'طرف‌حساب ذخیره شد.', 'id' => $id];
    }

    public static function setActive(int $userId, int $id, bool $active): bool
    {
        $st = Database::getConnection()->prepare('UPDATE biz_parties SET is_active = :a WHERE id = :id AND user_id = :u');
        $st->execute(['a' => $active ? 1 : 0, 'id' => $id, 'u' => $userId]);
        return $st->rowCount() > 0;
    }

    /** حذف — از مرحله‌ی ۳ طرف‌حسابِ دارای فاکتور فقط غیرفعال می‌شود. */
    public static function delete(int $userId, int $id): array
    {
        $st = Database::getConnection()->prepare('DELETE FROM biz_parties WHERE id = :id AND user_id = :u');
        $st->execute(['id' => $id, 'u' => $userId]);
        return $st->rowCount() > 0
            ? ['ok' => true, 'message' => 'طرف‌حساب حذف شد.']
            : ['ok' => false, 'message' => 'طرف‌حساب پیدا نشد.'];
    }

    /** @return array{count:int, receivable:int, payable:int, debtors:int, creditors:int} */
    public static function summary(int $userId): array
    {
        $bal = self::BALANCE_SQL;
        $st  = Database::getConnection()->prepare(
            "SELECT COUNT(*) AS cnt,
                    COALESCE(SUM(GREATEST({$bal}, 0)), 0)  AS rec,
                    COALESCE(SUM(GREATEST(-{$bal}, 0)), 0) AS pay,
                    COALESCE(SUM({$bal} > 0), 0) AS dn,
                    COALESCE(SUM({$bal} < 0), 0) AS cn
             FROM biz_parties p WHERE p.user_id = :u AND p.is_active = 1"
        );
        $st->execute(['u' => $userId]);
        $r = $st->fetch() ?: [];
        return [
            'count'      => (int)($r['cnt'] ?? 0),
            'receivable' => (int)($r['rec'] ?? 0),
            'payable'    => (int)($r['pay'] ?? 0),
            'debtors'    => (int)($r['dn'] ?? 0),
            'creditors'  => (int)($r['cn'] ?? 0),
        ];
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
    ];

    public const NAME_MAX = 80;

    /** ⛔ تنها تعریفِ «موجودیِ یک صندوق» — دریافت و پرداختِ مرحله‌ی ۳ اینجا می‌نشیند. */
    public const BALANCE_SQL = 'a.opening_balance';

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
        if (!isset(self::KINDS[$kind])) { return ['ok' => false, 'message' => 'نوعِ حساب معتبر نیست.']; }

        $pdo = Database::getConnection();
        if ($id > 0) {
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
        if (!$active) {
            $st = $pdo->prepare('SELECT COUNT(*) FROM biz_accounts WHERE user_id = :u AND is_active = 1 AND id <> :id');
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

    public static function total(array $rows): int
    {
        $t = 0;
        foreach ($rows as $r) {
            if ((int)$r['is_active'] === 1) { $t += (int)$r['balance']; }
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
