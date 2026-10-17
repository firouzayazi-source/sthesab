<?php
/**
 * ⛔ بسته‌ی فروش (migration_biz_bundles، `store/bundles.php`).
 *
 * **خواسته‌ی مالکِ نصب (مهر ۱۴۰۵):** «پکِ لوازم» (قاب + گلس + شارژر) با یک قیمتِ یک‌جا؛ موجودیِ هر قلم جدا کم شود.
 *
 * ⛔ بسته کالا نیست و هیچ موجودی ندارد — فقط الگو. تنها اثرش `expand()` است که `BizInvoices::saveDraft()`ِ
 *    **فروش** صدا می‌زند: ردیفی که متنش دقیقاً نام یا کدِ یک بسته است به ردیف‌های
 *    واقعیِ اجزا باز می‌شود. پس فاکتور، موجودی، بهای فروش، سود، برگشت و گزارش‌ها همه همان مسیرِ همیشه‌اند.
 * ⛔ قیمتِ یک‌جا به نسبتِ قیمتِ فروشِ اجزا پخش می‌شود و جمعِ ردیف‌ها **دقیقاً** همان می‌ماند (باقیِ گرد کردن به
 *    یک واحد از ردیفِ اول؛ اگر آن ردیف چندتایی است دو تکه می‌شود). قیمتِ نوشته‌شده روی ردیف (تخفیفِ لحظه‌ای)
 *    برنده است.
 * ⛔ گوشیِ IMEIدار و خدمت جزءِ بسته نمی‌شوند؛ نامِ بسته با نام یا کدِ هیچ کالایی یکی نیست (وگرنه کالا یا
 *    بسته، یکی بی‌صدا برنده می‌شد).
 */

require_once __DIR__ . '/biz_catalog.php';

final class BizBundles
{
    public const MAX_ITEMS = 12;

    public static function ready(): bool
    {
        return tableExists('biz_bundle_items');
    }

