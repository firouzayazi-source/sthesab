<?php
/**
 * ⛔ «تأمینِ موجودی» — خریدِ سریعِ یک کالا از دلِ فاکتورِ فروش یا صفحه‌ی کالا.
 *
 * **خواسته‌ی مالکِ نصب (مهر ۱۴۰۵):** «اگر کالایی موجودی نداره، همون‌جا توی
 * فاکتور بتونم موجودی بهش بدم … بگه این کالا رو از چه کسی خریدم که بدهکارش
 * بشم، به چه قیمتی خریدم، به چه قیمتی می‌فروشم … و وقتی برگشتم تمامِ دیتای
 * قبلی سرِ جاش باشه … که اگر سرم شلوغ شد راحت به کالا موجودی بدم.»
 *
 * ⛔ این **انبارگردانی نیست**، یک فاکتورِ خریدِ واقعی است. موجودیِ «از هیچ»
 *    (`BizStock::adjustTo()`) بهای خرید ندارد و هیچ‌کس طلبکار نمی‌شود — سودِ
 *    آن فروش دروغ می‌شد و بدهیِ واقعی به فروشنده هیچ‌جا دیده نمی‌شد. پس همان
 *    راهِ همیشگی: `BizInvoices::saveDraft()` و بعد `issue()` — شماره، موجودی،
 *    بهای میانگین، مانده‌ی فروشنده و (اگر نقد) پرداخت، همه از همان کدی که
 *    فاکتورِ خریدِ دستی می‌گذرد. هیچ نسخه‌ی دومی از منطقِ خرید اینجا نیست.
 *
 * ⛔ «نسیه» پیش‌فرض است: فروشنده طلبکار می‌شود (خواسته‌ی صریح). «نقد» یعنی
 *    همان «پرداختِ کامل» از یک صندوق. فروشنده‌ی گذری فقط با نقد (قاعده‌ی خودِ
 *    `issue()`).
 *
 * ⚠ صدور شکست بخورد (دوره‌ی بسته، IMEIِ تکراری، …) ⇒ پیش‌نویسِ بی‌شماره پاک
 *   می‌شود. پیش‌نویسِ یتیمِ «تأمینِ موجودی» در فهرستِ خرید فقط گیج می‌کرد.
 */

require_once __DIR__ . '/biz_docs.php';
require_once __DIR__ . '/biz_docview.php';

final class BizQuickBuy
{
    /** توضیحِ فاکتورِ خریدِ ساخته‌شده — تا در فهرستِ خرید معلوم باشد از کجا آمده. */
    public const NOTE = 'تأمینِ موجودی (خریدِ سریع)';

    /** سقفِ دستگاه در یک تأمین — همان سقفِ ردیفِ فاکتور. */
    public const MAX_UNITS = 50;

    /**
     * IMEIهای یک متنِ چندخطی: هر خط یک گوشی، «IMEI۱» یا «IMEI۱ / IMEI۲».
     * @return list<array{0:string,1:string}>
     */
    public static function parseImeis(string $text): array
    {
        $out = [];
        foreach (preg_split('/\R/u', $text) ?: [] as $ln) {
            $ln = trim(toLatinDigits($ln));
            if ($ln === '') { continue; }
            $parts = preg_split('~\s*[/,،|]\s*|\s+~u', $ln) ?: [];
            $parts = array_values(array_filter(array_map(fn($p) => BizSerial::norm((string)$p), $parts), fn($p) => $p !== ''));
            $out[] = [(string)($parts[0] ?? ''), (string)($parts[1] ?? '')];
        }
        return $out;
    }

    /**
     * آخرین فروشنده‌ی همین کالا (فاکتورِ خریدِ صادرشده) — پیش‌فرضِ پنل، تا
     * خریدِ تکراری از یک نفر یک تپ باشد.
     */
    public static function lastSupplier(int $userId, int $productId): int
    {
        $st = Database::getConnection()->prepare(
            "SELECT i.party_id FROM biz_invoice_lines l
               JOIN biz_invoices i ON i.id = l.invoice_id AND i.user_id = l.user_id
              WHERE l.user_id = :u AND l.product_id = :p AND i.kind = 'purchase' AND i.status = 'issued'
                AND i.party_id IS NOT NULL
              ORDER BY i.inv_date DESC, i.id DESC LIMIT 1"
        );
        $st->execute(['u' => $userId, 'p' => $productId]);
        return (int)($st->fetchColumn() ?: 0);
    }

