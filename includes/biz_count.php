<?php
/**
 * ⛔ انبارگردانی با اسکن (migration_biz_stock_count، `store/count.php`).
 *
 * **خواسته‌ی مالکِ نصب (مهر ۱۴۰۵):** قفسه به قفسه با دوربین یا اسکنرِ دستی بشماریم؛ آخرِ کار «کم است»،
 * «اضافه است» و «IMEIِ ناشناس» بیاید و اصلاح فقط با تأیید انجام شود.
 *
 * ⛔ اسکن هیچ اثری بر موجودی ندارد — فقط ردیف در `biz_stock_count_lines`.
 * ⛔ مقایسه با همان تعریفِ انبارگردانی (`BizStock::haveToday()`، تا امروز، انبارِ همان شمارش).
 * ⛔ اعمال فقط با تأییدِ صریح و فقط از `BizStock::adjustTo()` (قفلِ دوره، سدِ منفی، یادداشت)؛ دوباره‌اعمال
 *    بی‌خطر است چون «برسان به شمارش» است نه «اضافه کن». گوشیِ IMEIدار **هرگز** خودکار اصلاح نمی‌شود —
 *    دستگاهِ گم‌شده یا ناشناس بررسیِ دستی می‌خواهد (سند، نه عدد).
 * ⛔ «فقط اسکن‌شده‌ها» پیش‌فرض است: شمارشِ نیمه‌کاره هرگز کالای شمرده‌نشده را صفر نمی‌کند، مگر
 *    صاحب صریحاً «همه‌ی کالاها» را بزند.
 */

require_once __DIR__ . '/biz_docs.php';

final class BizCount
{
    public const STATUSES = ['open' => 'در حالِ شمارش', 'applied' => 'اعمال شد', 'cancelled' => 'لغو شد'];
    /** هر بار ارسال حداکثر این‌قدر اسکن. */
    public const MAX_SCANS = 500;

    public static function ready(): bool
    {
        return tableExists('biz_stock_count_lines');
    }