    /** @return list<array{id:int, name:string, sku:string, price:int, is_active:int, items:list<array>}> */
    public static function all(int $userId, bool $activeOnly = false): array
    {
        if (!self::ready()) { return []; }
        $pdo = Database::getConnection();
        $st = $pdo->prepare('SELECT id, name, sku, price, is_active FROM biz_bundles WHERE user_id = :u' . ($activeOnly ? ' AND is_active = 1' : '') . ' ORDER BY name');
        $st->execute(['u' => $userId]);
        $out = [];
        foreach ($st->fetchAll() as $b) { $b['items'] = []; $out[(int)$b['id']] = $b; }
        if (!$out) { return []; }
        $st = $pdo->prepare('SELECT i.bundle_id, i.product_id, i.qty, p.name, p.sell_price, p.unit FROM biz_bundle_items i
                             JOIN biz_products p ON p.id = i.product_id AND p.user_id = i.user_id WHERE i.user_id = :u ORDER BY i.id');
        $st->execute(['u' => $userId]);
        foreach ($st->fetchAll() as $i) { if (isset($out[(int)$i['bundle_id']])) { $out[(int)$i['bundle_id']]['items'][] = $i; } }
        return array_values($out);
    }

    /** قیمتِ بسته: ثبت‌شده، وگرنه جمعِ قیمتِ فروشِ اجزا. */
    public static function price(array $b): int
    {
        if ((int)$b['price'] > 0) { return (int)$b['price']; }
        return (int)round(array_sum(array_map(fn($i) => (float)$i['qty'] * (int)$i['sell_price'], $b['items'])));
    }

    /**
     * ساختن یا ویرایش. `$items`: product_id → qty.
     * @return array{ok:bool, message:string, id?:int}
     */
    public static function save(int $userId, array $in, int $id = 0): array
    {
        if (!self::ready()) { return ['ok' => false, 'message' => 'این بخش هنوز راه نیفتاده است.']; }
        $name = BizCommon::persian(BizCommon::line((string)($in['name'] ?? '')));
        $sku  = toLatinDigits(BizCommon::line((string)($in['sku'] ?? '')));
        $price = sanitizeAmount((string)($in['price'] ?? ''));
        if ($name === '' || mb_strlen($name) > 150) { return ['ok' => false, 'message' => 'نامِ بسته را بنویسید (حداکثر ۱۵۰ نویسه).']; }
        if (mb_strlen($sku) > 60) { return ['ok' => false, 'message' => 'کدِ بسته بلند است.']; }
        if ($price < 0 || ($e = BizCommon::moneyError($price, 'قیمتِ بسته')) !== null) { return ['ok' => false, 'message' => $e ?? 'قیمت نامعتبر است.']; }
        $pdo = Database::getConnection();
        // ⛔ هم‌نام یا هم‌کدِ یک کالا نه
        $clash = $pdo->prepare('SELECT 1 FROM biz_products WHERE user_id = :u AND (name = :n OR (:s <> \'\' AND (sku = :s2 OR name = :s3)) OR sku = :n2) LIMIT 1');
        $clash->execute(['u' => $userId, 'n' => $name, 's' => $sku, 's2' => $sku, 's3' => $sku, 'n2' => $name]);
        if ($clash->fetchColumn()) { return ['ok' => false, 'message' => 'کالایی با همین نام یا کد هست؛ نامِ بسته باید جدا باشد.']; }
        $items = [];
        foreach ((array)($in['items'] ?? []) as $pid => $q) {
            $pid = (int)$pid; $qty = sanitizeQty((string)$q);
            if ($pid <= 0 || $qty <= 0) { continue; }
            if (($e = BizCommon::qtyError($qty, 'تعداد')) !== null) { return ['ok' => false, 'message' => $e]; }
            $items[$pid] = $qty;
        }
        if (count($items) < 2) { return ['ok' => false, 'message' => 'بسته دست‌کم دو قلم می‌خواهد.']; }
        if (count($items) > self::MAX_ITEMS) { return ['ok' => false, 'message' => 'حداکثر ' . toPersianDigits((string)self::MAX_ITEMS) . ' قلم.']; }
        $chk = $pdo->prepare('SELECT id, name, unit, track_stock, has_serial, is_active FROM biz_products WHERE id = :id AND user_id = :u');
        foreach ($items as $pid => $qty) {
            $chk->execute(['id' => $pid, 'u' => $userId]);
            $p = $chk->fetch();
            if (!$p || (int)$p['is_active'] !== 1) { return ['ok' => false, 'message' => 'یکی از کالاها پیدا نشد یا غیرفعال است.']; }
            if ((int)$p['has_serial'] === 1) { return ['ok' => false, 'message' => '«' . $p['name'] . '» گوشیِ IMEIدار است و جزءِ بسته نمی‌شود — گوشی را جدا روی فاکتور بیاورید.']; }
            if (!BizProducts::qtyFits((string)$p['unit'], $qty)) { return ['ok' => false, 'message' => 'تعدادِ «' . $p['name'] . '» برای واحدِ «' . $p['unit'] . '» باید عددِ صحیح باشد.']; }
        }
        $pdo->beginTransaction();
        try {
            if ($id > 0) {
                $up = $pdo->prepare('UPDATE biz_bundles SET name = :n, sku = :s, price = :p, is_active = :a WHERE id = :id AND user_id = :u');
                $up->execute(['n' => $name, 's' => $sku !== '' ? $sku : null, 'p' => $price, 'a' => empty($in['inactive']) ? 1 : 0, 'id' => $id, 'u' => $userId]);
                $own = $pdo->prepare('SELECT 1 FROM biz_bundles WHERE id = :id AND user_id = :u');
                $own->execute(['id' => $id, 'u' => $userId]);
                if (!$own->fetchColumn()) { $pdo->rollBack(); return ['ok' => false, 'message' => 'بسته پیدا نشد.']; }
                $pdo->prepare('DELETE FROM biz_bundle_items WHERE bundle_id = :b AND user_id = :u')->execute(['b' => $id, 'u' => $userId]);
            } else {
                $pdo->prepare('INSERT INTO biz_bundles (user_id, name, sku, price) VALUES (:u, :n, :s, :p)')
                    ->execute(['u' => $userId, 'n' => $name, 's' => $sku !== '' ? $sku : null, 'p' => $price]);
                $id = (int)$pdo->lastInsertId();
            }
            $ins = $pdo->prepare('INSERT INTO biz_bundle_items (user_id, bundle_id, product_id, qty) VALUES (:u, :b, :p, :q)');
            foreach ($items as $pid => $qty) { $ins->execute(['u' => $userId, 'b' => $id, 'p' => $pid, 'q' => $qty]); }
            $pdo->commit();
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) { $pdo->rollBack(); }
            if ($e->getCode() === '23000') { return ['ok' => false, 'message' => 'بسته‌ای با همین نام هست.']; }
            throw $e;
        }
        return ['ok' => true, 'message' => 'بسته ذخیره شد.', 'id' => $id];
    }