    /**
     * طرف‌حساب‌ها برای انتخابِ فروشنده: تأمین‌کننده‌ها اول، بعد بقیه (کسی که
     * «مشتری» ثبت شده هم ممکن است جنس بیاورد). بی‌مانده — منوی ساده است.
     * @return list<array{id:int,name:string,supplier:bool}>
     */
    public static function suppliers(int $userId): array
    {
        $rows = [];
        foreach (BizParties::all($userId, '', 2000, false)['rows'] as $p) {
            if ((int)($p['is_active'] ?? 1) !== 1 || ($p['kind'] ?? '') === 'employee') { continue; }
            $rows[] = ['id' => (int)$p['id'], 'name' => (string)$p['name'],
                       'supplier' => in_array((string)$p['kind'], ['supplier', 'both'], true)];
        }
        usort($rows, fn($a, $b) => [$b['supplier'], $a['name']] <=> [$a['supplier'], $b['name']]);
        return $rows;
    }

    /**
     * «از چه کسی خریدم» — خریدهای صادرشده‌ی همین کالا، تازه‌ترین اول.
     * @return list<array{invoice_id:int, title:string, party:string, date:string, qty:float, price:int}>
     */
    public static function history(int $userId, int $productId, int $limit = 10): array
    {
        $st = Database::getConnection()->prepare(
            "SELECT i.id, i.kind, i.number, i.inv_date, p.name AS party, l.qty, l.unit_price
               FROM biz_invoice_lines l
               JOIN biz_invoices i ON i.id = l.invoice_id AND i.user_id = l.user_id
               LEFT JOIN biz_parties p ON p.id = i.party_id AND p.user_id = i.user_id
              WHERE l.user_id = :u AND l.product_id = :p AND i.kind = 'purchase' AND i.status = 'issued'
              ORDER BY i.inv_date DESC, i.id DESC, l.line_no LIMIT " . max(1, min(50, $limit))
        );
        $st->execute(['u' => $userId, 'p' => $productId]);
        return array_map(fn($r) => ['invoice_id' => (int)$r['id'], 'title' => BizInvoices::title($r),
            'party' => $r['party'] !== null ? (string)$r['party'] : 'فروشنده‌ی گذری', 'date' => (string)$r['inv_date'],
            'qty' => (float)$r['qty'], 'price' => (int)$r['unit_price']], $st->fetchAll());
    }

