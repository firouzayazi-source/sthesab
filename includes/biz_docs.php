<?php
/**
 * ⛔ محیطِ فروشگاهی — مرحله‌ی ۳: اسناد.
 *
 * **خواسته‌ی مالکِ نصب:** «مدل برنامه‌ی حسابداری، صدور فاکتور خرید و فروش
 * در دسترس باشه و امکاناتِ پایه‌ی فروشگاهی صفر تا صد: خرید، فروش، مرجوعی…»
 *
 * - `BizInvoices` — چهار سند در یک مدل: فاکتور فروش، فاکتور خرید، برگشت از
 *   فروش، برگشت از خرید. چرخه: پیش‌نویس ← صادرشده ← (باطل). **پیش‌نویس
 *   هیچ اثری ندارد**؛ فقط «صدور» به انبار (`BizStock::postDoc`) و مانده
 *   (`BizParties::BALANCE_SQL`) می‌رسد. سندِ صادرشده حذف نمی‌شود، باطل
 *   می‌شود و همه‌ی اثرش برمی‌گردد — شماره و ردش می‌ماند.
 * - `BizPay` — دریافت، پرداخت، هزینه، درآمدِ متفرقه و انتقال. موجودیِ صندوق
 *   (`BizCash::BALANCE_SQL`) از همین جمع زده می‌شود.
 *   ⛔ `reallocate()` تنها نویسنده‌ی `biz_allocations`، `biz_invoices.paid`
 *      و `biz_payments.allocated` است: تخصیص همیشه از نو و قطعی ساخته
 *      می‌شود (اول فاکتورِ ترجیحیِ هر دریافت، بعد قدیمی‌ترین فاکتورِ باز)،
 *      پس هیچ ترتیبی از ثبت و باطل و ویرایش نمی‌تواند آن را ناهماهنگ کند.
 *
 * ⛔ فقط جدول‌های `biz_*` — هیچ جدولِ دفترِ شخصی خوانده یا نوشته نمی‌شود
 *    (قاعده ۷۰). `user_id` روی هر کوئری هست.
 * ⛔ جمع‌ها همیشه در سرور حساب می‌شوند؛ عددی که از مرورگر می‌آید (جمعِ
 *    زنده‌ی `store.js`) هرگز پذیرفته نمی‌شود.
 */

require_once __DIR__ . '/biz_catalog.php';

final class BizInvoices
{
    /** ⛔ تنها مرجعِ نوع‌های سند. */
    public const KINDS = [
        'sale'            => 'فاکتور فروش',
        'purchase'        => 'فاکتور خرید',
        'sale_return'     => 'برگشت از فروش',
        'purchase_return' => 'برگشت از خرید',
    ];

    /** اثرِ سند بر موجودی: فروش و برگشت از خرید بیرون می‌برند. */
    public const STOCK_SIGN = ['sale' => -1, 'purchase' => 1, 'sale_return' => 1, 'purchase_return' => -1];

    /** نوعِ دریافت/پرداختی که این سند را تسویه می‌کند. */
    public const SETTLED_BY = ['sale' => 'receipt', 'purchase_return' => 'receipt', 'purchase' => 'payment', 'sale_return' => 'payment'];

    /** برگشتِ هر نوع — فقط فروش و خرید برگشت دارند. */
    public const RETURN_OF = ['sale' => 'sale_return', 'purchase' => 'purchase_return'];

    /** ⛔ تنها مرجعِ صافی‌های فهرست؛ ناشناخته به «همه» برمی‌گردد. */
    public const FILTERS = [
        ''        => 'همه',
        'draft'   => 'پیش‌نویس',
        'open'    => 'تسویه‌نشده',
        'paid'    => 'تسویه‌شده',
        'void'    => 'باطل',
    ];

    /** برچسب و رنگِ هر وضعیتِ نمایشی — تنها مرجعِ نشانِ وضعیت. */
    public const STATUS_LABELS = [
        'draft'   => 'پیش‌نویس',
        'open'    => 'صادرشده',
        'partial' => 'پرداختِ جزئی',
        'paid'    => 'تسویه',
        'void'    => 'باطل',
    ];

    public const MAX_LINES = 200;
    public const PAGE_SIZE = 25;
    public const NOTE_MAX  = 300;
    /** «شرحِ کالا»ی هر ردیف (`biz_invoice_lines.note`). */
    public const LINE_NOTE_MAX = 190;
    public const DESC_MAX  = 200;

    /**
     * وضعیتِ نمایشیِ یک سند (برای نشان و صافی).
     * ⛔ «سررسید گذشته» نیست: فاکتور به خواسته‌ی مالکِ نصب سررسید ندارد
     *    («سررسید رو پاک کن نمی‌خوام»). ستونِ `due_date` برای سندهای قدیمی
     *    مانده و صفحه‌ی سند و چاپ فقط اگر پر باشد نشانش می‌دهند؛ گزارشِ
     *    سنِ بدهی با `COALESCE(due_date, inv_date)` از تاریخِ فاکتور می‌شمارد.
     */
    public static function state(array $inv): string
    {
        if ($inv['status'] === 'draft') { return 'draft'; }
        if ($inv['status'] === 'void')  { return 'void'; }
        $total = (int)$inv['total']; $paid = (int)$inv['paid'];
        if ($paid >= $total) { return 'paid'; }
        return $paid > 0 ? 'partial' : 'open';
    }

    public static function remaining(array $inv): int
    {
        return $inv['status'] === 'issued' ? max(0, (int)$inv['total'] - (int)$inv['paid']) : 0;
    }

    /** عنوانِ سند: «فاکتور فروش ۱۲» یا «پیش‌نویسِ فاکتور فروش». */
    public static function title(array $inv): string
    {
        $k = self::KINDS[$inv['kind']] ?? '';
        return $inv['number'] !== null ? $k . ' ' . toPersianDigits((string)$inv['number']) : 'پیش‌نویسِ ' . $k;
    }

    /* ------------------------------------------------------------
       خواندن
       ------------------------------------------------------------ */

    /** @return array|null سند با `lines` و نامِ طرف‌حساب. */
    public static function get(int $userId, int $id): ?array
    {
        $pdo = Database::getConnection();
        // کدهای رسمیِ خریدار (migration_biz_business_info). ⚠ با امتحان، نه با
        //    `tableHasColumn()`: آن یک کوئریِ نقشه‌ی ساختار به هر صفحه‌ی سند اضافه
        //    می‌کرد؛ نصبِ عقب‌مانده خطای «ستون نیست» (42S22) می‌گیرد و بی‌آن‌ها
        //    دوباره می‌خواند.
        $sql = fn(string $codes): string =>
            'SELECT i.*, p.name AS party_name, p.phone AS party_phone, p.address AS party_address' . $codes . ',
                    r.number AS ref_number, r.kind AS ref_kind
             FROM biz_invoices i
             LEFT JOIN biz_parties p ON p.id = i.party_id AND p.user_id = i.user_id
             LEFT JOIN biz_invoices r ON r.id = i.ref_invoice_id AND r.user_id = i.user_id
             WHERE i.id = :id AND i.user_id = :u LIMIT 1';
        try {
            $st = $pdo->prepare($sql(', p.national_id AS party_national_id, p.economic_code AS party_economic_code, p.postal_code AS party_postal_code'));
            $st->execute(['id' => $id, 'u' => $userId]);
        } catch (PDOException $e) {
            if ((string)$e->getCode() !== '42S22') { throw $e; }
            $st = $pdo->prepare($sql(''));
            $st->execute(['id' => $id, 'u' => $userId]);
        }
        $inv = $st->fetch();
        if (!$inv) { return null; }
        $st = $pdo->prepare(
            'SELECT l.*, pr.name AS product_name, pr.sku, pr.track_stock, pr.has_serial, pr.stock_qty, pr.avg_cost, pr.buy_price
             FROM biz_invoice_lines l
             LEFT JOIN biz_products pr ON pr.id = l.product_id AND pr.user_id = l.user_id
             WHERE l.invoice_id = :id AND l.user_id = :u ORDER BY l.line_no, l.id'
        );
        $st->execute(['id' => $id, 'u' => $userId]);
        $inv['lines'] = $st->fetchAll();
        return $inv;
    }

    /**
     * ردیف‌های فروشی که زیرِ بهای خرید فروخته می‌شوند — گزینه‌ی
     * `warn_below_cost`. فیِ خالصِ هر واحد (پس از تخفیفِ ردیف؛ صادرشده: پس
     * از سهمِ تخفیف و حملِ کلِ فاکتور) با بها مقایسه می‌شود: صادرشده همان
     * `unit_cost`ِ ثبت‌شده، پیش‌نویس میانگینِ امروزِ کالا (یا قیمتِ خرید اگر
     * هنوز میانگینی نیست). خدمت و شرحِ آزاد بها ندارند.
     * @return array<int,array{n:int, desc:string, per:int, cost:int}>
     */
    public static function belowCost(array $inv): array
    {
        if ($inv['kind'] !== 'sale') { return []; }
        $out = [];
        foreach ($inv['lines'] as $n => $l) {
            $q = (float)$l['qty'];
            if ($l['product_id'] === null || $q <= 0 || (int)($l['track_stock'] ?? 1) !== 1) { continue; }
            $issued = $inv['status'] !== 'draft' && $l['unit_cost'] !== null;
            $cost = $issued ? (int)$l['unit_cost']
                : ((float)($l['avg_cost'] ?? 0) > 0 ? (int)round((float)$l['avg_cost']) : (int)($l['buy_price'] ?? 0));
            $per  = (int)round((int)($issued ? $l['net_total'] : $l['line_total']) / $q);
            if ($cost > 0 && $per < $cost) {
                $out[] = ['n' => $n + 1, 'desc' => (string)$l['description'], 'per' => $per, 'cost' => $cost];
            }
        }
        return $out;
    }

    /** سودِ ناخالصِ یک فاکتورِ فروشِ صادرشده — همان تعریفِ `BizReports::byInvoice()`. */
    public static function profit(array $inv): ?int
    {
        if ($inv['kind'] !== 'sale' || $inv['status'] !== 'issued') { return null; }
        $net = 0; $cost = 0;
        foreach ($inv['lines'] as $l) {
            $net  += (int)$l['net_total'];
            $cost += (int)round((int)($l['unit_cost'] ?? 0) * (float)$l['qty']);
        }
        return $net - $cost;
    }

