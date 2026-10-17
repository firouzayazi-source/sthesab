<?php
/**
 * ⛔ کالای خواب و ریسکِ افتِ قیمت (`store/aging.php`، کارتِ داشبورد).
 *
 * **خواسته‌ی مالکِ نصب (مهر ۱۴۰۵):** گوشی هر هفته ارزان‌تر می‌شود؛ صاحبِ مغازه باید ببیند کدام کالا چند
 * روز است در انبار مانده، چقدر پول در آن خوابیده و کدام بالاتر از قیمتِ امروز خریده شده.
 *
 * ⛔ هیچ چیز ذخیره نمی‌شود — هر بار از سند و حرکت‌ها:
 *    - **گوشیِ IMEIدار:** سنِ هر دستگاه از آخرین ورودش (خرید یا برگشت از فروش) با همان قاعده‌ی
 *      دستگاه‌محورِ `BizSerial::inStock()` (دو IMEIِ یک گوشی یک دستگاه‌اند)، بها = خالصِ همان ردیف.
 *    - **بقیه:** FIFO — آن‌چه مانده، تازه‌ترین ورودی‌هاست (قدیمی‌ها زودتر فروخته شده‌اند)؛ بهای ورودیِ
 *      بی‌بها = میانگینِ کالا. سهمِ بی‌ورودی (داده‌ی ناقص) سنِ نامعلوم می‌گیرد، نه صفر.
 *    - **ریسکِ افت** = بهای خریدِ مانده − قیمتِ خریدِ امروزِ کالا (`buy_price`) — فقط وقتی مثبت و قیمتِ
 *      امروز ثبت شده؛ هرگز حدس. «زیرِ قیمتِ فروش» = قیمتِ فروشِ امروز کمتر از بهای واحد.
 * ⛔ مبلغ‌ها فقط برای دارنده‌ی «بها و سود» (صفحه با `cost` باز می‌شود؛ کارتِ داشبورد هم همان را می‌پرسد).
 */

require_once __DIR__ . '/biz_docs.php';

final class BizAging
{
    /** مرزِ دسته‌ها (روز) — تنها مرجع. */
    public const BUCKETS = [30, 60, 90];
    public const LABELS = ['۰ تا ۳۰ روز', '۳۱ تا ۶۰ روز', '۶۱ تا ۹۰ روز', 'بیش از ۹۰ روز'];
    /** از این سن «خواب» است (کارتِ داشبورد). */
    public const IDLE_DAYS = 60;

    public static function bucket(?int $age): int
    {
        if ($age === null) { return count(self::BUCKETS); }
        foreach (self::BUCKETS as $i => $b) { if ($age <= $b) { return $i; } }
        return count(self::BUCKETS);
    }