    /**
     * ⛔ «خرید خورده، موجودی نخورده» — دو جای واقعی که پول/طلبِ فروشنده ثبت شده ولی
     *    انبار نه، تا به‌جای خریدِ **دوم** فقط موجودی برسد:
     *
     *    - `drafts`: پیش‌نویسِ خریدی که همین کالا را دارد و هنوز صادر نشده.
     *    - `loose`: ردیفِ **شرحِ آزادِ** فاکتورِ خریدِ صادرشده (به هیچ کالایی وصل نیست —
     *      مبلغش در سود «خریدِ بی‌انبار» و طلبِ فروشنده هست، ولی موجودی هرگز نیامد).
     *      نامِ هم‌ارز (`fold()`) اول، بعد تازه‌ترین‌ها؛ وصل کردن تصمیمِ کاربر است.
     *
     * ⚠ گوشی (`has_serial`) اینجا ردیفِ آزاد نمی‌گیرد: بی‌IMEI نمی‌شود به انبار برد.
     * @return array{drafts:list<array>, loose:list<array>}
     */
    public static function pending(int $userId, int $productId, ?array $prod = null): array
    {
        // ⚠ ردیفِ کالا اگر صفحه از قبل دارد (صفحه‌ی کالا) — یک کوئری کمتر
        $prod = $prod !== null && (int)($prod['id'] ?? 0) === $productId ? $prod : BizProducts::get($userId, $productId);
        if (!$prod) { return ['drafts' => [], 'loose' => []]; }
        $pdo = Database::getConnection();
        $st = $pdo->prepare(
            "SELECT i.id, i.kind, i.number, i.inv_date, p.name AS party, SUM(l.qty) AS qty
               FROM biz_invoices i
               JOIN biz_invoice_lines l ON l.invoice_id = i.id AND l.user_id = i.user_id AND l.product_id = :p
               LEFT JOIN biz_parties p ON p.id = i.party_id AND p.user_id = i.user_id
              WHERE i.user_id = :u AND i.kind = 'purchase' AND i.status = 'draft'
              GROUP BY i.id, i.kind, i.number, i.inv_date, p.name ORDER BY i.id DESC LIMIT 5"
        );
        $st->execute(['u' => $userId, 'p' => $productId]);
        $drafts = array_map(fn($r) => ['invoice_id' => (int)$r['id'], 'title' => BizInvoices::title($r),
            'party' => $r['party'] !== null ? (string)$r['party'] : 'فروشنده‌ی گذری', 'date' => (string)$r['inv_date'],
            'qty' => (float)$r['qty']], $st->fetchAll());

        $loose = [];
        if ((int)$prod['has_serial'] !== 1) {
            $st = $pdo->prepare(
                "SELECT l.id, l.description, l.qty, l.unit_price, i.id AS invoice_id, i.kind, i.number, i.inv_date, p.name AS party
                   FROM biz_invoice_lines l
                   JOIN biz_invoices i ON i.id = l.invoice_id AND i.user_id = l.user_id
                   LEFT JOIN biz_parties p ON p.id = i.party_id AND p.user_id = i.user_id
                  WHERE l.user_id = :u AND l.product_id IS NULL AND i.kind = 'purchase' AND i.status = 'issued'
                  ORDER BY i.inv_date DESC, l.id DESC LIMIT 200"
            );
            $st->execute(['u' => $userId]);
            $want = BizCommon::fold((string)$prod['name']);
            foreach ($st->fetchAll() as $r) {
                $loose[] = ['line_id' => (int)$r['id'], 'invoice_id' => (int)$r['invoice_id'], 'title' => BizInvoices::title($r),
                    'party' => $r['party'] !== null ? (string)$r['party'] : 'فروشنده‌ی گذری', 'date' => (string)$r['inv_date'],
                    'desc' => (string)$r['description'], 'qty' => (float)$r['qty'], 'price' => (int)$r['unit_price'],
                    'same' => BizCommon::fold((string)$r['description']) === $want];
            }
            usort($loose, fn($a, $b) => [$b['same'], $b['date'], $b['line_id']] <=> [$a['same'], $a['date'], $a['line_id']]);
            $loose = array_slice($loose, 0, 8);
        }
        return ['drafts' => $drafts, 'loose' => $loose];
    }