    /**
     * ⛔ تنها جای صافی‌های فهرستِ اسناد (صفحه و گزارش). @return array{0:string,1:array}
     * @param string[] $kinds
     */
    private static function where(int $userId, array $kinds, string $q, string $filter, string $from = '', string $to = ''): array
    {
        $kinds = array_values(array_filter($kinds, fn($k) => isset(self::KINDS[$k])));
        if (!$kinds) { $kinds = ['sale']; }
        $where  = ['i.user_id = :u'];
        $params = ['u' => $userId];
        $in = [];
        foreach ($kinds as $n => $k) { $in[] = ':k' . $n; $params['k' . $n] = $k; }
        $where[] = 'i.kind IN (' . implode(',', $in) . ')';
        switch (isset(self::FILTERS[$filter]) ? $filter : '') {
            case 'draft':   $where[] = "i.status = 'draft'"; break;
            case 'void':    $where[] = "i.status = 'void'"; break;
            case 'paid':    $where[] = "i.status = 'issued' AND i.paid >= i.total"; break;
            case 'open':    $where[] = "i.status = 'issued' AND i.paid < i.total"; break;
        }
        if ($q !== '') {
            $qq = toLatinDigits($q);
            $qi = BizSerial::norm($q);
            if (BizSerial::valid($qi)) {
                // ⛔ «این گوشی کِی و به چه کسی فروخته/از چه کسی خریده شد؟» —
                //    IMEI در هر کدام از دو ستونِ ردیف
                $where[] = 'EXISTS (SELECT 1 FROM biz_invoice_lines ql WHERE ql.invoice_id = i.id AND ql.user_id = i.user_id
                                     AND (ql.imei1 = :qi1 OR ql.imei2 = :qi2))';
                $params['qi1'] = $params['qi2'] = $qi;
            } elseif (preg_match('/^\s*#?(\d{1,9})\s*$/', $qq, $m)) {
                $where[] = '(i.number = :qn OR p.name LIKE :qp ESCAPE \'!\')';
                $params['qn'] = (int)$m[1];
                $params['qp'] = BizCommon::like($q);
            } else {
                $where[] = "(p.name LIKE :q1 ESCAPE '!' OR i.note LIKE :q2 ESCAPE '!')";
                $params['q1'] = $params['q2'] = BizCommon::like($q);
            }
        }
        if ($from !== '' && isValidDate($from)) { $where[] = 'i.inv_date >= :df'; $params['df'] = $from; }
        if ($to !== '' && isValidDate($to))     { $where[] = 'i.inv_date <= :dt'; $params['dt'] = $to; }
        return [implode(' AND ', $where), $params];
    }

    /**
     * فهرستِ صفحه‌بندی‌شده با جمعِ نتیجه (ردیفِ جمعِ بالای جدول).
     * @param string[] $kinds
     * @return array{rows:array, total:int, page:int, pages:int, sum:int, due:int}
     */
    public static function list(int $userId, array $kinds, string $q = '', string $filter = '', int $page = 1, string $from = '', string $to = ''): array
    {
        [$w, $params] = self::where($userId, $kinds, $q, $filter, $from, $to);
        $pdo = Database::getConnection();
        $join = 'FROM biz_invoices i LEFT JOIN biz_parties p ON p.id = i.party_id AND p.user_id = i.user_id';
        $st = $pdo->prepare("SELECT COUNT(*) AS n,
                                    COALESCE(SUM(CASE WHEN i.status = 'issued' THEN i.total ELSE 0 END), 0) AS s,
                                    COALESCE(SUM(CASE WHEN i.status = 'issued' THEN GREATEST(i.total - i.paid, 0) ELSE 0 END), 0) AS d
                             {$join} WHERE {$w}");
        $st->execute($params);
        $agg = $st->fetch() ?: ['n' => 0, 's' => 0, 'd' => 0];
        [$page, $pages, $offset] = BizCommon::window((int)$agg['n'], $page, self::PAGE_SIZE);
        $st = $pdo->prepare("SELECT i.*, p.name AS party_name {$join} WHERE {$w}
                             ORDER BY (i.status = 'draft') DESC, i.inv_date DESC, i.id DESC LIMIT :lim OFFSET :off");
        foreach ($params as $k => $v) { $st->bindValue($k, $v); }
        $st->bindValue('lim', self::PAGE_SIZE, PDO::PARAM_INT);
        $st->bindValue('off', $offset, PDO::PARAM_INT);
        $st->execute();
        return ['rows' => $st->fetchAll(), 'total' => (int)$agg['n'], 'page' => $page, 'pages' => $pages,
                'sum' => (int)$agg['s'], 'due' => (int)$agg['d']];
    }

    /** اسنادِ صادرشده‌ی تسویه‌نشده‌ی یک طرف‌حساب — برای فرمِ دریافت/پرداخت. */
    public static function openFor(int $userId, int $partyId, string $payKind): array
    {
        $kinds = array_keys(array_filter(self::SETTLED_BY, fn($pk) => $pk === $payKind));
        $in = []; $params = ['u' => $userId, 'p' => $partyId];
        foreach ($kinds as $n => $k) { $in[] = ':k' . $n; $params['k' . $n] = $k; }
        $st = Database::getConnection()->prepare(
            "SELECT id, kind, number, inv_date, due_date, total, paid FROM biz_invoices
             WHERE user_id = :u AND party_id = :p AND status = 'issued' AND paid < total
               AND kind IN (" . implode(',', $in) . ') ORDER BY inv_date, id'
        );
        $st->execute($params);
        return $st->fetchAll();
    }

    /* ------------------------------------------------------------
       ردیف‌ها و جمع‌ها
       ------------------------------------------------------------ */

    /**
     * ردیف‌های خامِ فرم → ردیف‌های سنجیده. کالا اول از `product_id`ِ همین
     * کاربر، وگرنه با کد/بارکدِ دقیق، وگرنه با نامِ دقیق؛ وگرنه «شرحِ آزاد»
     * (بی‌اثر بر موجودی — صفحه‌ی سند همین را صریح نشان می‌دهد).
     * ردیفِ کاملاً خالی نادیده گرفته می‌شود.
     *
     * `meta` (کلید = اندیسِ ورودی) کالای پیدا‌شده و IMEIِ هر ردیف را حتی
     * برای ردیفِ خطادار می‌گوید — صفحه با آن ردیف را دوباره می‌چیند (IMEIِ
     * تایپ‌شده در خانه‌ی کالا بی‌جاوااسکریپت هم به نامِ گوشی تبدیل می‌شود).
     *
     * @return array{lines:array, errors:array<int,string>, meta:array<int,array>}
     */
    public static function parseLines(int $userId, array $raw): array
    {
        $pdo = Database::getConnection();
        $cols = 'id, name, unit, track_stock, has_serial, is_active';
        $byId = $pdo->prepare("SELECT {$cols} FROM biz_products WHERE id = :id AND user_id = :u");
        $bySku = $pdo->prepare("SELECT {$cols} FROM biz_products WHERE sku = :s AND user_id = :u LIMIT 1");
        $byName = $pdo->prepare("SELECT {$cols} FROM biz_products WHERE name = :n AND user_id = :u ORDER BY is_active DESC, id LIMIT 1");

        $lines = []; $errors = []; $meta = []; $seen = []; $no = 0; $folded = null;
        foreach (array_values($raw) as $i => $r) {
            if (!is_array($r)) { continue; }
            $item  = BizCommon::line((string)($r['item'] ?? ''));
            $pid   = (int)($r['product_id'] ?? 0);
            $qtyIn = trim((string)($r['qty'] ?? ''));
            $prIn  = trim((string)($r['price'] ?? ''));
            $imei1 = BizSerial::norm((string)($r['imei1'] ?? ''));
            $imei2 = BizSerial::norm((string)($r['imei2'] ?? ''));
            $lnote = mb_substr(BizCommon::line((string)($r['note'] ?? '')), 0, self::LINE_NOTE_MAX);
            if ($imei1 === '' && $imei2 !== '') { [$imei1, $imei2] = [$imei2, '']; }
            if ($item === '' && $pid === 0 && $prIn === '' && $imei1 === '') { continue; }
            $no++;
            if ($no > self::MAX_LINES) { $errors[$i] = 'حداکثر ' . self::MAX_LINES . ' ردیف.'; break; }
            $tag = 'ردیف ' . toPersianDigits((string)$no) . ': ';

            $prod = null;
            if ($pid > 0) { $byId->execute(['id' => $pid, 'u' => $userId]); $prod = $byId->fetch() ?: null; }
            // ⛔ شناسه‌ی پنهانِ کهنه برنده نیست: اگر کاربر متنِ ردیف را عوض کرده
            //    (بی‌جاوااسکریپت شناسه پاک نمی‌شود)، کالای قبلی بی‌صدا روی ردیف
            //    می‌ماند. پس شناسه فقط وقتی پذیرفته است که متن هنوز نامِ خودِ آن باشد.
            if ($prod && $item !== '' && $item !== (string)$prod['name']) { $prod = null; }
            if (!$prod && $item !== '') {
                $bySku->execute(['s' => toLatinDigits($item), 'u' => $userId]); $prod = $bySku->fetch() ?: null;
                if (!$prod) { $byName->execute(['n' => BizCommon::persian($item), 'u' => $userId]); $prod = $byName->fetch() ?: null; }
                // ⛔ «از نظرِ آدم یکی» (نیم‌فاصله، ارقامِ فارسی، اعراب…) — ولی فقط
                //    برابریِ **یکتا**: متنی که به دو کالا می‌خورد شرحِ آزاد می‌ماند،
                //    وگرنه موجودیِ کالای اشتباه بی‌صدا کم می‌شد. هرگز «شامل بودن».
                if (!$prod) {
                    if ($folded === null) {
                        $folded = [];
                        $fs = $pdo->prepare('SELECT id, name FROM biz_products WHERE user_id = :u AND is_active = 1 LIMIT 5000');
                        $fs->execute(['u' => $userId]);
                        foreach ($fs->fetchAll() as $fp) { $folded[BizCommon::fold((string)$fp['name'])][] = (int)$fp['id']; }
                    }
                    $hit = $folded[BizCommon::fold($item)] ?? [];
                    if (count($hit) === 1) { $byId->execute(['id' => $hit[0], 'u' => $userId]); $prod = $byId->fetch() ?: null; }
                }
            }
            // ⛔ جست‌وجوی هوشمند: متنِ ردیف خودش یک IMEI است (اسکن یا تایپِ
            //    شماره‌ی روی جعبه) → گوشیِ همان IMEI از اسنادِ صادرشده.
            $typedImei = BizSerial::norm($item);
            if (!$prod && BizSerial::valid($typedImei)) {
                $unit = BizSerial::lookup($userId, $typedImei);
                if ($unit !== null && $unit['product_id'] !== null) {
                    $byId->execute(['id' => $unit['product_id'], 'u' => $userId]); $prod = $byId->fetch() ?: null;
                    if ($prod && $imei1 === '') { $imei1 = $unit['imei1']; $imei2 = (string)($unit['imei2'] ?? ''); }
                }
            }
            if (!$prod && BizSerial::valid($typedImei) && $imei1 === '') {
                $meta[$i] = ['product_id' => null, 'name' => null, 'serial' => false, 'imei1' => '', 'imei2' => ''];
                $errors[$i] = $tag . 'گوشی با IMEI «' . $typedImei . '» پیدا نشد. نامِ گوشی را بنویسید و IMEI را در خانه‌ی خودش، یا با «+» گوشیِ تازه تعریف کنید.';
                continue;
            }
            $serial = $prod && (int)$prod['has_serial'] === 1 && (int)$prod['track_stock'] === 1;
            $meta[$i] = ['product_id' => $prod ? (int)$prod['id'] : null, 'name' => $prod ? (string)$prod['name'] : null,
                         'serial' => $serial, 'imei1' => $imei1, 'imei2' => $imei2];
            $qty   = $qtyIn === '' ? 1.0 : sanitizeQty($qtyIn);
            $price = sanitizeAmount($prIn);
            $disc  = sanitizeAmount($r['disc'] ?? '');
            $desc  = $prod ? (string)$prod['name'] : $item;
            $unit  = $prod ? (string)$prod['unit'] : 'عدد';

            if ($desc === '') { $errors[$i] = 'ردیف ' . toPersianDigits((string)$no) . ': شرح یا کالا خالی است.'; continue; }
            if (mb_strlen($desc) > self::DESC_MAX) { $desc = mb_substr($desc, 0, self::DESC_MAX); }
            if ($qty <= 0) { $errors[$i] = 'ردیف ' . toPersianDigits((string)$no) . ': تعداد باید بیشتر از صفر باشد.'; continue; }
            if ($prod && !BizProducts::qtyFits($unit, $qty)) {
                $errors[$i] = 'ردیف ' . toPersianDigits((string)$no) . ': تعدادِ «' . $desc . '» برای واحدِ «' . $unit . '» باید عددِ صحیح باشد.';
                continue;
            }
            // ⛔ گوشی: هر ردیف دقیقاً یک دستگاه با IMEIِ خودش. IMEI فقط روی
            //    کالای «گوشی» معنا دارد، و یک IMEI در یک سند دو بار نمی‌آید.
            foreach ([$imei1, $imei2] as $v) {
                if ($v !== '' && !BizSerial::valid($v)) { $errors[$i] = $tag . 'IMEI «' . $v . '» معتبر نیست (۱۴ تا ۱۷ رقم).'; continue 2; }
            }
            if ($imei1 !== '' && $imei1 === $imei2) { $errors[$i] = $tag . 'IMEI ۱ و ۲ یکی‌اند.'; continue; }
            if ($serial) {
                if ($imei1 === '') { $errors[$i] = $tag . 'برای گوشیِ «' . $desc . '» IMEI را بنویسید (هر گوشی یک ردیف).'; continue; }
                if (abs($qty - 1.0) > 0.0005) { $errors[$i] = $tag . 'هر گوشی یک ردیف است؛ تعدادِ ردیفِ IMEIدار ۱ است.'; continue; }
            } elseif ($imei1 !== '') {
                $errors[$i] = $tag . ($prod ? '«' . $desc . '» گوشی نیست؛ IMEI فقط روی کالای نوعِ «گوشی» ثبت می‌شود.'
                                            : 'IMEI فقط روی گوشیِ ثبت‌شده می‌نشیند؛ اول کالا را با «+» تعریف کنید.');
                continue;
            }
            foreach ([$imei1, $imei2] as $v) {
                if ($v === '') { continue; }
                if (isset($seen[$v])) { $errors[$i] = $tag . 'IMEI «' . $v . '» در این سند تکراری است.'; continue 2; }
                $seen[$v] = true;
            }
            $gross = (int)round($qty * $price);
            if ($disc > $gross) { $errors[$i] = 'ردیف ' . toPersianDigits((string)$no) . ': تخفیف از مبلغِ ردیف بیشتر است.'; continue; }
            $lines[] = [
                'product_id' => $prod ? (int)$prod['id'] : null, 'track' => $prod ? (int)$prod['track_stock'] === 1 : false,
                'description' => $desc, 'unit' => $unit, 'qty' => round($qty, 3), 'unit_price' => $price,
                'line_discount' => $disc, 'line_total' => $gross - $disc, 'ref_line_id' => null, 'unit_cost' => null,
                'inactive' => $prod && (int)$prod['is_active'] !== 1,
                'imei1' => $imei1 === '' ? null : $imei1, 'imei2' => $imei2 === '' ? null : $imei2,
                'note' => $lnote === '' ? null : $lnote,
            ];
        }
        return ['lines' => $lines, 'errors' => $errors, 'meta' => $meta];
    }

    /**
     * جمع‌ها و سهمِ هر ردیف از تخفیف/حملِ کلِ فاکتور (`net_total`) —
     * متناسب با مبلغِ ردیف؛ باقیمانده‌ی گرد به آخرین ردیفِ ناصفر می‌رود،
     * پس جمعِ `net_total` همیشه دقیقاً `total` است.
     * @return array{lines:array, subtotal:int, total:int}
     */
    public static function totals(array $lines, int $discount, int $extra): array
    {
        $sub = 0;
        foreach ($lines as $l) { $sub += (int)$l['line_total']; }
        $total = $sub - $discount + $extra;
        $adj = $extra - $discount; $used = 0; $last = null;
        foreach ($lines as $k => $l) {
            $share = $sub > 0 ? (int)round($adj * (int)$l['line_total'] / $sub) : 0;
            $lines[$k]['net_total'] = (int)$l['line_total'] + $share;
            $used += $share;
            if ((int)$l['line_total'] > 0) { $last = $k; }
        }
        if ($last !== null && $used !== $adj) { $lines[$last]['net_total'] += $adj - $used; }
        return ['lines' => $lines, 'subtotal' => $sub, 'total' => $total];
    }

    /* ------------------------------------------------------------
       نوشتن
       ------------------------------------------------------------ */

    /**
     * ساخت یا ویرایشِ پیش‌نویس. فقط پیش‌نویس ویرایش‌پذیر است.
     * @return array{ok:bool, message:string, id?:int, errors?:array}
     */
    public static function saveDraft(int $userId, string $kind, array $in, int $id = 0): array
    {
        if (!isset(self::RETURN_OF[$kind])) { return ['ok' => false, 'message' => 'از این صفحه فقط فاکتورِ فروش و خرید ساخته می‌شود.']; }
        $head = self::parseHead($userId, $in);
        if (!$head['ok']) { return $head; }
        $parsed = self::parseLines($userId, (array)($in['lines'] ?? []));
        if ($parsed['errors']) {
            return ['ok' => false, 'message' => implode(' ', array_values($parsed['errors'])), 'errors' => $parsed['errors']];
        }
        if (!$parsed['lines']) { return ['ok' => false, 'message' => 'دست‌کم یک ردیف لازم است.']; }
        $t = self::totals($parsed['lines'], $head['discount'], $head['extra']);
        if ($t['total'] < 0) { return ['ok' => false, 'message' => 'تخفیف از جمعِ فاکتور بیشتر است.']; }

        $pdo = Database::getConnection();
        $pdo->beginTransaction();
        try {
            if ($id > 0) {
                $st = $pdo->prepare('SELECT status, kind FROM biz_invoices WHERE id = :id AND user_id = :u FOR UPDATE');
                $st->execute(['id' => $id, 'u' => $userId]);
                $cur = $st->fetch();
                if (!$cur) { $pdo->rollBack(); return ['ok' => false, 'message' => 'سند پیدا نشد.']; }
                if ($cur['status'] !== 'draft') { $pdo->rollBack(); return ['ok' => false, 'message' => 'فقط پیش‌نویس ویرایش می‌شود؛ اول آن را به پیش‌نویس برگردانید.']; }
                $kind = (string)$cur['kind'];
                $pdo->prepare('UPDATE biz_invoices SET party_id = :p, inv_date = :d, due_date = :dd, subtotal = :s, discount = :di,
                                      extra = :e, total = :t, note = :n WHERE id = :id AND user_id = :u')
                    ->execute(['p' => $head['party_id'], 'd' => $head['date'], 'dd' => $head['due'], 's' => $t['subtotal'],
                               'di' => $head['discount'], 'e' => $head['extra'], 't' => $t['total'], 'n' => $head['note'],
                               'id' => $id, 'u' => $userId]);
            } else {
                $pdo->prepare('INSERT INTO biz_invoices (user_id, kind, status, party_id, inv_date, due_date, subtotal, discount, extra, total, note)
                               VALUES (:u, :k, \'draft\', :p, :d, :dd, :s, :di, :e, :t, :n)')
                    ->execute(['u' => $userId, 'k' => $kind, 'p' => $head['party_id'], 'd' => $head['date'], 'dd' => $head['due'],
                               's' => $t['subtotal'], 'di' => $head['discount'], 'e' => $head['extra'], 't' => $t['total'], 'n' => $head['note']]);
                $id = (int)$pdo->lastInsertId();
            }
            self::writeLines($pdo, $userId, $id, $t['lines']);
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) { $pdo->rollBack(); }
            throw $e;
        }
        return ['ok' => true, 'message' => 'پیش‌نویس ذخیره شد.', 'id' => $id];
    }

    /** @return array{ok:bool, message?:string, party_id?:?int, date?:string, due?:?string, discount?:int, extra?:int, note?:?string} */
    private static function parseHead(int $userId, array $in): array
    {
        $partyId = (int)($in['party_id'] ?? 0);
        if ($partyId > 0) {
            $p = BizParties::get($userId, $partyId);
            if (!$p) { return ['ok' => false, 'message' => 'طرف‌حساب پیدا نشد.']; }
        }
        $date = (string)($in['inv_date'] ?? '');
        if (!isValidDate($date)) { $date = date('Y-m-d'); }
        $due = (string)($in['due_date'] ?? '');
        $due = isValidDate($due) ? $due : null;
        if ($due !== null && $due < $date) { return ['ok' => false, 'message' => 'سررسید نمی‌تواند پیش از تاریخِ فاکتور باشد.']; }
        $note = trim((string)($in['note'] ?? ''));
        if (mb_strlen($note) > self::NOTE_MAX) { return ['ok' => false, 'message' => 'توضیح بیش از ' . self::NOTE_MAX . ' نویسه است.']; }
        return ['ok' => true, 'party_id' => $partyId > 0 ? $partyId : null, 'date' => $date, 'due' => $due,
                'discount' => sanitizeAmount($in['discount'] ?? ''), 'extra' => sanitizeAmount($in['extra'] ?? ''),
                'note' => $note === '' ? null : $note];
    }

    private static function writeLines(PDO $pdo, int $userId, int $id, array $lines): void
    {
        $pdo->prepare('DELETE FROM biz_invoice_lines WHERE invoice_id = :id AND user_id = :u')->execute(['id' => $id, 'u' => $userId]);
        // «شرحِ کالا» (`migration_biz_search`) — روی نصبِ عقب‌مانده بی‌صدا کنار
        // گذاشته می‌شود، نه اینکه صدورِ فاکتور بخوابد.
        $withNote = function_exists('tableHasColumn') && tableHasColumn('biz_invoice_lines', 'note');
        $ins = $pdo->prepare(
            'INSERT INTO biz_invoice_lines (user_id, invoice_id, line_no, product_id, ref_line_id, description, unit, imei1, imei2, '
            . ($withNote ? 'note, ' : '') . 'qty, unit_price, line_discount, line_total, net_total, unit_cost)
             VALUES (:u, :i, :no, :p, :r, :d, :un, :m1, :m2, ' . ($withNote ? ':nt2, ' : '') . ':q, :pr, :di, :lt, :nt, :c)'
        );
        foreach (array_values($lines) as $n => $l) {
            $row = ['u' => $userId, 'i' => $id, 'no' => $n + 1, 'p' => $l['product_id'], 'r' => $l['ref_line_id'],
                    'd' => $l['description'], 'un' => $l['unit'], 'm1' => $l['imei1'] ?? null, 'm2' => $l['imei2'] ?? null,
                    'q' => $l['qty'], 'pr' => $l['unit_price'],
                    'di' => $l['line_discount'], 'lt' => $l['line_total'], 'nt' => $l['net_total'] ?? $l['line_total'],
                    'c' => $l['unit_cost']];
            if ($withNote) { $row['nt2'] = $l['note'] ?? null; }
            $ins->execute($row);
        }
    }

    /**
     * ⛔ صدور — تنها جایی که یک سند اثر پیدا می‌کند: شماره، موجودی، مانده، و
     *    (اگر خواسته شده) دریافت/پرداختِ همزمان — همه در **یک** تراکنش.
     *
     * `$pay` = `['account_id' => …, 'amount' => …, 'method' => …]`؛ مبلغِ صفر
     * یعنی «نسیه». بی‌طرف‌حساب (مشتریِ گذری) فقط با تسویه‌ی کامل صادر
     * می‌شود — وگرنه «مانده» مالِ هیچ‌کس نبود.
     *
     * @return array{ok:bool, message:string}
     */
    public static function issue(int $userId, int $id, array $pay = []): array
    {
        $pdo = Database::getConnection();
        $pdo->beginTransaction();
        try {
            $r = self::issueTx($pdo, $userId, $id, $pay);
            if (!$r['ok']) { $pdo->rollBack(); return $r; }
            $pdo->commit();
            return $r;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) { $pdo->rollBack(); }
            throw $e;
        }
    }

    /** بدنه‌ی صدور، داخلِ تراکنشِ فراخواننده (برگشت هم از همین می‌گذرد). */
    private static function issueTx(PDO $pdo, int $userId, int $id, array $pay): array
    {
        $st = $pdo->prepare('SELECT * FROM biz_invoices WHERE id = :id AND user_id = :u FOR UPDATE');
        $st->execute(['id' => $id, 'u' => $userId]);
        $inv = $st->fetch();
        if (!$inv) { return ['ok' => false, 'message' => 'سند پیدا نشد.']; }
        if ($inv['status'] !== 'draft') { return ['ok' => false, 'message' => 'این سند از قبل صادر شده است.']; }
        $kind = (string)$inv['kind'];

        $st = $pdo->prepare('SELECT l.*, pr.track_stock FROM biz_invoice_lines l
                             LEFT JOIN biz_products pr ON pr.id = l.product_id AND pr.user_id = l.user_id
                             WHERE l.invoice_id = :id AND l.user_id = :u ORDER BY l.line_no, l.id');
        $st->execute(['id' => $id, 'u' => $userId]);
        $lines = $st->fetchAll();
        if (!$lines) { return ['ok' => false, 'message' => 'سندِ بی‌ردیف صادر نمی‌شود.']; }

        // `full` = «کلِ مبلغ» — از جمعِ ذخیره‌شده‌ی خودِ سند، نه عددی از فرم
        $payAmount = !empty($pay['full']) ? (int)$inv['total'] : sanitizeAmount($pay['amount'] ?? '');
        if ($inv['party_id'] === null && $payAmount !== (int)$inv['total']) {
            return ['ok' => false, 'message' => 'بی‌طرف‌حساب (مشتری یا فروشنده‌ی گذری) سند فقط با تسویه‌ی کامل صادر می‌شود؛ طرف‌حساب انتخاب کنید یا کلِ مبلغ را '
                . (self::SETTLED_BY[$kind] === 'receipt' ? 'دریافت' : 'پرداخت') . ' کنید.'];
        }

        // ⛔ شماره فقط هنگامِ صدور؛ پیش‌نویسِ رهاشده شماره را نمی‌سوزاند.
        if ($inv['number'] === null) {
            $n = $pdo->prepare('SELECT COALESCE(MAX(number), 0) + 1 FROM biz_invoices WHERE user_id = :u AND kind = :k FOR UPDATE');
            $n->execute(['u' => $userId, 'k' => $kind]);
            $number = (int)$n->fetchColumn();
        } else {
            $number = (int)$inv['number'];
        }

        // بهای برگشت از ردیفِ اصلی می‌آید: کالا با همان بهایی برمی‌گردد که بیرون رفت.
        $refCost = [];
        if ($inv['ref_invoice_id'] !== null) {
            $rc = $pdo->prepare('SELECT id, unit_cost, net_total, qty FROM biz_invoice_lines WHERE invoice_id = :r AND user_id = :u');
            $rc->execute(['r' => (int)$inv['ref_invoice_id'], 'u' => $userId]);
            foreach ($rc->fetchAll() as $o) {
                $refCost[(int)$o['id']] = $o['unit_cost'] !== null ? (int)$o['unit_cost']
                    : ((float)$o['qty'] > 0 ? (int)round((int)$o['net_total'] / (float)$o['qty']) : 0);
            }
        }

        $moves = []; $sign = self::STOCK_SIGN[$kind];
        foreach ($lines as $l) {
            if ($l['product_id'] === null || (int)$l['track_stock'] !== 1) { continue; }
            $q = (float)$l['qty'];
            $cost = null;
            if ($kind === 'purchase') {
                $cost = $q > 0 ? (int)round((int)$l['net_total'] / $q) : 0;          // بهای تمام‌شده با سهمِ حمل/تخفیف
            } elseif ($l['ref_line_id'] !== null && isset($refCost[(int)$l['ref_line_id']])) {
                $cost = $refCost[(int)$l['ref_line_id']];
            }
            $moves[(int)$l['product_id']][] = ['qty' => $sign * $q, 'unit_cost' => $cost, 'date' => (string)$inv['inv_date']];
        }
        $post = BizStock::postDoc($userId, $kind, $id, $moves);
        if (!$post['ok']) { return $post; }
        // ⛔ IMEI پس از قفلِ ردیفِ کالا (همان postDoc) سنجیده می‌شود: دو صدورِ
        //    هم‌زمانِ یک گوشی پشتِ هم می‌افتند، نه کنارِ هم.
        $imeiErr = BizSerial::check($pdo, $userId, $kind, $id, $lines);
        if ($imeiErr !== null) { return ['ok' => false, 'message' => $imeiErr]; }

        // بهای تمام‌شده‌ی هر ردیف — برای سودِ ناخالص. فروش: میانگینِ همان لحظه.
        $up = $pdo->prepare('UPDATE biz_invoice_lines SET unit_cost = :c WHERE id = :id AND user_id = :u');
        foreach ($lines as $l) {
            $pid = $l['product_id'] !== null ? (int)$l['product_id'] : 0;
            if ($pid === 0) { $c = null; }
            elseif ($kind === 'purchase') { $q = (float)$l['qty']; $c = $q > 0 ? (int)round((int)$l['net_total'] / $q) : 0; }
            elseif ($l['ref_line_id'] !== null && isset($refCost[(int)$l['ref_line_id']])) { $c = $refCost[(int)$l['ref_line_id']]; }
            elseif ((int)$l['track_stock'] === 1) { $c = (int)round($post['avg'][$pid] ?? 0); }
            else { $c = 0; }                                                              // خدمت: بهای تمام‌شده‌ی صفر
            $up->execute(['c' => $c, 'id' => (int)$l['id'], 'u' => $userId]);
        }

        $pdo->prepare("UPDATE biz_invoices SET status = 'issued', number = :n, issued_at = NOW(), voided_at = NULL
                       WHERE id = :id AND user_id = :u")->execute(['n' => $number, 'id' => $id, 'u' => $userId]);

        // ⛔ گزینه‌های فاکتور (`Biz::INVOICE_FLAGS`): «قیمتِ خرید/فروش»ِ کالا به فیِ
        //    همین سند — داخلِ همان تراکنش، پس صدورِ ناموفق قیمت را هم عوض نمی‌کند.
        //    فیِ صفر (هدیه، نمونه) قیمت را صفر نمی‌کند؛ کالای تکراری: آخرین ردیف.
        $prefs = Biz::invoicePrefs($userId);
        $col = $kind === 'purchase' && $prefs['update_buy_price'] ? 'buy_price'
            : ($kind === 'sale' && $prefs['update_sell_price'] ? 'sell_price' : '');
        if ($col !== '') {
            $upP = $pdo->prepare("UPDATE biz_products SET {$col} = :p WHERE id = :id AND user_id = :u");
            foreach ($lines as $l) {
                if ($l['product_id'] !== null && (int)$l['unit_price'] > 0) {
                    $upP->execute(['p' => (int)$l['unit_price'], 'id' => (int)$l['product_id'], 'u' => $userId]);
                }
            }
        }

        if ($payAmount > 0) {
            $p = BizPay::createTx($pdo, $userId, [
                'kind' => self::SETTLED_BY[$kind], 'party_id' => $inv['party_id'] !== null ? (int)$inv['party_id'] : 0,
                'account_id' => (int)($pay['account_id'] ?? 0), 'amount' => (string)$payAmount,
                'method' => (string)($pay['method'] ?? 'cash'), 'pay_date' => (string)$inv['inv_date'],
                'invoice_id' => $id, 'origin_invoice' => 1,
            ]);
            if (!$p['ok']) { return $p; }
        } else {
            BizPay::reallocateTx($pdo, $userId, $inv['party_id'] !== null ? (int)$inv['party_id'] : null, $id);
        }
        return ['ok' => true, 'message' => self::KINDS[$kind] . ' شماره‌ی ' . toPersianDigits((string)$number) . ' صادر شد.'];
    }

    /** برگشتی‌های صادرشده یا پیش‌نویسی که به این سند اشاره می‌کنند. */
    private static function returnsOf(PDO $pdo, int $userId, int $id): int
    {
        $st = $pdo->prepare("SELECT COUNT(*) FROM biz_invoices WHERE ref_invoice_id = :id AND user_id = :u AND status <> 'void'");
        $st->execute(['id' => $id, 'u' => $userId]);
        return (int)$st->fetchColumn();
    }

    /**
     * صادرشده → پیش‌نویس (برای اصلاح). فقط وقتی هیچ دریافت/پرداختی به آن
     * نخورده و هیچ برگشتی از آن نیست؛ شماره می‌ماند.
     * @return array{ok:bool, message:string}
     */
    public static function unissue(int $userId, int $id): array
    {
        return self::undo($userId, $id, 'draft');
    }

    /**
     * ⛔ باطل — اثرِ سند (موجودی و مانده) برمی‌گردد؛ ردیف و شماره می‌مانند.
     *    دریافت/پرداختی که **همراهِ همین سند** ثبت شده بود (`origin_invoice`)
     *    هم باطل می‌شود؛ پرداخت‌های جدا باطل نمی‌شوند و به‌صورتِ پیش‌پرداخت
     *    روی حسابِ طرف‌حساب می‌مانند — پولی که واقعاً جابه‌جا شده پاک
     *    نمی‌شود.
     * @return array{ok:bool, message:string}
     */
    public static function void(int $userId, int $id): array
    {
        return self::undo($userId, $id, 'void');
    }

    private static function undo(int $userId, int $id, string $to): array
    {
        $pdo = Database::getConnection();
        $pdo->beginTransaction();
        try {
            $st = $pdo->prepare('SELECT * FROM biz_invoices WHERE id = :id AND user_id = :u FOR UPDATE');
            $st->execute(['id' => $id, 'u' => $userId]);
            $inv = $st->fetch();
            if (!$inv || $inv['status'] !== 'issued') { $pdo->rollBack(); return ['ok' => false, 'message' => 'فقط سندِ صادرشده.']; }
            if (self::returnsOf($pdo, $userId, $id) > 0) {
                $pdo->rollBack();
                return ['ok' => false, 'message' => 'از این سند برگشت ثبت شده؛ اول برگشت را باطل کنید.'];
            }
            $pays = $pdo->prepare("SELECT id, origin_invoice FROM biz_payments WHERE invoice_id = :id AND user_id = :u AND status = 'ok'");
            $pays->execute(['id' => $id, 'u' => $userId]);
            $linked = $pays->fetchAll();
            if ($to === 'draft') {
                $al = $pdo->prepare('SELECT COUNT(*) FROM biz_allocations WHERE invoice_id = :id AND user_id = :u');
                $al->execute(['id' => $id, 'u' => $userId]);
                if ($linked || (int)$al->fetchColumn() > 0) {
                    $pdo->rollBack();
                    return ['ok' => false, 'message' => 'به این سند دریافت/پرداخت خورده؛ برای اصلاح آن را باطل کنید و سندِ تازه بزنید.'];
                }
            }

            $cl = $pdo->prepare('SELECT DISTINCT product_id FROM biz_stock_moves WHERE user_id = :u AND ref_type = :rt AND ref_id = :r');
            $cl->execute(['u' => $userId, 'rt' => BizStock::REF_INVOICE, 'r' => $id]);
            $post = BizStock::postDoc($userId, (string)$inv['kind'], $id, [], array_map('intval', $cl->fetchAll(PDO::FETCH_COLUMN)));
            if (!$post['ok']) {
                $pdo->rollBack();
                return ['ok' => false, 'message' => $post['message'] . ' (کالاهای این سند بعداً جابه‌جا شده‌اند)'];
            }

            foreach ($linked as $p) {
                if ((int)$p['origin_invoice'] === 1) {
                    $pdo->prepare("UPDATE biz_payments SET status = 'void', voided_at = NOW() WHERE id = :id AND user_id = :u")
                        ->execute(['id' => (int)$p['id'], 'u' => $userId]);
                }
            }
            if ($to === 'void') {
                $pdo->prepare("UPDATE biz_invoices SET status = 'void', voided_at = NOW() WHERE id = :id AND user_id = :u")
                    ->execute(['id' => $id, 'u' => $userId]);
            } else {
                $pdo->prepare("UPDATE biz_invoices SET status = 'draft', issued_at = NULL WHERE id = :id AND user_id = :u")
                    ->execute(['id' => $id, 'u' => $userId]);
                $pdo->prepare('UPDATE biz_invoice_lines SET unit_cost = NULL WHERE invoice_id = :id AND user_id = :u')
                    ->execute(['id' => $id, 'u' => $userId]);
            }
            BizPay::reallocateTx($pdo, $userId, $inv['party_id'] !== null ? (int)$inv['party_id'] : null, $id);
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) { $pdo->rollBack(); }
            throw $e;
        }
        return ['ok' => true, 'message' => $to === 'void' ? 'سند باطل شد و اثرش برگشت.' : 'سند به پیش‌نویس برگشت.'];
    }

    /** حذفِ پیش‌نویس — سندِ صادرشده حذف نمی‌شود. */
    public static function deleteDraft(int $userId, int $id): array
    {
        $st = Database::getConnection()->prepare("DELETE FROM biz_invoices WHERE id = :id AND user_id = :u AND status = 'draft'");
        $st->execute(['id' => $id, 'u' => $userId]);
        return $st->rowCount() > 0 ? ['ok' => true, 'message' => 'پیش‌نویس حذف شد.']
                                   : ['ok' => false, 'message' => 'فقط پیش‌نویس حذف می‌شود؛ سندِ صادرشده را باطل کنید.'];
    }

    /* ------------------------------------------------------------
       برگشت (مرجوعی)
       ------------------------------------------------------------ */

    /**
     * مقدارِ برگشت‌پذیرِ هر ردیفِ فاکتورِ اصلی = مقدارِ ردیف − آنچه در
     * برگشت‌های باطل‌نشده آمده.
     * @return array<int,array{line:array, left:float}> کلید = شناسه‌ی ردیف
     */
    public static function returnable(int $userId, array $orig): array
    {
        $st = Database::getConnection()->prepare(
            "SELECT l.ref_line_id, SUM(l.qty) AS q FROM biz_invoice_lines l
             JOIN biz_invoices i ON i.id = l.invoice_id AND i.user_id = l.user_id
             WHERE i.ref_invoice_id = :r AND i.user_id = :u AND i.status <> 'void' AND l.ref_line_id IS NOT NULL
             GROUP BY l.ref_line_id"
        );
        $st->execute(['r' => (int)$orig['id'], 'u' => $userId]);
        $done = [];
        foreach ($st->fetchAll() as $r) { $done[(int)$r['ref_line_id']] = (float)$r['q']; }
        $out = [];
        foreach ($orig['lines'] as $l) {
            $out[(int)$l['id']] = ['line' => $l, 'left' => max(0.0, round((float)$l['qty'] - ($done[(int)$l['id']] ?? 0.0), 3))];
        }
        return $out;
    }

    /**
     * ⛔ برگشت از روی فاکتورِ اصلی — ساخت و صدور در **یک** تراکنش.
     *    بهای هر ردیف همان «خالصِ» ردیفِ اصلی است (پس از سهمِ تخفیف/حملِ
     *    فاکتور)، پس مبلغِ برگشت همان است که واقعاً پرداخت شد؛ و مقدار هرگز
     *    از «برگشت‌پذیر» بیشتر نمی‌شود.
     *
     * @param array<int,string> $qtys  شناسه‌ی ردیفِ اصلی → مقدار
     * @return array{ok:bool, message:string, id?:int}
     */
    public static function createReturn(int $userId, int $origId, array $qtys, array $pay = [], string $date = '', string $note = ''): array
    {
        $orig = self::get($userId, $origId);
        if (!$orig || !isset(self::RETURN_OF[$orig['kind']])) { return ['ok' => false, 'message' => 'فاکتورِ اصلی پیدا نشد.']; }
        if ($orig['status'] !== 'issued') { return ['ok' => false, 'message' => 'فقط از فاکتورِ صادرشده برگشت زده می‌شود.']; }
        $kind = self::RETURN_OF[$orig['kind']];
        $left = self::returnable($userId, $orig);

        $lines = [];
        foreach ($qtys as $lineId => $raw) {
            $q = sanitizeQty((string)$raw);
            if ($q <= 0) { continue; }
            $lineId = (int)$lineId;
            if (!isset($left[$lineId])) { return ['ok' => false, 'message' => 'ردیفِ برگشت به این فاکتور تعلق ندارد.']; }
            $o = $left[$lineId]['line'];
            if ($q > $left[$lineId]['left'] + 0.0005) {
                return ['ok' => false, 'message' => '«' . $o['description'] . '» حداکثر ' . formatQty($left[$lineId]['left']) . ' ' . $o['unit'] . ' برگشت‌پذیر است.'];
            }
            if ($o['product_id'] !== null && !BizProducts::qtyFits((string)$o['unit'], $q)) {
                return ['ok' => false, 'message' => 'تعدادِ «' . $o['description'] . '» باید عددِ صحیح باشد.'];
            }
            $unitNet = (float)$o['qty'] > 0 ? (int)$o['net_total'] / (float)$o['qty'] : 0;
            $lt = (int)round($q * $unitNet);
            $lines[] = ['product_id' => $o['product_id'] !== null ? (int)$o['product_id'] : null, 'ref_line_id' => $lineId,
                        'description' => (string)$o['description'], 'unit' => (string)$o['unit'], 'qty' => round($q, 3),
                        'unit_price' => (int)round($unitNet), 'line_discount' => 0, 'line_total' => $lt, 'net_total' => $lt,
                        'unit_cost' => null, 'imei1' => $o['imei1'] ?? null, 'imei2' => $o['imei2'] ?? null,
                        'note' => $o['note'] ?? null];
        }
        if (!$lines) { return ['ok' => false, 'message' => 'تعدادِ برگشت را دست‌کم برای یک ردیف بنویسید.']; }
        $total = array_sum(array_column($lines, 'line_total'));
        // ⛔ «پس دادنِ کامل» یعنی همان مبلغی که همین‌جا ساخته شد — صفحه آن را
        //    دوباره حساب نمی‌کند (دو جای حسابِ پول دیر یا زود دو عدد می‌گویند).
        if (!empty($pay['full'])) { $pay['amount'] = (string)$total; }
        $date  = isValidDate($date) ? $date : date('Y-m-d');
        if ($date < (string)$orig['inv_date']) { return ['ok' => false, 'message' => 'تاریخِ برگشت نمی‌تواند پیش از فاکتورِ اصلی باشد.']; }
        $note = mb_substr(trim($note), 0, self::NOTE_MAX);

        $pdo = Database::getConnection();
        $pdo->beginTransaction();
        try {
            $pdo->prepare("INSERT INTO biz_invoices (user_id, kind, status, party_id, ref_invoice_id, inv_date, subtotal, total, note)
                           VALUES (:u, :k, 'draft', :p, :r, :d, :s, :t, :n)")
                ->execute(['u' => $userId, 'k' => $kind, 'p' => $orig['party_id'], 'r' => $origId, 'd' => $date,
                           's' => $total, 't' => $total, 'n' => $note === '' ? null : $note]);
            $id = (int)$pdo->lastInsertId();
            self::writeLines($pdo, $userId, $id, $lines);
            $r = self::issueTx($pdo, $userId, $id, $pay);
            if (!$r['ok']) { $pdo->rollBack(); return $r; }
            $pdo->commit();
            return ['ok' => true, 'message' => $r['message'], 'id' => $id];
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) { $pdo->rollBack(); }
            throw $e;
        }
    }

    /* ------------------------------------------------------------
       خلاصه‌ها — داشبورد و گزارش
       ------------------------------------------------------------ */

    /** شمارِ پیش‌نویس‌ها (کارتِ «نیازمندِ اقدام»؛ سررسید دیگر نیست — `state()`). */
    public static function attention(int $userId): array
    {
        $st = Database::getConnection()->prepare("SELECT COALESCE(SUM(status = 'draft'), 0) AS drafts FROM biz_invoices WHERE user_id = :u");
        $st->execute(['u' => $userId]);
        $r = $st->fetch() ?: [];
        return array_map('intval', $r + ['drafts' => 0]);
    }

    /** آخرین اسناد (همه‌ی نوع‌ها). */
    public static function recent(int $userId, int $limit = 8): array
    {
        $st = Database::getConnection()->prepare(
            'SELECT i.id, i.kind, i.number, i.status, i.inv_date, i.due_date, i.total, i.paid, p.name AS party_name
             FROM biz_invoices i LEFT JOIN biz_parties p ON p.id = i.party_id AND p.user_id = i.user_id
             WHERE i.user_id = :u ORDER BY i.updated_at DESC, i.id DESC LIMIT :lim'
        );
        $st->bindValue('u', $userId, PDO::PARAM_INT);
        $st->bindValue('lim', $limit, PDO::PARAM_INT);
        $st->execute();
        return $st->fetchAll();
    }
}

/* =================================================================
   دریافت، پرداخت، هزینه، درآمد، انتقال
   ================================================================= */
final class BizPay
{
    /** ⛔ تنها مرجعِ نوع‌ها. */
    public const KINDS = [
        'receipt'  => 'دریافت',
        'payment'  => 'پرداخت',
        'expense'  => 'هزینه‌ی فروشگاه',
        'income'   => 'درآمدِ متفرقه',
        'transfer' => 'انتقال بینِ صندوق‌ها',
    ];

    public const METHODS = [
        'cash'     => 'نقد',
        'card'     => 'کارت‌خوان',
        'transfer' => 'کارت‌به‌کارت / حواله',
        'cheque'   => 'چک',
    ];

    /**
     * روش‌های پرداختِ همراهِ فاکتور، فروشِ سریع و برگشت. ⛔ «چک» اینجا نیست:
     * چک شماره و سررسید می‌خواهد و آن فرم‌ها جایش را ندارند؛ چک از
     * «دریافت/پرداخت» ثبت می‌شود و خودش به فاکتورِ باز می‌خورد.
     * ⚠ بی‌قید است (نه «اگر migration آمده») تا این سه صفحه کوئریِ
     *   `schemaMap()` نگیرند.
     */
    public static function quickMethods(): array
    {
        $m = self::METHODS;
        unset($m['cheque']);
        return $m;
    }

    /** نوعِ سندی که هر دریافت/پرداخت تسویه می‌کند — وارونه‌ی `BizInvoices::SETTLED_BY`. */
    public const SETTLES = ['receipt' => ['sale', 'purchase_return'], 'payment' => ['purchase', 'sale_return']];

    public const FILTERS = ['' => 'همه', 'receipt' => 'دریافت‌ها', 'payment' => 'پرداخت‌ها', 'expense' => 'هزینه‌ها',
                            'income' => 'درآمدها', 'transfer' => 'انتقال‌ها', 'void' => 'باطل'];

    public const PAGE_SIZE = 25;
    public const TITLE_MAX = 150;

    /** نشانه‌ی جهتِ پول برای صندوقِ مبدأ: + ورود، − خروج. */
    public static function cashSign(string $kind): int
    {
        return in_array($kind, ['receipt', 'income'], true) ? 1 : -1;
    }

    /** @return array{ok:bool, message:string, id?:int} */
    public static function create(int $userId, array $in): array
    {
        $pdo = Database::getConnection();
        $pdo->beginTransaction();
        try {
            $r = self::createTx($pdo, $userId, $in);
            if (!$r['ok']) { $pdo->rollBack(); return $r; }
            $pdo->commit();
            return $r;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) { $pdo->rollBack(); }
            throw $e;
        }
    }

    /** بدنه‌ی ثبت، داخلِ تراکنشِ فراخواننده (صدورِ فاکتور هم از همین می‌گذرد). */
    public static function createTx(PDO $pdo, int $userId, array $in): array
    {
        $kind   = (string)($in['kind'] ?? '');
        $amount = sanitizeAmount($in['amount'] ?? '');
        $acc    = (int)($in['account_id'] ?? 0);
        $to     = (int)($in['to_account_id'] ?? 0);
        $party  = (int)($in['party_id'] ?? 0);
        $invId  = (int)($in['invoice_id'] ?? 0);
        $method = (string)($in['method'] ?? 'cash');
        $date   = (string)($in['pay_date'] ?? '');
        $title  = BizCommon::line((string)($in['title'] ?? ''));
        $note   = mb_substr(trim((string)($in['note'] ?? '')), 0, 300);

        if (!isset(self::KINDS[$kind])) { return ['ok' => false, 'message' => 'نوعِ سند معتبر نیست.']; }
        if ($amount <= 0) { return ['ok' => false, 'message' => 'مبلغ باید بیشتر از صفر باشد.']; }
        if (!isset(self::METHODS[$method])) { $method = 'cash'; }
        if (!isValidDate($date)) { $date = date('Y-m-d'); }
        if (mb_strlen($title) > self::TITLE_MAX) { return ['ok' => false, 'message' => 'شرح بیش از ' . self::TITLE_MAX . ' نویسه است.']; }

        // ⛔ چکِ دریافتی/پرداختی: پول به صندوقِ «چک‌های در جریان» می‌رود نه
        //    صندوقی که کاربر انتخاب کرده — تا وصول نشده نقد نیست. مانده‌ی
        //    طرف‌حساب همین حالا کم می‌شود (چک را گرفته‌ایم)؛ اگر برگشت خورد،
        //    `BizCheques::bounce()` سند را باطل می‌کند و بدهی برمی‌گردد.
        $chq = null;
        if ($method === 'cheque' && in_array($kind, ['receipt', 'payment'], true) && BizCheques::ready()) {
            $due  = (string)($in['cheque_due'] ?? '');
            $cno  = BizCommon::line(toLatinDigits((string)($in['cheque_no'] ?? '')));
            $cbnk = BizCommon::line((string)($in['cheque_bank'] ?? ''));
            if (!isValidDate($due)) { return ['ok' => false, 'message' => 'تاریخِ سررسیدِ چک را وارد کنید.']; }
            if (mb_strlen($cno) > 30)  { return ['ok' => false, 'message' => 'شماره‌ی چک بیش از ۳۰ نویسه است.']; }
            if (mb_strlen($cbnk) > 60) { return ['ok' => false, 'message' => 'نامِ بانک بیش از ۶۰ نویسه است.']; }
            $acc = BizCash::chequeAccount($pdo, $userId, $kind === 'receipt' ? 'in' : 'out');
            $chq = ['no' => $cno === '' ? null : $cno, 'bank' => $cbnk === '' ? null : $cbnk, 'due' => $due];
        }
        $settle = !empty($in['_cheque_settle']);

        $accOk = $pdo->prepare('SELECT is_active, kind FROM biz_accounts WHERE id = :id AND user_id = :u');
        $accOk->execute(['id' => $acc, 'u' => $userId]);
        $a = $accOk->fetch();
        if ($a === false) { return ['ok' => false, 'message' => 'صندوق یا حساب را انتخاب کنید.']; }
        if ((int)$a['is_active'] !== 1) { return ['ok' => false, 'message' => 'این صندوق غیرفعال است.']; }
        // ⛔ صندوقِ چک فقط از دو راه پول می‌گیرد یا می‌دهد: ثبتِ چک و وصولش
        if (BizCash::isCheque($a) && $chq === null && !$settle) {
            return ['ok' => false, 'message' => 'صندوقِ چک را خودِ برنامه پر و خالی می‌کند — از صفحه‌ی «چک‌ها» وصول کنید.'];
        }

        if ($kind === 'transfer') {
            $accOk->execute(['id' => $to, 'u' => $userId]);
            $t = $accOk->fetch();
            if ($to === $acc || $t === false || (int)$t['is_active'] !== 1) { return ['ok' => false, 'message' => 'صندوقِ مقصد باید فعال و متفاوت باشد.']; }
            if (BizCash::isCheque($t) && !$settle) {
                return ['ok' => false, 'message' => 'صندوقِ چک را خودِ برنامه پر و خالی می‌کند — از صفحه‌ی «چک‌ها» وصول کنید.'];
            }
            $party = 0; $invId = 0;
        } else {
            $to = 0;
        }
        if (in_array($kind, ['expense', 'income'], true)) {
            if ($title === '') { return ['ok' => false, 'message' => 'شرحِ ' . self::KINDS[$kind] . ' را بنویسید (مثلاً اجاره، قبضِ برق).']; }
            $party = 0; $invId = 0;
        }
        if ($party > 0 && !BizParties::get($userId, $party)) { return ['ok' => false, 'message' => 'طرف‌حساب پیدا نشد.']; }
        if ($invId > 0) {
            $iv = $pdo->prepare('SELECT kind, party_id, status FROM biz_invoices WHERE id = :id AND user_id = :u');
            $iv->execute(['id' => $invId, 'u' => $userId]);
            $inv = $iv->fetch();
            if (!$inv || $inv['status'] !== 'issued' || !in_array($inv['kind'], self::SETTLES[$kind] ?? [], true)) {
                return ['ok' => false, 'message' => 'این سند با این دریافت/پرداخت تسویه نمی‌شود.'];
            }
            // ⛔ پول باید مالِ همان طرف‌حسابِ فاکتور باشد
            if ($inv['party_id'] !== null && $party !== (int)$inv['party_id']) { $party = (int)$inv['party_id']; }
            if ($inv['party_id'] === null) { $party = 0; }
        }
        if (in_array($kind, ['receipt', 'payment'], true) && $party === 0 && $invId === 0) {
            return ['ok' => false, 'message' => 'برای دریافت/پرداخت، طرف‌حساب را انتخاب کنید (یا از صفحه‌ی خودِ فاکتور ثبت کنید).'];
        }
        // ⛔ چکِ برگشتی باید به کسی برگردد؛ فاکتورِ گذری طرف‌حسابی ندارد
        if ($chq !== null && $party === 0) {
            return ['ok' => false, 'message' => 'چک فقط از طرف‌حسابِ ثبت‌شده پذیرفته می‌شود؛ برای فاکتورِ گذری نقد یا کارت ثبت کنید.'];
        }

        $n = $pdo->prepare('SELECT COALESCE(MAX(number), 0) + 1 FROM biz_payments WHERE user_id = :u AND kind = :k FOR UPDATE');
        $n->execute(['u' => $userId, 'k' => $kind]);
        $number = (int)$n->fetchColumn();
        $pdo->prepare('INSERT INTO biz_payments (user_id, kind, number, party_id, account_id, to_account_id, invoice_id, origin_invoice,
                                                 amount, pay_date, method, title, note)
                       VALUES (:u, :k, :no, :p, :a, :t, :i, :o, :am, :d, :m, :ti, :n)')
            ->execute(['u' => $userId, 'k' => $kind, 'no' => $number, 'p' => $party ?: null, 'a' => $acc, 't' => $to ?: null,
                       'i' => $invId ?: null, 'o' => !empty($in['origin_invoice']) ? 1 : 0, 'am' => $amount, 'd' => $date,
                       'm' => $method, 'ti' => $title === '' ? null : $title, 'n' => $note === '' ? null : $note]);
        $id = (int)$pdo->lastInsertId();
        if ($chq !== null) {
            $pdo->prepare("UPDATE biz_payments SET cheque_no = :no, cheque_bank = :b, cheque_due = :d, cheque_status = 'pending'
                           WHERE id = :id AND user_id = :u")
                ->execute(['no' => $chq['no'], 'b' => $chq['bank'], 'd' => $chq['due'], 'id' => $id, 'u' => $userId]);
        }
        if (in_array($kind, ['receipt', 'payment'], true)) {
            self::reallocateTx($pdo, $userId, $party ?: null, $invId ?: null);
        }
        return ['ok' => true, 'message' => self::KINDS[$kind] . ' شماره‌ی ' . toPersianDigits((string)$number) . ' ثبت شد.', 'id' => $id];
    }

    /**
     * باطل — پول برمی‌گردد (از موجودیِ صندوق و مانده بیرون می‌رود). ⛔
     * دریافتِ فاکتورِ گذری باطل نمی‌شود مگر خودِ فاکتور باطل شود — وگرنه
     * فاکتوری صادرشده می‌ماند که بدهکاری ندارد.
     * @return array{ok:bool, message:string}
     */
    public static function void(int $userId, int $id): array
    {
        $pdo = Database::getConnection();
        $pdo->beginTransaction();
        try {
            $st = $pdo->prepare('SELECT y.*, i.party_id AS inv_party, i.status AS inv_status FROM biz_payments y
                                 LEFT JOIN biz_invoices i ON i.id = y.invoice_id AND i.user_id = y.user_id
                                 WHERE y.id = :id AND y.user_id = :u FOR UPDATE');
            $st->execute(['id' => $id, 'u' => $userId]);
            $p = $st->fetch();
            if (!$p || $p['status'] !== 'ok') { $pdo->rollBack(); return ['ok' => false, 'message' => 'سند پیدا نشد یا از قبل باطل است.']; }
            if ($p['invoice_id'] !== null && $p['inv_party'] === null && $p['inv_status'] === 'issued') {
                $pdo->rollBack();
                return ['ok' => false, 'message' => 'این دریافت/پرداختِ یک فاکتورِ گذری است؛ خودِ فاکتور را باطل کنید.'];
            }
            // ⛔ چکِ وصول‌شده و انتقالِ وصولش جدا باطل نمی‌شوند: یکی بی‌دیگری
            //    یعنی پولی که یا دو بار در بانک است یا هیچ‌جا
            if (BizCheques::ready()) {
                if (($p['cheque_status'] ?? null) === 'cleared') {
                    $pdo->rollBack();
                    return ['ok' => false, 'message' => 'این چک وصول شده؛ اول از صفحه‌ی «چک‌ها» وصول را برگردانید.'];
                }
                $ref = $pdo->prepare("SELECT COUNT(*) FROM biz_payments WHERE user_id = :u AND id = :c AND cheque_status = 'cleared'");
                $ref->execute(['u' => $userId, 'c' => (int)($p['cheque_settle_id'] ?? 0)]);
                if ($p['kind'] === 'transfer' && (int)$ref->fetchColumn() > 0) {
                    $pdo->rollBack();
                    return ['ok' => false, 'message' => 'این انتقال وصولِ یک چک است؛ از صفحه‌ی «چک‌ها» برگردانید.'];
                }
            }
            $pdo->prepare("UPDATE biz_payments SET status = 'void', voided_at = NOW() WHERE id = :id AND user_id = :u")
                ->execute(['id' => $id, 'u' => $userId]);
            if (in_array($p['kind'], ['receipt', 'payment'], true)) {
                self::reallocateTx($pdo, $userId, $p['party_id'] !== null ? (int)$p['party_id'] : null,
                                   $p['invoice_id'] !== null ? (int)$p['invoice_id'] : null);
            }
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) { $pdo->rollBack(); }
            throw $e;
        }
        return ['ok' => true, 'message' => 'سند باطل شد.'];
    }

    /**
     * ⛔ تنها نویسنده‌ی تخصیص‌ها و کش‌های `paid`/`allocated`.
     *
     * برای یک طرف‌حساب همه چیز از نو ساخته می‌شود: هر دریافت/پرداختِ
     * باطل‌نشده به ترتیبِ تاریخ اول به فاکتورِ ترجیحیِ خودش (`invoice_id`)
     * و بعد به قدیمی‌ترین سندِ بازِ هم‌جهت می‌خورد. برای سندِ گذری
     * (بی‌طرف‌حساب) فقط پرداخت‌هایی که به خودِ آن اشاره می‌کنند.
     * باقیمانده‌ی پرداخت «پیش‌پرداخت» است — در مانده هست، به فاکتوری نخورده.
     */
    public static function reallocateTx(PDO $pdo, int $userId, ?int $partyId, ?int $invoiceId = null): void
    {
        if ($partyId !== null) {
            $inv = $pdo->prepare("SELECT id, kind, total, status, ref_invoice_id FROM biz_invoices WHERE user_id = :u AND party_id = :p ORDER BY inv_date, id FOR UPDATE");
            $inv->execute(['u' => $userId, 'p' => $partyId]);
            $pay = $pdo->prepare("SELECT id, kind, amount, invoice_id, status FROM biz_payments
                                  WHERE user_id = :u AND party_id = :p AND kind IN ('receipt','payment') ORDER BY pay_date, id FOR UPDATE");
            $pay->execute(['u' => $userId, 'p' => $partyId]);
        } elseif ($invoiceId !== null) {
            $inv = $pdo->prepare('SELECT id, kind, total, status, ref_invoice_id FROM biz_invoices WHERE user_id = :u AND id = :i FOR UPDATE');
            $inv->execute(['u' => $userId, 'i' => $invoiceId]);
            $pay = $pdo->prepare("SELECT id, kind, amount, invoice_id, status FROM biz_payments
                                  WHERE user_id = :u AND invoice_id = :i AND party_id IS NULL ORDER BY pay_date, id FOR UPDATE");
            $pay->execute(['u' => $userId, 'i' => $invoiceId]);
        } else {
            return;
        }
        $invoices = []; foreach ($inv->fetchAll() as $r) { $invoices[(int)$r['id']] = $r + ['open' => $r['status'] === 'issued' ? (int)$r['total'] : 0, 'paid' => 0]; }
        $payments = $pay->fetchAll();

        if ($payments) {
            $ids = array_map(fn($p) => (int)$p['id'], $payments);
            $pdo->prepare('DELETE FROM biz_allocations WHERE user_id = ? AND payment_id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')')
                ->execute(array_merge([$userId], $ids));
        }
        $insA = $pdo->prepare('INSERT INTO biz_allocations (user_id, payment_id, invoice_id, amount) VALUES (:u, :p, :i, :a)');
        $upP  = $pdo->prepare('UPDATE biz_payments SET allocated = :a WHERE id = :id AND user_id = :u');
        foreach ($payments as $p) {
            $left = $p['status'] === 'ok' ? (int)$p['amount'] : 0;
            $kinds = self::SETTLES[$p['kind']] ?? [];
            $order = [];
            if ($p['invoice_id'] !== null && isset($invoices[(int)$p['invoice_id']])) { $order[] = (int)$p['invoice_id']; }
            foreach (array_keys($invoices) as $iid) { if (!in_array($iid, $order, true)) { $order[] = $iid; } }
            $alloc = 0;
            foreach ($order as $iid) {
                if ($left <= 0) { break; }
                $iv = $invoices[$iid];
                if (!in_array($iv['kind'], $kinds, true) || $iv['open'] <= 0) { continue; }
                $x = min($left, $iv['open']);
                $insA->execute(['u' => $userId, 'p' => (int)$p['id'], 'i' => $iid, 'a' => $x]);
                $invoices[$iid]['open'] -= $x;
                $invoices[$iid]['paid'] += $x;
                $left -= $x; $alloc += $x;
            }
            $upP->execute(['a' => $alloc, 'id' => (int)$p['id'], 'u' => $userId]);
        }
        // ⛔ برگشتِ نسیه یک «اعتبار» است: باقیمانده‌اش (آنچه پس داده نشده) اول
        //    به فاکتورِ اصلیِ خودش و بعد به قدیمی‌ترین فاکتورِ بازِ هم‌جهت می‌خورد.
        //    بدونش فاکتوری که کالایش برگشته «سررسید گذشته» می‌ماند در حالی که
        //    مانده‌ی طرف‌حساب (BALANCE_SQL) درست بود — دو عدد برای یک حقیقت.
        //    ردیفِ تخصیص نمی‌سازد (تخصیص مالِ پول است)؛ فقط کشِ `paid` دو سو.
        foreach ($invoices as $rid => $rv) {
            $orig = array_search($rv['kind'], BizInvoices::RETURN_OF, true);
            if ($orig === false || $rv['open'] <= 0) { continue; }
            $order = [];
            if ($rv['ref_invoice_id'] !== null && isset($invoices[(int)$rv['ref_invoice_id']])) { $order[] = (int)$rv['ref_invoice_id']; }
            foreach (array_keys($invoices) as $iid) { if (!in_array($iid, $order, true)) { $order[] = $iid; } }
            foreach ($order as $iid) {
                if ($invoices[$rid]['open'] <= 0) { break; }
                $iv = $invoices[$iid];
                if ($iv['kind'] !== $orig || $iv['open'] <= 0) { continue; }
                $x = min($invoices[$rid]['open'], $iv['open']);
                $invoices[$iid]['open'] -= $x; $invoices[$iid]['paid'] += $x;
                $invoices[$rid]['open'] -= $x; $invoices[$rid]['paid'] += $x;
            }
        }
        $upI = $pdo->prepare('UPDATE biz_invoices SET paid = :p WHERE id = :id AND user_id = :u');
        foreach ($invoices as $iid => $iv) { $upI->execute(['p' => $iv['paid'], 'id' => $iid, 'u' => $userId]); }
    }

    public static function get(int $userId, int $id): ?array
    {
        $st = Database::getConnection()->prepare(
            'SELECT y.*, p.name AS party_name, a.name AS account_name, t.name AS to_account_name
             FROM biz_payments y
             LEFT JOIN biz_parties p ON p.id = y.party_id AND p.user_id = y.user_id
             LEFT JOIN biz_accounts a ON a.id = y.account_id AND a.user_id = y.user_id
             LEFT JOIN biz_accounts t ON t.id = y.to_account_id AND t.user_id = y.user_id
             WHERE y.id = :id AND y.user_id = :u LIMIT 1'
        );
        $st->execute(['id' => $id, 'u' => $userId]);
        $p = $st->fetch();
        if (!$p) { return null; }
        $st = Database::getConnection()->prepare(
            'SELECT al.amount, i.id, i.kind, i.number FROM biz_allocations al
             JOIN biz_invoices i ON i.id = al.invoice_id AND i.user_id = al.user_id
             WHERE al.payment_id = :id AND al.user_id = :u ORDER BY al.id'
        );
        $st->execute(['id' => $id, 'u' => $userId]);
        $p['allocations'] = $st->fetchAll();
        return $p;
    }

    /** پرداخت‌هایی که به یک سند خورده‌اند (برای صفحه‌ی سند). */
    public static function forInvoice(int $userId, int $invoiceId): array
    {
        $st = Database::getConnection()->prepare(
            'SELECT y.id, y.kind, y.number, y.pay_date, y.method, y.status, al.amount AS applied, y.amount, a.name AS account_name
             FROM biz_allocations al
             JOIN biz_payments y ON y.id = al.payment_id AND y.user_id = al.user_id
             LEFT JOIN biz_accounts a ON a.id = y.account_id AND a.user_id = y.user_id
             WHERE al.invoice_id = :i AND al.user_id = :u ORDER BY y.pay_date, y.id'
        );
        $st->execute(['i' => $invoiceId, 'u' => $userId]);
        return $st->fetchAll();
    }

    /** @return array{rows:array, total:int, page:int, pages:int, in:int, out:int} */
    public static function list(int $userId, string $filter = '', int $account = 0, string $q = '', int $page = 1, string $from = '', string $to = ''): array
    {
        $where = ['y.user_id = :u']; $params = ['u' => $userId];
        $filter = isset(self::FILTERS[$filter]) ? $filter : '';
        if ($filter === 'void') { $where[] = "y.status = 'void'"; }
        elseif ($filter !== '') { $where[] = "y.status = 'ok' AND y.kind = :k"; $params['k'] = $filter; }
        if ($account > 0) { $where[] = '(y.account_id = :a1 OR y.to_account_id = :a2)'; $params['a1'] = $params['a2'] = $account; }
        if ($q !== '') {
            $where[] = "(p.name LIKE :q1 ESCAPE '!' OR y.title LIKE :q2 ESCAPE '!' OR y.note LIKE :q3 ESCAPE '!')";
            $params['q1'] = $params['q2'] = $params['q3'] = BizCommon::like($q);
        }
        if ($from !== '' && isValidDate($from)) { $where[] = 'y.pay_date >= :df'; $params['df'] = $from; }
        if ($to !== '' && isValidDate($to))     { $where[] = 'y.pay_date <= :dt'; $params['dt'] = $to; }
        $w = implode(' AND ', $where);
        $join = 'FROM biz_payments y LEFT JOIN biz_parties p ON p.id = y.party_id AND p.user_id = y.user_id
                 LEFT JOIN biz_accounts a ON a.id = y.account_id AND a.user_id = y.user_id
                 LEFT JOIN biz_accounts t ON t.id = y.to_account_id AND t.user_id = y.user_id';
        $pdo = Database::getConnection();
        $st = $pdo->prepare("SELECT COUNT(*) AS n,
                     COALESCE(SUM(CASE WHEN y.status = 'ok' AND y.kind IN ('receipt','income') THEN y.amount ELSE 0 END), 0) AS i,
                     COALESCE(SUM(CASE WHEN y.status = 'ok' AND y.kind IN ('payment','expense') THEN y.amount ELSE 0 END), 0) AS o
                     {$join} WHERE {$w}");
        $st->execute($params);
        $agg = $st->fetch() ?: ['n' => 0, 'i' => 0, 'o' => 0];
        [$page, $pages, $offset] = BizCommon::window((int)$agg['n'], $page, self::PAGE_SIZE);
        $st = $pdo->prepare("SELECT y.*, p.name AS party_name, a.name AS account_name, t.name AS to_account_name {$join}
                             WHERE {$w} ORDER BY y.pay_date DESC, y.id DESC LIMIT :lim OFFSET :off");
        foreach ($params as $k => $v) { $st->bindValue($k, $v); }
        $st->bindValue('lim', self::PAGE_SIZE, PDO::PARAM_INT);
        $st->bindValue('off', $offset, PDO::PARAM_INT);
        $st->execute();
        return ['rows' => $st->fetchAll(), 'total' => (int)$agg['n'], 'page' => $page, 'pages' => $pages,
                'in' => (int)$agg['i'], 'out' => (int)$agg['o']];
    }

    /** عنوانِ یک ردیف برای فهرست: طرف‌حساب، شرحِ هزینه، یا «از … به …». */
    public static function label(array $p): string
    {
        if ($p['kind'] === 'transfer') { return 'از ' . ($p['account_name'] ?? '') . ' به ' . ($p['to_account_name'] ?? ''); }
        if (in_array($p['kind'], ['expense', 'income'], true)) { return (string)($p['title'] ?? ''); }
        return (string)($p['party_name'] ?? 'گذری');
    }
}

/* =================================================================
   دفترِ چک (migration_biz_cheques)
   ================================================================= */
/**
 * ⛔ چک یک ردیفِ جدا نیست؛ همان دریافت/پرداختی است که روشش «چک» است و
 *    چهار ستونِ `cheque_*` دارد. پس مانده‌ی طرف‌حساب، تسویه‌ی فاکتور و
 *    رسیدِ چاپی بی‌هیچ کدِ تازه‌ای درست‌اند؛ فقط **صندوقش** فرق دارد:
 *    «چک‌های دریافتی/پرداختیِ در جریان» (`BizCash::chequeAccount()`).
 *
 *    چرخه: `pending` → `cleared` (یک انتقالِ صندوقِ چک ↔ بانک؛ **انتقال** با
 *                    `cheque_settle_id` به چک اشاره می‌کند — ارجاعِ رو به عقب)
 *                    → `bounced` (خودِ سند باطل؛ بدهیِ طرف‌حساب برمی‌گردد).
 *    وصول برگشت‌پذیر است (`unclear()`)؛ برگشتی نه — چکِ تازه ثبت کنید.
 */
final class BizCheques
{
    public const FILTERS = [
        ''        => 'در جریان',
        'in'      => 'دریافتی',
        'out'     => 'پرداختی',
        'overdue' => 'سررسید گذشته',
        'cleared' => 'وصول‌شده',
        'bounced' => 'برگشتی',
        'all'     => 'همه',
    ];
    public const STATUSES = ['pending' => 'در جریان', 'cleared' => 'وصول‌شده', 'bounced' => 'برگشتی'];
    public const PAGE_SIZE = 25;
    public const DUE_DAYS  = 7;

    /** ستون‌ها آمده‌اند؟ (بدون migration، «چک» همان برچسبِ قدیمی است.) */
    public static function ready(): bool
    {
        return function_exists('tableHasColumn') && tableHasColumn('biz_payments', 'cheque_status');
    }

    /** ⛔ تنها تعریفِ صافی‌ها — فهرست، چاپ و داشبورد همه از همین. */
    private static function where(string $filter): string
    {
        $pend = "y.status = 'ok' AND y.cheque_status = 'pending'";
        switch ($filter) {
            case 'in':      return "{$pend} AND y.kind = 'receipt'";
            case 'out':     return "{$pend} AND y.kind = 'payment'";
            case 'overdue': return "{$pend} AND y.cheque_due < CURDATE()";
            case 'cleared': return "y.cheque_status = 'cleared'";
            case 'bounced': return "y.cheque_status = 'bounced'";
            case 'all':     return 'y.cheque_status IS NOT NULL';
            default:        return $pend;
        }
    }

    private const JOIN = "FROM biz_payments y
        LEFT JOIN biz_parties p ON p.id = y.party_id AND p.user_id = y.user_id
        LEFT JOIN biz_payments s ON s.cheque_settle_id = y.id AND s.user_id = y.user_id AND s.kind = 'transfer' AND s.status = 'ok'
        LEFT JOIN biz_accounts b ON b.user_id = y.user_id
             AND b.id = (CASE WHEN y.kind = 'receipt' THEN s.to_account_id ELSE s.account_id END)";

    /** @return array{rows:array, total:int, page:int, pages:int, in:int, out:int, overdue:int} */
    public static function list(int $userId, string $filter = '', int $page = 1, int $cap = 0): array
    {
        $filter = isset(self::FILTERS[$filter]) ? $filter : '';
        $pdo = Database::getConnection();
        // جمع‌های بالای صفحه همیشه از «در جریان» اند، مستقل از صافی
        $st = $pdo->prepare("SELECT
                COALESCE(SUM(CASE WHEN y.kind = 'receipt' THEN y.amount ELSE 0 END), 0) AS i,
                COALESCE(SUM(CASE WHEN y.kind = 'payment' THEN y.amount ELSE 0 END), 0) AS o,
                COALESCE(SUM(y.cheque_due < CURDATE()), 0) AS od
            FROM biz_payments y WHERE y.user_id = :u AND y.status = 'ok' AND y.cheque_status = 'pending'");
        $st->execute(['u' => $userId]);
        $sum = $st->fetch() ?: ['i' => 0, 'o' => 0, 'od' => 0];

        $w = 'y.user_id = :u AND ' . self::where($filter);
        $st = $pdo->prepare("SELECT COUNT(*) FROM biz_payments y WHERE {$w}");
        $st->execute(['u' => $userId]);
        $n = (int)$st->fetchColumn();
        $size = $cap > 0 ? $cap : self::PAGE_SIZE;
        [$page, $pages, $offset] = $cap > 0 ? [1, 1, 0] : BizCommon::window($n, $page, $size);
        $order = in_array($filter, ['cleared', 'bounced', 'all'], true) ? 'y.cheque_due DESC, y.id DESC' : 'y.cheque_due, y.id';
        $st = $pdo->prepare("SELECT y.*, p.name AS party_name, s.pay_date AS settle_date, b.name AS settle_account
                             " . self::JOIN . " WHERE {$w} ORDER BY {$order} LIMIT :lim OFFSET :off");
        $st->bindValue('u', $userId, PDO::PARAM_INT);
        $st->bindValue('lim', $size, PDO::PARAM_INT);
        $st->bindValue('off', $offset, PDO::PARAM_INT);
        $st->execute();
        return ['rows' => $st->fetchAll(), 'total' => $n, 'page' => $page, 'pages' => $pages,
                'in' => (int)$sum['i'], 'out' => (int)$sum['o'], 'overdue' => (int)$sum['od']];
    }

    /** چک‌های در جریانی که تا `$days` روزِ دیگر (یا گذشته) سررسیدند — برای داشبورد. */
    public static function due(int $userId, int $days = self::DUE_DAYS, int $limit = 6): array
    {
        // ⚠ نبودِ ستون با 42S22 شناخته می‌شود نه `ready()`: داشبورد کوئریِ
        //   `schemaMap()` نمی‌گیرد
        $st = Database::getConnection()->prepare(
            "SELECT y.id, y.kind, y.amount, y.cheque_no, y.cheque_due, p.name AS party_name,
                    DATEDIFF(y.cheque_due, CURDATE()) AS days
             FROM biz_payments y LEFT JOIN biz_parties p ON p.id = y.party_id AND p.user_id = y.user_id
             WHERE y.user_id = :u AND y.status = 'ok' AND y.cheque_status = 'pending'
               AND y.cheque_due <= DATE_ADD(CURDATE(), INTERVAL :d DAY)
             ORDER BY y.cheque_due, y.id LIMIT :lim"
        );
        $st->bindValue('u', $userId, PDO::PARAM_INT);
        $st->bindValue('d', max(0, $days), PDO::PARAM_INT);
        $st->bindValue('lim', $limit, PDO::PARAM_INT);
        try {
            $st->execute();
        } catch (PDOException $e) {
            if ($e->getCode() === '42S22') { return []; }
            throw $e;
        }
        return $st->fetchAll();
    }

    /** برچسبِ کوتاهِ یک چک: «چکِ ۱۲۳۴ بانکِ ملت». */
    public static function label(array $p): string
    {
        $t = 'چک';
        if ((string)($p['cheque_no'] ?? '') !== '') { $t .= ' ' . toPersianDigits((string)$p['cheque_no']); }
        if ((string)($p['cheque_bank'] ?? '') !== '') { $t .= ' — ' . $p['cheque_bank']; }
        return $t;
    }

    private static function lock(PDO $pdo, int $userId, int $id): ?array
    {
        $st = $pdo->prepare('SELECT * FROM biz_payments WHERE id = :id AND user_id = :u AND cheque_status IS NOT NULL FOR UPDATE');
        $st->execute(['id' => $id, 'u' => $userId]);
        return $st->fetch() ?: null;
    }

    /**
     * وصول — دریافتی: صندوقِ چک → بانک؛ پرداختی: بانک → صندوقِ چک.
     * ⛔ از همان `BizPay::createTx()` می‌گذرد (انتقال)، پس موجودیِ هر دو صندوق
     *    از `BALANCE_SQL` می‌آید و نسخه‌ی دومی از «موجودی» ساخته نمی‌شود.
     * @return array{ok:bool, message:string}
     */
    public static function clear(int $userId, int $id, int $bankAcc, string $date = ''): array
    {
        if (!self::ready()) { return ['ok' => false, 'message' => 'دفترِ چک هنوز راه نیفتاده است.']; }
        $pdo = Database::getConnection();
        $pdo->beginTransaction();
        try {
            $p = self::lock($pdo, $userId, $id);
            if (!$p || $p['status'] !== 'ok' || $p['cheque_status'] !== 'pending') {
                $pdo->rollBack();
                return ['ok' => false, 'message' => 'این چک در جریان نیست.'];
            }
            $bk = $pdo->prepare('SELECT kind, is_active FROM biz_accounts WHERE id = :id AND user_id = :u');
            $bk->execute(['id' => $bankAcc, 'u' => $userId]);
            $b = $bk->fetch();
            if (!$b || (int)$b['is_active'] !== 1 || BizCash::isCheque($b)) {
                $pdo->rollBack();
                return ['ok' => false, 'message' => 'حسابی را که چک در آن وصول شد انتخاب کنید.'];
            }
            $in = $p['kind'] === 'receipt';
            $r = BizPay::createTx($pdo, $userId, [
                'kind' => 'transfer', 'amount' => (int)$p['amount'], 'pay_date' => isValidDate($date) ? $date : date('Y-m-d'),
                'account_id' => $in ? (int)$p['account_id'] : $bankAcc, 'to_account_id' => $in ? $bankAcc : (int)$p['account_id'],
                'title' => 'وصولِ ' . self::label($p), '_cheque_settle' => 1,
            ]);
            if (!$r['ok']) { $pdo->rollBack(); return $r; }
            $pdo->prepare("UPDATE biz_payments SET cheque_settle_id = :c WHERE id = :t AND user_id = :u AND kind = 'transfer'")
                ->execute(['c' => $id, 't' => (int)$r['id'], 'u' => $userId]);
            $pdo->prepare("UPDATE biz_payments SET cheque_status = 'cleared' WHERE id = :id AND user_id = :u")
                ->execute(['id' => $id, 'u' => $userId]);
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) { $pdo->rollBack(); }
            throw $e;
        }
        return ['ok' => true, 'message' => self::label($p) . ' وصول شد.'];
    }

    /** برگشتِ وصول (اشتباهِ ثبت) — انتقال باطل، چک دوباره در جریان. */
    public static function unclear(int $userId, int $id): array
    {
        if (!self::ready()) { return ['ok' => false, 'message' => 'دفترِ چک هنوز راه نیفتاده است.']; }
        $pdo = Database::getConnection();
        $pdo->beginTransaction();
        try {
            $p = self::lock($pdo, $userId, $id);
            if (!$p || $p['cheque_status'] !== 'cleared') { $pdo->rollBack(); return ['ok' => false, 'message' => 'این چک وصول‌شده نیست.']; }
            $pdo->prepare("UPDATE biz_payments SET status = 'void', voided_at = NOW()
                           WHERE cheque_settle_id = :id AND user_id = :u AND kind = 'transfer' AND status = 'ok'")
                ->execute(['id' => $id, 'u' => $userId]);
            $pdo->prepare("UPDATE biz_payments SET cheque_status = 'pending' WHERE id = :id AND user_id = :u")
                ->execute(['id' => $id, 'u' => $userId]);
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) { $pdo->rollBack(); }
            throw $e;
        }
        return ['ok' => true, 'message' => 'وصول برگشت خورد؛ چک دوباره در جریان است.'];
    }

    /**
     * برگشتی — خودِ دریافت/پرداخت باطل می‌شود، پس مانده‌ی طرف‌حساب و
     * تسویه‌ی فاکتورها (`reallocateTx()`) به پیش از چک برمی‌گردند.
     */
    public static function bounce(int $userId, int $id): array
    {
        if (!self::ready()) { return ['ok' => false, 'message' => 'دفترِ چک هنوز راه نیفتاده است.']; }
        $pdo = Database::getConnection();
        $pdo->beginTransaction();
        try {
            $p = self::lock($pdo, $userId, $id);
            if (!$p || $p['status'] !== 'ok' || $p['cheque_status'] !== 'pending') {
                $pdo->rollBack();
                return ['ok' => false, 'message' => 'فقط چکِ در جریان برگشت می‌خورد (چکِ وصول‌شده را اول برگردانید).'];
            }
            $pdo->prepare("UPDATE biz_payments SET status = 'void', voided_at = NOW(), cheque_status = 'bounced' WHERE id = :id AND user_id = :u")
                ->execute(['id' => $id, 'u' => $userId]);
            BizPay::reallocateTx($pdo, $userId, $p['party_id'] !== null ? (int)$p['party_id'] : null,
                                 $p['invoice_id'] !== null ? (int)$p['invoice_id'] : null);
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) { $pdo->rollBack(); }
            throw $e;
        }
        return ['ok' => true, 'message' => self::label($p) . ' برگشتی ثبت شد؛ مبلغش دوباره به حسابِ طرف‌حساب برگشت.'];
    }
}

/* =================================================================
   گوشی با IMEI (migration_biz_serials)
   ================================================================= */
final class BizSerial
{
    /** سندهایی که گوشی را وارد انبار می‌کنند؛ دو نوعِ دیگر بیرون می‌برند. */
    public const IN_KINDS = ['purchase', 'sale_return'];

    /** ارقامِ فارسی → لاتین، فاصله و خط‌تیره حذف (IMEIِ روی جعبه با فاصله چاپ می‌شود). */
    public static function norm(string $s): string
    {
        return (string)preg_replace('/[\s\x{200c}\-\/.]+/u', '', toLatinDigits(trim($s)));
    }

    /** IMEI: فقط رقم، ۱۴ تا ۱۷ (۱۵ معمول، ۱۶ برای IMEISV، ۱۴ بی‌رقمِ کنترل). */
    public static function valid(string $imei): bool
    {
        return (bool)preg_match('/^\d{14,17}$/', $imei);
    }

    /**
     * ⛔ تنها جای «این IMEI الان کجاست؟»: آخرین ردیفِ یک سندِ **صادرشده** با
     *    آن شماره (در هر کدام از دو ستون)، به ترتیبِ صدور. پیش‌نویس اثری ندارد
     *    و سندِ باطل خودبه‌خود بیرون است — پس ابطال هیچ کدِ «برگرداندن» نمی‌خواهد.
     *
     * @param string[] $imeis
     * @return array<string,array{dir:string, product_id:?int, imei1:string, imei2:?string, kind:string, invoice_id:int}>
     */
    public static function states(PDO $pdo, int $userId, array $imeis, int $exceptInvoice = 0, bool $lock = false): array
    {
        $imeis = array_values(array_unique(array_filter(array_map('strval', $imeis), fn($x) => $x !== '')));
        if (!$imeis) { return []; }
        $a = []; $b = []; $params = ['u' => $userId, 'x' => $exceptInvoice];
        foreach ($imeis as $n => $v) { $a[] = ':a' . $n; $b[] = ':b' . $n; $params['a' . $n] = $v; $params['b' . $n] = $v; }
        // ⚠ قفلِ اشتراکی: درونِ صدور، خواندنِ عادی عکسِ ابتدای تراکنش را می‌دید
        //    و صدورِ هم‌زمانِ دیگری که همین حالا کامیت شده پنهان می‌ماند.
        $st = $pdo->prepare(
            "SELECT l.imei1, l.imei2, l.product_id, i.kind, i.id AS invoice_id
             FROM biz_invoice_lines l JOIN biz_invoices i ON i.id = l.invoice_id AND i.user_id = l.user_id
             WHERE l.user_id = :u AND i.status = 'issued' AND i.id <> :x
               AND (l.imei1 IN (" . implode(',', $a) . ') OR l.imei2 IN (' . implode(',', $b) . '))
             ORDER BY i.issued_at, i.id, l.id' . ($lock ? ' LOCK IN SHARE MODE' : '')
        );
        $st->execute($params);
        $out = [];
        foreach ($st->fetchAll() as $r) {
            $row = ['dir' => in_array($r['kind'], self::IN_KINDS, true) ? 'in' : 'out',
                    'product_id' => $r['product_id'] !== null ? (int)$r['product_id'] : null,
                    'imei1' => (string)$r['imei1'], 'imei2' => $r['imei2'] !== null ? (string)$r['imei2'] : null,
                    'kind' => (string)$r['kind'], 'invoice_id' => (int)$r['invoice_id']];
            foreach ([$r['imei1'], $r['imei2']] as $v) {
                if ($v !== null && in_array((string)$v, $imeis, true)) { $out[(string)$v] = $row; }
            }
        }
        return $out;
    }

    /** یک IMEI → آخرین ردیفِ صادرشده‌اش (برای جست‌وجوی هوشمندِ فاکتور). */
    public static function lookup(int $userId, string $imei): ?array
    {
        $imei = self::norm($imei);
        if (!self::valid($imei)) { return null; }
        return self::states(Database::getConnection(), $userId, [$imei])[$imei] ?? null;
    }

    /**
     * ⛔ سدِ صدور — داخلِ تراکنشِ `issueTx`. ورود (خرید، برگشت از فروش):
     *    گوشیِ همان IMEI نباید همین حالا در انبار باشد. خروج (فروش، برگشت
     *    از خرید): نباید از قبل بیرون رفته باشد، و اگر در انبار است باید
     *    همان کالا باشد. IMEIِ دیده‌نشده در خروج پذیرفته است: گوشی‌ای که
     *    پیش از این قابلیت (موجودیِ اول دوره) وارد شده IMEIِ ثبت‌شده ندارد.
     *
     * @param array $lines ردیف‌های سند (`product_id`, `imei1`, `imei2`)
     */
    public static function check(PDO $pdo, int $userId, string $kind, int $invoiceId, array $lines): ?string
    {
        $nums = [];
        foreach ($lines as $l) {
            foreach (['imei1', 'imei2'] as $c) { if (!empty($l[$c])) { $nums[] = (string)$l[$c]; } }
        }
        if (!$nums) { return null; }
        $states = self::states($pdo, $userId, $nums, $invoiceId, true);
        $in = in_array($kind, self::IN_KINDS, true);
        foreach ($lines as $l) {
            foreach (['imei1', 'imei2'] as $c) {
                $v = (string)($l[$c] ?? '');
                if ($v === '' || !isset($states[$v])) { continue; }
                $s = $states[$v];
                if ($in && $s['dir'] === 'in') {
                    return 'گوشی با IMEI ' . $v . ' همین حالا در انبار است؛ یک گوشی دو بار وارد نمی‌شود.';
                }
                if (!$in && $s['dir'] === 'out') {
                    return 'گوشی با IMEI ' . $v . ' در انبار نیست؛ پیش‌تر فروخته یا برگشت داده شده است.';
                }
                if (!$in && $s['product_id'] !== null && (int)($l['product_id'] ?? 0) !== $s['product_id']) {
                    return 'IMEI ' . $v . ' مالِ کالای دیگری است؛ ردیفِ «' . ($l['description'] ?? '') . '» را درست کنید.';
                }
            }
        }
        return null;
    }

    /** ⛔ تنها مرجعِ صافیِ گزارشِ IMEI (صفحه و چاپ). */
    public const REPORT_FILTERS = ['' => 'همه', 'in' => 'در انبار', 'out' => 'فروخته / بیرون رفته'];

    /**
     * گزارشِ شماره سریال — سرگذشتِ هر گوشی: اولین ورود (سند، طرف، بها) و
     * آخرین رخداد. «کجاست» با همان قاعده‌ی `inStock()` تا می‌شود (آخرین
     * سندِ صادرشده، دو شماره یک کلید)؛ این تابع فقط ستون‌های نمایش را اضافه
     * می‌کند و تصمیمِ دومی نمی‌سازد.
     *
     * @return array{rows:array, in:int, out:int, capped:bool}
     */
    public static function report(int $userId, string $filter = '', int $productId = 0, int $cap = 2000): array
    {
        $sql = "SELECT l.imei1, l.imei2, l.product_id, l.unit_price, i.kind, i.id AS invoice_id, i.number, i.inv_date,
                       COALESCE(p.name, l.description) AS product_name, pa.name AS party_name
                FROM biz_invoice_lines l
                JOIN biz_invoices i ON i.id = l.invoice_id AND i.user_id = l.user_id
                LEFT JOIN biz_products p FORCE INDEX (PRIMARY) ON p.id = l.product_id AND p.user_id = l.user_id
                LEFT JOIN biz_parties pa ON pa.id = i.party_id AND pa.user_id = i.user_id
                WHERE l.user_id = :u AND i.status = 'issued' AND l.imei1 IS NOT NULL"
             . ($productId > 0 ? ' AND l.product_id = :p' : '') . ' ORDER BY i.issued_at, i.id, l.id';
        $st = Database::getConnection()->prepare($sql);
        $st->execute($productId > 0 ? ['u' => $userId, 'p' => $productId] : ['u' => $userId]);
        $alias = []; $units = [];
        foreach ($st->fetchAll() as $r) {
            $n1 = (string)$r['imei1']; $n2 = $r['imei2'] !== null ? (string)$r['imei2'] : null;
            $key = $alias[$n1] ?? ($n2 !== null ? ($alias[$n2] ?? null) : null) ?? $n1;
            $alias[$n1] = $key;
            if ($n2 !== null) { $alias[$n2] = $key; }
            $ev = ['kind' => (string)$r['kind'], 'invoice_id' => (int)$r['invoice_id'], 'number' => $r['number'],
                   'date' => (string)$r['inv_date'], 'party' => $r['party_name'] !== null ? (string)$r['party_name'] : 'گذری',
                   'price' => (int)$r['unit_price']];
            if (!isset($units[$key])) {
                $units[$key] = ['imei1' => $n1, 'imei2' => $n2, 'product_id' => $r['product_id'] !== null ? (int)$r['product_id'] : null,
                                'product' => (string)$r['product_name'], 'first' => $ev];
            }
            $units[$key]['last']  = $ev;
            $units[$key]['state'] = in_array($r['kind'], self::IN_KINDS, true) ? 'in' : 'out';
        }
        $rows = []; $in = 0; $out = 0;
        foreach ($units as $u) {
            $u['state'] === 'in' ? $in++ : $out++;
            if ($filter !== '' && $u['state'] !== $filter) { continue; }
            $rows[] = $u;
        }
        return ['rows' => array_slice($rows, 0, $cap), 'in' => $in, 'out' => $out, 'capped' => count($rows) > $cap];
    }

    /**
     * گوشی‌های همین حالا در انبار — برای جست‌وجوی فاکتورِ فروش و صفحه‌ی کالا.
     * وضعیت از حرکت‌ها «تا» می‌شود؛ دو شماره‌ی یک گوشی یک کلید دارند (اگر
     * جایی IMEI ۱ و ۲ جابه‌جا نوشته شده باشند، باز همان گوشی است).
     *
     * @return array{rows:array<int,array{imei1:string, imei2:?string, product_id:int}>, capped:bool}
     */
    public static function inStock(int $userId, int $productId = 0, int $cap = 2000): array
    {
        $sql = "SELECT l.imei1, l.imei2, l.product_id, i.kind
                FROM biz_invoice_lines l JOIN biz_invoices i ON i.id = l.invoice_id AND i.user_id = l.user_id
                WHERE l.user_id = :u AND i.status = 'issued' AND l.imei1 IS NOT NULL AND l.product_id IS NOT NULL"
             . ($productId > 0 ? ' AND l.product_id = :p' : '') . ' ORDER BY i.issued_at, i.id, l.id';
        $st = Database::getConnection()->prepare($sql);
        $st->execute($productId > 0 ? ['u' => $userId, 'p' => $productId] : ['u' => $userId]);
        $alias = []; $units = [];
        foreach ($st->fetchAll() as $r) {
            $n1 = (string)$r['imei1']; $n2 = $r['imei2'] !== null ? (string)$r['imei2'] : null;
            $key = $alias[$n1] ?? ($n2 !== null ? ($alias[$n2] ?? null) : null) ?? $n1;
            $alias[$n1] = $key;
            if ($n2 !== null) { $alias[$n2] = $key; }
            $units[$key] = ['imei1' => $n1, 'imei2' => $n2, 'product_id' => (int)$r['product_id'],
                            'in' => in_array($r['kind'], self::IN_KINDS, true)];
        }
        $rows = [];
        foreach ($units as $u) {
            if ($u['in']) { unset($u['in']); $rows[] = $u; }
        }
        return ['rows' => array_slice($rows, 0, $cap), 'capped' => count($rows) > $cap];
    }
}