    /** @return array{ok:bool, message:string, id?:int} */
    public static function open(int $userId, ?int $warehouseId, string $note, int $actorId): array
    {
        $note = mb_substr(BizCommon::line($note), 0, 200);
        $pdo = Database::getConnection();
        $pdo->beginTransaction();
        try {
            Biz::lockShop($pdo, $userId);
            $n = $pdo->prepare('SELECT COALESCE(MAX(number), 0) + 1 FROM biz_stock_counts WHERE user_id = :u');
            $n->execute(['u' => $userId]);
            $no = (int)$n->fetchColumn();
            $pdo->prepare('INSERT INTO biz_stock_counts (user_id, number, warehouse_id, note, created_by) VALUES (:u, :n, :w, :no, :b)')
                ->execute(['u' => $userId, 'n' => $no, 'w' => $warehouseId, 'no' => $note !== '' ? $note : null, 'b' => $actorId > 0 ? $actorId : null]);
            $id = (int)$pdo->lastInsertId();
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) { $pdo->rollBack(); }
            throw $e;
        }
        return ['ok' => true, 'message' => 'انبارگردانیِ شماره‌ی ' . toPersianDigits((string)$no) . ' باز شد؛ شروع به اسکن کنید.', 'id' => $id];
    }

    public static function get(int $userId, int $id): ?array
    {
        $st = Database::getConnection()->prepare('SELECT * FROM biz_stock_counts WHERE id = :id AND user_id = :u');
        $st->execute(['id' => $id, 'u' => $userId]);
        return $st->fetch() ?: null;
    }

    /** @return list<array> */
    public static function all(int $userId, int $limit = 30): array
    {
        $st = Database::getConnection()->prepare('SELECT c.*, (SELECT COUNT(*) FROM biz_stock_count_lines l WHERE l.count_id = c.id) AS scans
                                                  FROM biz_stock_counts c WHERE c.user_id = :u ORDER BY c.id DESC LIMIT ' . max(1, min(200, $limit)));
        $st->execute(['u' => $userId]);
        return $st->fetchAll();
    }

    /**
     * اسکن‌ها را ثبت می‌کند. هر خط یک کد؛ «کد×۳» یا «کد*3» یعنی سه عدد (برای کالای بی‌IMEI).
     * کد = SKUِ کالا؛ وگرنه IMEI (۱۴ تا ۱۷ رقم) → کالای همان گوشی از سندها؛ وگرنه «ناشناس».
     * ⛔ یک IMEI در یک شمارش یک بار (کلیدِ یکتا) — اسکنِ دوباره‌ی همان جعبه شمار را بالا نمی‌برد.
     * @return array{ok:bool, message:string, added:int, dup:int, unknown:int}
     */
    public static function scan(int $userId, int $countId, string $raw): array
    {
        $out = ['ok' => false, 'message' => '', 'added' => 0, 'dup' => 0, 'unknown' => 0];
        $c = self::get($userId, $countId);
        if (!$c || $c['status'] !== 'open') { $out['message'] = 'این انبارگردانی باز نیست.'; return $out; }
        $lines = array_values(array_filter(array_map('trim', preg_split('/[\r\n]+/', toLatinDigits($raw)) ?: []), fn($l) => $l !== ''));
        if (!$lines) { $out['message'] = 'کدی اسکن نشده است.'; return $out; }
        if (count($lines) > self::MAX_SCANS) { $out['message'] = 'هر بار حداکثر ' . toPersianDigits((string)self::MAX_SCANS) . ' اسکن.'; return $out; }
        $pdo = Database::getConnection();
        $bySku = $pdo->prepare('SELECT id, has_serial FROM biz_products WHERE user_id = :u AND sku = :s AND track_stock = 1 LIMIT 1');
        $ins = $pdo->prepare('INSERT IGNORE INTO biz_stock_count_lines (user_id, count_id, product_id, imei, code, qty) VALUES (:u, :c, :p, :i, :code, :q)');
        foreach ($lines as $l) {
            $qty = 1.0;
            if (preg_match('/^(.+?)\s*[×x*]\s*(\d+(?:\.\d+)?)$/u', $l, $m)) { $l = trim($m[1]); $qty = (float)$m[2]; }
            if ($qty <= 0 || BizCommon::qtyError($qty, 'تعداد') !== null) { continue; }
            $code = mb_substr($l, 0, 60);
            $pid = null; $imei = null;
            $bySku->execute(['u' => $userId, 's' => $code]);
            $p = $bySku->fetch();
            if ($p && (int)$p['has_serial'] !== 1) {
                $pid = (int)$p['id'];
            } elseif (BizSerial::valid(BizSerial::norm($code))) {
                $imei = BizSerial::norm($code);
                $qty = 1.0;
                $st = BizSerial::lookup($userId, $imei);
                $pid = $st !== null ? (int)$st['product_id'] : null;
            }
            $ins->execute(['u' => $userId, 'c' => $countId, 'p' => $pid, 'i' => $imei, 'code' => $code, 'q' => $qty]);
            if ($ins->rowCount() === 0) { $out['dup']++; continue; }
            $out['added']++;
            if ($pid === null) { $out['unknown']++; }
        }
        $out['ok'] = $out['added'] > 0;
        $out['message'] = toPersianDigits((string)$out['added']) . ' اسکن ثبت شد'
            . ($out['dup'] ? '؛ ' . toPersianDigits((string)$out['dup']) . ' IMEIِ تکراری نادیده' : '')
            . ($out['unknown'] ? '؛ ' . toPersianDigits((string)$out['unknown']) . ' کدِ ناشناس' : '') . '.';
        return $out;
    }

    /** حذفِ یک اسکن (اشتباه) — فقط از شمارشِ باز. */
    public static function unscan(int $userId, int $countId, int $lineId): bool
    {
        $c = self::get($userId, $countId);
        if (!$c || $c['status'] !== 'open') { return false; }
        $st = Database::getConnection()->prepare('DELETE FROM biz_stock_count_lines WHERE id = :l AND count_id = :c AND user_id = :u');
        $st->execute(['l' => $lineId, 'c' => $countId, 'u' => $userId]);
        return $st->rowCount() > 0;
    }

    /**
     * مقایسه با دفتر.
     * `$scope`: 'scanned' فقط کالاهایی که دست‌کم یک بار اسکن شدند؛ 'all' همه‌ی کالاهای انباری (شمرده‌نشده = صفر).
     * @return array{rows:list<array{id:int, name:string, unit:string, serial:bool, system:float, counted:float, diff:float}>,
     *               missing:list<array{imei:string, product:string}>, foreign:list<array{imei:string, product:string}>,
     *               unknown:list<array{id:int, code:string, qty:float}>, lines:int}
     */
    public static function diff(int $userId, int $countId, string $scope = 'scanned'): array
    {
        $c = self::get($userId, $countId);
        $out = ['rows' => [], 'missing' => [], 'foreign' => [], 'unknown' => [], 'lines' => 0];
        if (!$c) { return $out; }
        $pdo = Database::getConnection();
        $st = $pdo->prepare('SELECT id, product_id, imei, code, qty FROM biz_stock_count_lines WHERE count_id = :c AND user_id = :u ORDER BY id');
        $st->execute(['c' => $countId, 'u' => $userId]);
        $counted = []; $imeis = [];
        foreach ($st->fetchAll() as $l) {
            $out['lines']++;
            if ($l['product_id'] === null) { $out['unknown'][] = ['id' => (int)$l['id'], 'code' => (string)$l['code'], 'qty' => (float)$l['qty']]; continue; }
            $counted[(int)$l['product_id']] = ($counted[(int)$l['product_id']] ?? 0) + (float)$l['qty'];
            if ($l['imei'] !== null) { $imeis[(string)$l['imei']] = (int)$l['product_id']; }
        }
        $st = $pdo->prepare('SELECT id, name, unit, has_serial, stock_qty FROM biz_products WHERE user_id = :u AND track_stock = 1 ORDER BY name');
        $st->execute(['u' => $userId]);
        $wh = $c['warehouse_id'] !== null ? (int)$c['warehouse_id'] : null;
        $multi = BizWarehouses::any($userId);
        $names = [];
        foreach ($st->fetchAll() as $p) {
            $pid = (int)$p['id'];
            $names[$pid] = (string)$p['name'];
            if ($scope !== 'all' && !isset($counted[$pid])) { continue; }
            $sys = BizStock::haveToday($pdo, $userId, $pid, $wh, $multi);
            if ($scope === 'all' && !isset($counted[$pid]) && abs($sys) < 0.0005) { continue; }
            $cnt = $counted[$pid] ?? 0.0;
            $out['rows'][] = ['id' => $pid, 'name' => (string)$p['name'], 'unit' => (string)$p['unit'], 'serial' => (int)$p['has_serial'] === 1,
                              'system' => $sys, 'counted' => $cnt, 'diff' => round($cnt - $sys, 3)];
        }
        // گوشی‌ها: دستگاهِ در دفتر ولی اسکن‌نشده، و اسکن‌شده‌ای که در دفتر «در انبار» نیست
        $stock = BizSerial::inStock($userId);
        $inBook = [];
        foreach ($stock['rows'] as $r) {
            $keys = array_filter([(string)$r['imei1'], (string)($r['imei2'] ?? '')]);
            foreach ($keys as $k) { $inBook[$k] = $r['product_id']; }
            if ($scope !== 'all' && !isset($counted[$r['product_id']])) { continue; }
            $seen = false;
            foreach ($keys as $k) { if (isset($imeis[$k])) { $seen = true; } }
            if (!$seen) { $out['missing'][] = ['imei' => (string)$r['imei1'], 'product' => $names[$r['product_id']] ?? '']; }
        }
        foreach ($imeis as $im => $pid) {
            if (!isset($inBook[$im])) { $out['foreign'][] = ['imei' => $im, 'product' => $names[$pid] ?? '']; }
        }
        return $out;
    }

    /**
     * ⛔ اعمال — فقط با تأیید، فقط کالای بی‌IMEI، فقط از `BizStock::adjustTo()`.
     * @return array{ok:bool, message:string, applied:int, failed:list<string>}
     */
    public static function apply(int $userId, int $countId, string $scope, bool $confirmed): array
    {
        $c = self::get($userId, $countId);
        if (!$c || $c['status'] !== 'open') { return ['ok' => false, 'message' => 'این انبارگردانی باز نیست.', 'applied' => 0, 'failed' => []]; }
        if (!$confirmed) { return ['ok' => false, 'message' => 'برای اعمال، تأیید را تیک بزنید.', 'applied' => 0, 'failed' => []]; }
        $scope = $scope === 'all' ? 'all' : 'scanned';
        $d = self::diff($userId, $countId, $scope);
        $note = 'انبارگردانیِ شماره‌ی ' . toPersianDigits((string)$c['number']);
        $wh = $c['warehouse_id'] !== null ? (int)$c['warehouse_id'] : null;
        $done = 0; $failed = [];
        foreach ($d['rows'] as $r) {
            if ($r['serial'] || abs($r['diff']) < 0.0005) { continue; }
            $res = BizStock::adjustTo($userId, $r['id'], $r['counted'], $note, $wh);
            if ($res['ok']) { $done++; } else { $failed[] = $r['name'] . ': ' . $res['message']; }
        }
        if (!$failed) {
            Database::getConnection()->prepare("UPDATE biz_stock_counts SET status = 'applied', applied_at = NOW() WHERE id = :id AND user_id = :u AND status = 'open'")
                ->execute(['id' => $countId, 'u' => $userId]);
        }
        return ['ok' => !$failed, 'applied' => $done, 'failed' => $failed,
                'message' => $failed ? toPersianDigits((string)$done) . ' کالا اصلاح شد؛ ' . toPersianDigits((string)count($failed)) . ' مورد نشد (شمارش باز ماند): ' . implode(' — ', $failed)
                                     : ($done ? toPersianDigits((string)$done) . ' کالا به شمارش رسید؛ انبارگردانی بسته شد.' : 'اختلافی نبود؛ انبارگردانی بسته شد.')];
    }

    public static function cancel(int $userId, int $countId): bool
    {
        $st = Database::getConnection()->prepare("UPDATE biz_stock_counts SET status = 'cancelled' WHERE id = :id AND user_id = :u AND status = 'open'");
        $st->execute(['id' => $countId, 'u' => $userId]);
        return $st->rowCount() > 0;
    }
}
