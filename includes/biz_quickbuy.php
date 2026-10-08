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