    /**
     * ⛔ «فقط موجودی بده»: ردیفِ آزادِ یک فاکتورِ خریدِ صادرشده به کالا وصل می‌شود.
     *
     * هیچ پول، طلب یا سندِ تازه‌ای ساخته نمی‌شود — مبلغ و فروشنده از قبل ثبت‌اند؛ فقط
     * همان ردیف از «خریدِ بی‌انبار» به انبار می‌رود: حرکتِ انبار با بهای همان ردیف
     * (`net_total / qty`، همان قاعده‌ی `issueTx()`) از راهِ `BizStock::postDoc()`، و
     * `recalc()` بهای فروش‌های بعد از آن را درست می‌کند.
     *
     * رد می‌شود اگر: دوره‌ی بسته، ردیف وصل است، سند صادرشده‌ی خرید نیست، کالا خدمت یا
     * گوشی است، تعداد با واحد نمی‌خواند، یا برگشتی به این ردیف اشاره دارد.
     */
    public static function linkLine(int $userId, int $lineId, int $productId): array
    {
        $pdo = Database::getConnection();
        $pdo->beginTransaction();
        Biz::lockShop($pdo, $userId);
        try {
            $st = $pdo->prepare(
                "SELECT l.*, i.kind, i.status, i.inv_date, i.number FROM biz_invoice_lines l
                   JOIN biz_invoices i ON i.id = l.invoice_id AND i.user_id = l.user_id
                  WHERE l.id = :id AND l.user_id = :u FOR UPDATE"
            );
            $st->execute(['id' => $lineId, 'u' => $userId]);
            $l = $st->fetch();
            $prod = BizProducts::get($userId, $productId);
            $err = null;
            if (!$l || $l['kind'] !== 'purchase' || $l['status'] !== 'issued') { $err = 'ردیفِ فاکتورِ خرید پیدا نشد.'; }
            elseif ($l['product_id'] !== null) { $err = 'این ردیف از قبل به کالایی وصل است.'; }
            elseif (!$prod) { $err = 'کالا پیدا نشد.'; }
            elseif ((int)$prod['track_stock'] !== 1) { $err = '«' . $prod['name'] . '» خدمت است و موجودی ندارد.'; }
            elseif ((int)$prod['has_serial'] === 1) { $err = 'گوشی بی‌IMEI به انبار نمی‌رود؛ فاکتورِ خرید را اصلاح کنید و IMEI را بنویسید.'; }
            elseif ((float)$l['qty'] <= 0 || !BizProducts::qtyFits((string)$prod['unit'], (float)$l['qty'])) {
                $err = 'تعدادِ این ردیف برای واحدِ «' . $prod['unit'] . '» نمی‌خواند.';
            }
            elseif (($e = Biz::lockError($userId, (string)$l['inv_date'], 'فاکتورِ خرید')) !== null) { $err = $e; }
            if ($err === null) {
                $rf = $pdo->prepare("SELECT COUNT(*) FROM biz_invoice_lines r JOIN biz_invoices ri ON ri.id = r.invoice_id AND ri.user_id = r.user_id
                                      WHERE r.user_id = :u AND r.ref_line_id = :l AND ri.status <> 'void'");
                $rf->execute(['u' => $userId, 'l' => $lineId]);
                if ((int)$rf->fetchColumn() > 0) { $err = 'برای این ردیف برگشت از خرید ثبت شده؛ اول آن را باطل کنید.'; }
            }
            if ($err !== null) { $pdo->rollBack(); return ['ok' => false, 'message' => $err]; }

            $q    = (float)$l['qty'];
            $cost = (int)round((int)$l['net_total'] / $q);   // سهمِ تخفیف/حملِ کلِ فاکتور هم در آن است
            $pdo->prepare('UPDATE biz_invoice_lines SET product_id = :p, unit = :un, unit_cost = :c WHERE id = :id AND user_id = :u')
                ->execute(['p' => $productId, 'un' => (string)$prod['unit'], 'c' => $cost, 'id' => $lineId, 'u' => $userId]);

            // حرکت‌های همین سند برای این کالا — همه‌ی ردیف‌هایش، نه فقط این یکی (`postDoc()` آن‌ها را جایگزین می‌کند)
            $all = $pdo->prepare('SELECT qty, net_total FROM biz_invoice_lines WHERE invoice_id = :i AND user_id = :u AND product_id = :p');
            $all->execute(['i' => (int)$l['invoice_id'], 'u' => $userId, 'p' => $productId]);
            $moves = [];
            foreach ($all->fetchAll() as $m) {
                $mq = (float)$m['qty'];
                $moves[$productId][] = ['qty' => $mq, 'unit_cost' => $mq > 0 ? (int)round((int)$m['net_total'] / $mq) : 0,
                                        'date' => (string)$l['inv_date']];
            }
            $post = BizStock::postDoc($userId, 'purchase', (int)$l['invoice_id'], $moves);
            if (!$post['ok']) { $pdo->rollBack(); return ['ok' => false, 'message' => $post['message']]; }
            BizLog::add($pdo, $userId, 'invoice', (int)$l['invoice_id'], 'stock_link', null);
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) { $pdo->rollBack(); }
            throw $e;
        }
        $after = BizProducts::get($userId, $productId);
        return ['ok' => true, 'invoice_id' => (int)$l['invoice_id'], 'stock' => (float)$after['stock_qty'],
            'message' => 'ردیفِ «' . $l['description'] . '» از ' . BizInvoices::title($l) . ' به «' . $prod['name'] . '» وصل شد — '
                . formatQty($q) . ' ' . $prod['unit'] . ' به انبار آمد، بی‌خرید و بی‌طلبِ تازه. موجودیِ تازه: '
                . formatQty((float)$after['stock_qty']) . ' ' . $prod['unit'] . '.'];
    }

    /**
     * پیش‌نویسِ خریدی که همین کالا را دارد: صدورش موجودی را می‌آورد (نسیه — همان
     * `issue()`؛ فروشنده‌ی گذری را `issue()` خودش رد می‌کند).
     */
    public static function issueDraft(int $userId, int $invoiceId): array
    {
        $inv = BizInvoices::get($userId, $invoiceId);
        if (!$inv || $inv['kind'] !== 'purchase' || $inv['status'] !== 'draft') {
            return ['ok' => false, 'message' => 'پیش‌نویسِ خرید پیدا نشد.'];
        }
        $r = BizInvoices::issue($userId, $invoiceId, ['amount' => '0', 'full' => false, 'part' => false]);
        if (!$r['ok']) { return $r; }
        return ['ok' => true, 'invoice_id' => $invoiceId, 'message' => 'پیش‌نویسِ خرید صادر شد و موجودی‌اش به انبار آمد (نسیه — به حسابِ فروشنده).'];
    }