    public static function delete(int $userId, int $id): bool
    {
        $st = Database::getConnection()->prepare('DELETE FROM biz_bundles WHERE id = :id AND user_id = :u');
        $st->execute(['id' => $id, 'u' => $userId]);
        return $st->rowCount() > 0;
    }

    /**
     * ⛔ ردیفِ بسته → ردیف‌های اجزا (فقط فاکتورِ فروش، از `saveDraft()`). بقیه‌ی ردیف‌ها دست‌نخورده.
     * @param list<array> $lines ردیف‌های خامِ فرم
     * @return list<array>
     */
    public static function expand(int $userId, array $lines): array
    {
        if (!self::ready()) { return array_values($lines); }
        $bundles = null; $out = [];
        foreach (array_values($lines) as $r) {
            // ⛔ متنِ ردیف تصمیم می‌گیرد، نه شناسه‌ی پنهان: شناسه‌ی کهنه‌ی کالایی که کاربر رویش نامِ بسته
            //    نوشته همان «شناسه‌ی کهنه» است که `parseLines()` هم دور می‌ریزد (نامِ بسته با هیچ کالایی یکی نیست)
            if (!is_array($r)) { $out[] = $r; continue; }
            $item = BizCommon::line((string)($r['item'] ?? ''));
            if ($item === '') { $out[] = $r; continue; }
            if ($bundles === null) {
                $bundles = [];
                foreach (self::all($userId, true) as $b) {
                    $bundles[BizCommon::fold((string)$b['name'])] = $b;
                    if ((string)$b['sku'] !== '') { $bundles['sku:' . mb_strtolower((string)$b['sku'])] = $b; }
                }
            }
            // ⛔ کد بی‌حساسیت به بزرگی و کوچکیِ حروف (اسکنر و تایپ دو جورند) — همان `bySku`ِ store.js
            $b = $bundles[BizCommon::fold($item)] ?? $bundles['sku:' . mb_strtolower(toLatinDigits($item))] ?? null;
            if ($b === null || count($b['items']) < 2) { $out[] = $r; continue; }
            $n = sanitizeQty(trim((string)($r['qty'] ?? '')) !== '' ? (string)$r['qty'] : '1');
            $n = $n > 0 && floor($n) == $n ? (int)$n : 1;
            $typed = trim((string)($r['price'] ?? ''));
            $total = ($typed !== '' ? sanitizeAmount($typed) : self::price($b)) * $n;
            foreach (self::split($b, $total, $n) as $x) {
                $out[] = ['product_id' => (string)$x['product_id'], 'item' => $x['name'], 'qty' => (string)$x['qty'], 'price' => (string)$x['price'],
                          'disc' => '', 'note' => 'از بسته‌ی «' . $b['name'] . '»'];
            }
        }
        return $out;
    }

    /**
     * پخشِ قیمتِ یک‌جا میانِ اجزا — جمعِ ردیف‌ها دقیقاً `$total`.
     * @return list<array{product_id:int, name:string, qty:float, price:int}>
     */
    public static function split(array $b, int $total, int $n = 1): array
    {
        $parts = []; $weight = 0.0;
        foreach ($b['items'] as $i) {
            $q = (float)$i['qty'] * $n;
            $w = max(1, (int)$i['sell_price']) * $q;
            $parts[] = ['product_id' => (int)$i['product_id'], 'name' => (string)$i['name'], 'qty' => $q, 'w' => $w];
            $weight += $w;
        }
        $out = []; $sum = 0;
        foreach ($parts as $p) {
            $unit = (int)floor(($total * $p['w'] / $weight) / $p['qty']);
            $out[] = ['product_id' => $p['product_id'], 'name' => $p['name'], 'qty' => $p['qty'], 'price' => $unit];
            $sum += (int)round($unit * $p['qty']);
        }
        $rest = $total - $sum;
        if ($rest > 0 && $out) {
            // ⛔ باقی به یک واحد از ردیفِ اول؛ ردیفِ چندتایی دو تکه می‌شود تا قیمتِ واحد صحیح بماند
            $f = $out[0];
            if ($f['qty'] > 1) {
                $out[0]['qty'] = $f['qty'] - 1;
                array_splice($out, 1, 0, [['product_id' => $f['product_id'], 'name' => $f['name'], 'qty' => 1.0, 'price' => $f['price'] + $rest]]);
            } else {
                $out[0]['price'] = $f['price'] + $rest;
            }
        }
        return $out;
    }
}