    /**
     * هر کالای موجود با تکه‌های سن‌دارش.
     * @return list<array{id:int, name:string, unit:string, serial:bool, qty:float, value:int, oldest:?int,
     *                    buckets:list<float>, risk:int, below_sell:bool, buy_price:int, sell_price:int,
     *                    lots:list<array{qty:float, cost:float, age:?int, imei:?string}>}>
     */
    public static function rows(int $userId, ?string $today = null): array
    {
        $today = $today ?? today();
        $t0 = strtotime($today);
        $pdo = Database::getConnection();
        $st = $pdo->prepare('SELECT id, name, unit, has_serial, stock_qty, avg_cost, buy_price, sell_price FROM biz_products
                             WHERE user_id = :u AND track_stock = 1 AND stock_qty > 0 ORDER BY name');
        $st->execute(['u' => $userId]);
        $prods = [];
        foreach ($st->fetchAll() as $p) { $prods[(int)$p['id']] = $p; }
        if (!$prods) { return []; }
        $age = fn(string $d): int => max(0, (int)floor(($t0 - strtotime($d)) / 86400));

        // گوشی‌ها: هر دستگاه از آخرین رخدادش (همان تا کردنِ `inStock()`)
        $units = [];
        $st = $pdo->prepare("SELECT l.imei1, l.imei2, l.product_id, l.qty, l.net_total, l.unit_cost, i.kind, i.inv_date
                             FROM biz_invoice_lines l JOIN biz_invoices i ON i.id = l.invoice_id AND i.user_id = l.user_id
                             WHERE l.user_id = :u AND i.status = 'issued' AND l.imei1 IS NOT NULL AND l.product_id IS NOT NULL
                             ORDER BY " . BizSerial::orderSql('i') . ', l.id');
        $st->execute(['u' => $userId]);
        $alias = [];
        foreach ($st->fetchAll() as $r) {
            $n1 = (string)$r['imei1']; $n2 = $r['imei2'] !== null && $r['imei2'] !== '' ? (string)$r['imei2'] : null;
            $key = $alias[$n1] ?? ($n2 !== null ? ($alias[$n2] ?? null) : null) ?? $n1;
            $alias[$n1] = $key;
            if ($n2 !== null) { $alias[$n2] = $key; }
            $q = max(1.0, (float)$r['qty']);
            // بهای واحدِ ورود را `recalc()` روی ردیف می‌نویسد (خرید: خالصِ ردیف؛ برگشت: بهای فروشِ اصل)
            $cost = $r['unit_cost'] !== null ? (float)$r['unit_cost'] : (float)$r['net_total'] / $q;
            $units[$key] = ['pid' => (int)$r['product_id'], 'in' => in_array($r['kind'], BizSerial::IN_KINDS, true),
                            'date' => (string)$r['inv_date'], 'cost' => $cost, 'imei' => $n1];
        }
        $lots = [];
        foreach ($units as $u) {
            if (!$u['in'] || !isset($prods[$u['pid']])) { continue; }
            $lots[$u['pid']][] = ['qty' => 1.0, 'cost' => $u['cost'], 'age' => $age($u['date']), 'imei' => $u['imei']];
        }

        // بقیه: FIFO روی ورودی‌ها، تازه‌ترین اول
        $st = $pdo->prepare('SELECT product_id, move_date, qty, unit_cost FROM biz_stock_moves
                             WHERE user_id = :u AND qty > 0 ORDER BY product_id, move_date DESC, id DESC');
        $st->execute(['u' => $userId]);
        $moves = [];
        foreach ($st->fetchAll() as $m) { $moves[(int)$m['product_id']][] = $m; }

        $out = [];
        foreach ($prods as $pid => $p) {
            $need = (float)$p['stock_qty'];
            $avg = (float)$p['avg_cost'];
            $pl = [];
            if ((int)$p['has_serial'] === 1 && !empty($lots[$pid])) {
                usort($lots[$pid], fn($a, $b) => $a['age'] <=> $b['age']);
                foreach ($lots[$pid] as $l) { if ($need <= 0) { break; } $pl[] = $l; $need -= 1; }
            }
            foreach ($moves[$pid] ?? [] as $m) {
                if ($need <= 0.0005) { break; }
                if ((int)$p['has_serial'] === 1 && $pl) { break; }   // گوشی: دستگاه‌ها بس‌اند
                $take = min($need, (float)$m['qty']);
                $pl[] = ['qty' => $take, 'cost' => $m['unit_cost'] !== null ? (float)$m['unit_cost'] : $avg, 'age' => $age((string)$m['move_date']), 'imei' => null];
                $need -= $take;
            }
            if ($need > 0.0005) { $pl[] = ['qty' => $need, 'cost' => $avg, 'age' => null, 'imei' => null]; }
            $b = array_fill(0, count(self::BUCKETS) + 1, 0.0);
            $value = 0.0; $oldest = null; $risk = 0.0; $buy = (int)$p['buy_price']; $belowSell = false;
            foreach ($pl as $l) {
                $b[self::bucket($l['age'])] += $l['qty'];
                $value += $l['qty'] * $l['cost'];
                if ($l['age'] !== null) { $oldest = max($oldest ?? 0, $l['age']); }
                if ($buy > 0 && $l['cost'] > $buy) { $risk += ($l['cost'] - $buy) * $l['qty']; }
                if ((int)$p['sell_price'] > 0 && (int)$p['sell_price'] < $l['cost']) { $belowSell = true; }
            }
            $out[] = ['id' => $pid, 'name' => (string)$p['name'], 'unit' => (string)$p['unit'], 'serial' => (int)$p['has_serial'] === 1,
                      'qty' => (float)$p['stock_qty'], 'value' => (int)round($value), 'oldest' => $oldest, 'buckets' => $b,
                      'risk' => (int)round($risk), 'below_sell' => $belowSell, 'buy_price' => $buy, 'sell_price' => (int)$p['sell_price'],
                      'lots' => $pl];
        }
        usort($out, fn($a, $b) => [$b['oldest'] ?? -1, $b['value']] <=> [$a['oldest'] ?? -1, $a['value']]);
        return $out;
    }

    /**
     * جمعِ دسته‌ها و ریسک.
     * @return array{value:list<int>, qty:list<float>, total:int, idle:int, risk:int, risky:int, below_sell:int}
     */
    public static function summary(array $rows): array
    {
        $n = count(self::BUCKETS) + 1;
        $s = ['value' => array_fill(0, $n, 0), 'qty' => array_fill(0, $n, 0.0), 'total' => 0, 'idle' => 0, 'risk' => 0, 'risky' => 0, 'below_sell' => 0];
        foreach ($rows as $r) {
            foreach ($r['lots'] as $l) {
                $k = self::bucket($l['age']);
                $v = (int)round($l['qty'] * $l['cost']);
                $s['value'][$k] += $v;
                $s['qty'][$k] += $l['qty'];
                if ($l['age'] === null || $l['age'] > self::IDLE_DAYS) { $s['idle'] += $v; }
            }
            $s['total'] += $r['value'];
            $s['risk'] += $r['risk'];
            if ($r['risk'] > 0) { $s['risky']++; }
            if ($r['below_sell']) { $s['below_sell']++; }
        }
        return $s;
    }
}