    /**
     * خرید و صدور.
     *
     * @param array{product_id?:int, party_id?:int, party_name?:string, qty?:string, buy?:string, sell?:string,
     *              date?:string, pay?:string, account_id?:int, imeis?:string} $in  `date` میلادی (Y-m-d)
     * @return array{ok:bool, message:string, invoice_id?:int, stock?:float, sell?:int, party_id?:int}
     */
    public static function run(int $userId, array $in): array
    {
        $prod = BizProducts::get($userId, (int)($in['product_id'] ?? 0));
        if (!$prod) { return ['ok' => false, 'message' => 'کالا پیدا نشد.']; }
        if ((int)$prod['track_stock'] !== 1) {
            return ['ok' => false, 'message' => '«' . $prod['name'] . '» خدمت است و موجودی ندارد.'];
        }
        $serial = (int)$prod['has_serial'] === 1;
        $name   = (string)$prod['name'];

        $buyIn = trim((string)($in['buy'] ?? ''));
        $buy   = $buyIn === '' ? (int)$prod['buy_price'] : sanitizeAmount($buyIn);
        if ($buy <= 0) { return ['ok' => false, 'message' => 'قیمتِ خریدِ هر واحد را بنویسید.']; }
        if (($e = BizCommon::moneyError($buy, 'قیمتِ خرید')) !== null) { return ['ok' => false, 'message' => $e]; }
        $sellIn = trim((string)($in['sell'] ?? ''));
        $sell   = $sellIn === '' ? null : sanitizeAmount($sellIn);
        if ($sell !== null && ($e = BizCommon::moneyError($sell, 'قیمتِ فروش')) !== null) { return ['ok' => false, 'message' => $e]; }

        // ردیف‌ها — گوشی: هر دستگاه یک ردیف با IMEIِ خودش (همان قاعده‌ی `parseLines()`)
        $lines = [];
        if ($serial) {
            $units = self::parseImeis((string)($in['imeis'] ?? ''));
            if (!$units) { return ['ok' => false, 'message' => 'برای گوشیِ «' . $name . '» IMEIِ هر دستگاه را بنویسید (هر خط یک گوشی).']; }
            if (count($units) > self::MAX_UNITS) { return ['ok' => false, 'message' => 'حداکثر ' . self::MAX_UNITS . ' دستگاه در یک تأمین.']; }
            foreach ($units as [$i1, $i2]) {
                $lines[] = ['item' => $name, 'product_id' => (int)$prod['id'], 'qty' => '1', 'price' => (string)$buy,
                            'imei1' => $i1, 'imei2' => $i2];
            }
            $qty = (float)count($units);
        } else {
            $qty = sanitizeQty((string)($in['qty'] ?? ''));
            if ($qty <= 0) { return ['ok' => false, 'message' => 'تعدادِ خریدشده را بنویسید.']; }
            // ⛔ سقف پیش از `(string)$qty`: عددِ ≥۱e15 شکلِ نمایی می‌گرفت («1.0E+19») و
            //    `parseLines` آن را ۱٫۰۱۹ می‌خواند — پیام «ده میلیارد میلیارد خریده شد»،
            //    موجودی ۱٫۰۱۹ (بازرسیِ محاسباتی، مهر ۱۴۰۵).
            if ($err = BizCommon::qtyError($qty, 'تعداد')) { return ['ok' => false, 'message' => $err]; }
            $lines[] = ['item' => $name, 'product_id' => (int)$prod['id'], 'qty' => (string)$qty, 'price' => (string)$buy];
        }

        // فروشنده — انتخاب‌شده، یا نامِ تازه (اگر همین نام هست، همان؛ تکراری ساخته نمی‌شود)
        $partyId = (int)($in['party_id'] ?? 0);
        $newName = BizCommon::persian(BizCommon::line((string)($in['party_name'] ?? '')));
        if ($partyId > 0 && !BizParties::get($userId, $partyId)) { return ['ok' => false, 'message' => 'فروشنده پیدا نشد.']; }
        $cash = ($in['pay'] ?? 'credit') === 'cash';
        if ($partyId === 0 && $newName === '' && !$cash) {
            return ['ok' => false, 'message' => 'از چه کسی خریدید؟ فروشنده را انتخاب کنید یا نامش را بنویسید (برای خریدِ نقدی از فروشنده‌ی گذری «نقد» را بزنید).'];
        }
        $date = (string)($in['date'] ?? '');
        if (!isValidDate($date)) { return ['ok' => false, 'message' => 'تاریخِ خرید معتبر نیست.']; }
        // ⛔ دوره‌ی بسته پیش از ساختنِ فروشنده — وگرنه شخصِ تازه برای خریدی که انجام نشد می‌ماند
        if (($e = Biz::lockError($userId, $date, 'فاکتورِ خرید')) !== null) { return ['ok' => false, 'message' => $e]; }
        $accountId = (int)($in['account_id'] ?? 0);
        if ($cash && $accountId <= 0) { return ['ok' => false, 'message' => 'صندوقی که از آن پرداخت کردید را انتخاب کنید.']; }

        // پیش‌سنجِ ردیف‌ها (IMEI، تعداد، سقف) پیش از ساختنِ فروشنده — همان تابعِ ذخیره
        $pre = BizInvoices::parseLines($userId, $lines, 'buy_price');
        if ($pre['errors']) { return ['ok' => false, 'message' => implode(' ', array_values($pre['errors']))]; }

        if ($partyId === 0 && $newName !== '') {
            foreach (BizParties::all($userId, '', 3000, false)['rows'] as $p) {
                if (BizCommon::fold((string)$p['name']) === BizCommon::fold($newName)) { $partyId = (int)$p['id']; break; }
            }
            if ($partyId === 0) {
                $pr = BizParties::save($userId, ['name' => $newName, 'kind' => 'supplier']);
                if (!$pr['ok']) { return ['ok' => false, 'message' => $pr['message']]; }
                $partyId = (int)$pr['id'];
            }
        }

        $draft = BizInvoices::saveDraft($userId, 'purchase', [
            'party_id' => $partyId, 'inv_date' => $date, 'lines' => $lines, 'note' => self::NOTE,
        ]);
        if (!$draft['ok']) { return ['ok' => false, 'message' => $draft['message']]; }
        $invId = (int)$draft['id'];
        $r = BizInvoices::issue($userId, $invId, [
            'account_id' => $accountId, 'method' => 'cash', 'full' => $cash, 'amount' => '0', 'part' => false,
        ]);
        if (!$r['ok']) {
            BizInvoices::deleteDraft($userId, $invId);
            return ['ok' => false, 'message' => $r['message']];
        }

        // قیمتِ فروش: فقط اگر عوض شده، و هرگز روی کالای وصل به نرخِ روز
        // (`BizRates::apply()` تنها نویسنده‌ی آن‌هاست — قاعده ۷۰).
        $sellNow = (int)$prod['sell_price'];
        if ($sell !== null && $sell !== $sellNow && trim((string)($prod['rate_code'] ?? '')) === '') {
            Database::getConnection()->prepare('UPDATE biz_products SET sell_price = :s WHERE id = :id AND user_id = :u')
                ->execute(['s' => $sell, 'id' => (int)$prod['id'], 'u' => $userId]);
            $sellNow = $sell;
        }

        $after = BizProducts::get($userId, (int)$prod['id']);
        $inv   = BizInvoices::get($userId, $invId);
        $stock = (float)($after['stock_qty'] ?? 0);
        return [
            'ok' => true, 'invoice_id' => $invId, 'stock' => $stock, 'sell' => $sellNow, 'party_id' => $partyId,
            'message' => formatQty($qty) . ' ' . $prod['unit'] . ' «' . $name . '» خریده شد — '
                . ($inv ? BizInvoices::title($inv) : 'فاکتورِ خرید') . ' به مبلغِ ' . formatMoney((int)($inv['total'] ?? 0)) . ' تومان'
                . ($cash ? ' (پرداخت شد)' : ' (نسیه — به حسابِ فروشنده)')
                . '. موجودیِ تازه: ' . formatQty($stock) . ' ' . $prod['unit'] . '.',
        ];
    }
}
