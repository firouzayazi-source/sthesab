<?php
/**
 * ⛔ سخت‌سازیِ محیطِ فروشگاهی پیش از عرضه — بازرسیِ مهر ۱۴۰۵.
 *
 * **خواسته‌ی مالکِ نصب:** «بخشِ فروشگاهی را بررسی کن … می‌خوام سیستم بی‌نقص
 * باشه در همه‌جا برای وارد شدن به معامله‌ی واقعی.»
 *
 * هر گروه یک خرابیِ **بازتولیدشده** است — نه حدس — و پیش از اصلاح همین
 * سناریو عددِ غلط می‌داد. اگر روزی یکی قرمز شد، همان پول دوباره گم می‌شود.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit('Not found.');
}

require_once __DIR__ . '/lib/assert.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/signup.php';
require_once __DIR__ . '/../includes/user_data.php';
require_once __DIR__ . '/../includes/biz_catalog.php';
require_once __DIR__ . '/../includes/biz_docs.php';

const HPREFIX = '__bhard_';
const HPASS   = 'Hard12345';

try {
    $pdo = Database::getConnection();
} catch (Throwable $e) {
    T::blocked('سخت‌سازیِ فروشگاه', 'اتصال به دیتابیس برقرار نشد: ' . $e->getMessage());
    exit(T::report());
}
if (!Biz::available() || !tableHasColumn('biz_invoice_lines', 'imei1') || !BizSerial::hasFirstIssued()) {
    T::blocked('سخت‌سازیِ فروشگاه', 'ستون‌های فروشگاه نیست — اول: bash deploy/migrate.sh --apply');
    exit(T::report());
}

$wipe = function () use ($pdo) {
    $ids = $pdo->query("SELECT id FROM users WHERE username LIKE '" . HPREFIX . "%'")->fetchAll(PDO::FETCH_COLUMN);
    foreach ($ids as $id) {
        foreach (['biz_allocations', 'biz_payments', 'biz_stock_moves'] as $t) {
            $pdo->prepare("DELETE FROM `{$t}` WHERE user_id = :u")->execute(['u' => $id]);
        }
        $pdo->prepare('DELETE FROM biz_invoices WHERE user_id = :u AND ref_invoice_id IS NOT NULL')->execute(['u' => $id]);
        foreach (['biz_invoices', 'biz_products', 'biz_categories', 'biz_parties', 'biz_accounts', 'biz_settings'] as $t) {
            $pdo->prepare("DELETE FROM `{$t}` WHERE user_id = :u")->execute(['u' => $id]);
        }
        foreach (userDataTables() as $t) {
            try { $pdo->prepare("DELETE FROM `{$t}` WHERE user_id = :u")->execute(['u' => $id]); }
            catch (PDOException $e) { }
        }
        $pdo->prepare('DELETE FROM audit_log WHERE target_user_id = :u')->execute(['u' => $id]);
    }
    $pdo->exec("DELETE FROM users WHERE username LIKE '" . HPREFIX . "%'");
};
$wipe();

$make = function (string $name) use ($pdo): int {
    $res = createUserAccount($pdo, 'کاربرِ ' . $name, HPREFIX . $name, HPREFIX . $name . '@example.com', HPASS);
    if (!($res['ok'] ?? false)) { throw new RuntimeException('ساختِ کاربر: ' . ($res['error'] ?? '?')); }
    $st = $pdo->prepare('SELECT id FROM users WHERE username = :u');
    $st->execute(['u' => HPREFIX . $name]);
    $id = (int)$st->fetchColumn();
    Biz::setType($id, 'business');
    return $id;
};

$L = fn(string $item, string $qty, string $price, string $i1 = '', string $i2 = ''): array
    => ['item' => $item, 'qty' => $qty, 'price' => $price, 'imei1' => $i1, 'imei2' => $i2];
$acc = fn(int $u): int => (int)BizCash::list($u)[0]['id'];
/** پیش‌نویس + صدور؛ شناسه یا پیامِ خطا */
$doc = function (int $u, string $kind, array $lines, array $head = []) use ($acc) {
    $r = BizInvoices::saveDraft($u, $kind, ['lines' => $lines] + $head);
    if (!$r['ok']) { return $r['message']; }
    $i = BizInvoices::issue($u, (int)$r['id'], ['full' => true, 'account_id' => $acc($u)]);
    return $i['ok'] ? (int)$r['id'] : $i['message'];
};
$stock = fn(int $u, int $pid): float => (float)BizProducts::get($u, $pid)['stock_qty'];
$inStock = fn(int $u, int $pid = 0): array => array_column(BizSerial::inStock($u, $pid)['rows'], 'imei1');

const X = '350000000000006';
const Y = '350000000079190';
const Z = '350000000158382';

// =================================================================
T::group('۱ — گوشیِ فروخته‌شده با «اصلاح و صدورِ دوباره»ی خریدش برنمی‌گردد');
$u = $make('a');
$PH = (int)BizProducts::save($u, ['type' => 'phone', 'name' => 'گوشی آزمون', 'unit' => 'دستگاه', 'buy_price' => '400', 'sell_price' => '500'])['id'];
$p1 = $doc($u, 'purchase', [$L('گوشی آزمون', '1', '400', X)]);
$p2 = $doc($u, 'purchase', [$L('گوشی آزمون', '1', '450', Y)]);
$s1 = $doc($u, 'sale', [$L('گوشی آزمون', '1', '600', X)]);
T::ok(is_int($p1) && is_int($p2) && is_int($s1), 'خریدِ X و Y، فروشِ X', var_export([$p1, $p2, $s1], true));

$r = BizInvoices::unissue($u, $p1);
T::ok($r['ok'], 'خریدِ X برای اصلاحِ قیمت به پیش‌نویس رفت', $r['message']);
$r = BizInvoices::issue($u, $p1, ['full' => true, 'account_id' => $acc($u)]);
T::ok($r['ok'], 'و دوباره صادر شد', $r['message']);
T::same([Y], $inStock($u, $PH), '⛔ فقط Y در انبار است — X فروخته شده و با صدورِ دوباره‌ی خریدش برنگشت');
T::same('out', BizSerial::lookup($u, X)['dir'] ?? null, 'سرگذشتِ X: آخرین رخداد همان فروش است');
T::ok(!is_int($doc($u, 'sale', [$L('گوشی آزمون', '1', '600', X)])), '⛔ X دوباره فروختنی نیست');

$r = BizInvoices::void($u, $p1);
T::ok(!$r['ok'] && str_contains($r['message'], X), '⛔ ابطالِ خریدِ گوشیِ فروخته‌شده رد می‌شود (سرگذشت بی‌ورود می‌ماند)', $r['message']);
$r = BizInvoices::void($u, $p2);
T::ok($r['ok'], 'ابطالِ خریدی که گوشی‌اش هنوز در انبار است آزاد است', $r['message']);

// اصلاحِ فروشی که گوشی‌اش بعداً دوباره خریده و فروخته شده
$p3 = $doc($u, 'purchase', [$L('گوشی آزمون', '1', '300', X)]);
$s2 = $doc($u, 'sale', [$L('گوشی آزمون', '1', '650', X)]);
T::ok(is_int($p3) && is_int($s2), 'X دوباره خریده و فروخته شد');
$r = BizInvoices::unissue($u, $s1);
$r2 = $r['ok'] ? BizInvoices::issue($u, $s1, ['full' => true, 'account_id' => $acc($u)]) : $r;
T::ok($r2['ok'], '⛔ اصلاحِ فروشِ اولِ X رد نمی‌شود: وضعیت در جای خودِ سند سنجیده می‌شود', $r2['message']);
T::same([], $inStock($u, $PH), 'و X بیرون است (فروشِ دوم آخرین رخداد است)');

// =================================================================
T::group('۲ — خواندنِ قفل‌دارِ حرکت‌ها');
$src = (string)file_get_contents(__DIR__ . '/../includes/biz_catalog.php');
T::ok((bool)preg_match('/FROM biz_stock_moves\s+WHERE product_id = :p AND user_id = :u\s+ORDER BY[^"]*FOR UPDATE/', $src),
    '⛔ `recalc()` حرکت‌ها را با `FOR UPDATE` می‌خواند (دو صدورِ هم‌زمان هم را می‌بینند)');

/**
 * N پردازشِ **جدا** هم‌زمان (هر کدام اتصالِ خودش به دیتابیس) — مسابقه‌ی واقعی،
 * نه شبیه‌سازی. هر پردازش `$code` را با `$u` اجرا می‌کند و یک خط چاپ می‌کند.
 * @return string[] خروجیِ هر پردازش
 */
$race = function (int $n, string $code): array {
    $tmp = tempnam(sys_get_temp_dir(), 'bhrace') . '.php';
    file_put_contents($tmp, "<?php\nrequire '" . __DIR__ . "/../includes/db.php';\nrequire '" . __DIR__ . "/../includes/functions.php';\n"
        . "require '" . __DIR__ . "/../includes/biz_catalog.php';\nrequire '" . __DIR__ . "/../includes/biz_docs.php';\n"
        . "usleep(random_int(0, 20000));\ntry {\n" . $code . "\n} catch (Throwable \$e) { echo 'EXC ' . \$e->getMessage(); }\n");
    $procs = []; $pipes = [];
    for ($i = 0; $i < $n; $i++) {
        $procs[$i] = proc_open([PHP_BINARY, $tmp], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes[$i]);
    }
    $out = [];
    foreach ($procs as $i => $pr) {
        $out[] = trim((string)stream_get_contents($pipes[$i][1]) . (string)stream_get_contents($pipes[$i][2]));
        proc_close($pr);
    }
    @unlink($tmp);
    return $out;
};

// =================================================================
T::group('۳ — برگشت: نه به پیش‌نویس، نه دو بار، نه هم‌زمان');
$u = $make('b');
$party = (int)BizParties::save($u, ['name' => 'مشتری برگشت', 'kind' => 'customer'])['id'];
$AC = (int)BizProducts::save($u, ['type' => 'goods', 'name' => 'گارد', 'unit' => 'عدد', 'buy_price' => '100', 'sell_price' => '150', 'opening_qty' => '10'])['id'];
$sale = $doc($u, 'sale', [$L('گارد', '2', '150')], ['party_id' => $party]);
$ret = BizInvoices::createReturn($u, $sale, [(int)BizInvoices::get($u, $sale)['lines'][0]['id'] => '2'], ['full' => true, 'account_id' => $acc($u)]);
T::ok($ret['ok'], 'برگشتِ ۲ تا از فروشِ ۲ تایی', $ret['message']);
$r = BizInvoices::unissue($u, (int)$ret['id']);
T::ok(!$r['ok'], '⛔ برگشت به پیش‌نویس نمی‌رود (پیوندِ ردیف‌ها به فاکتورِ اصلی می‌ماند)', $r['message']);
T::same(10.0, $stock($u, $AC), 'موجودی همان ۱۰');

$sale2 = $doc($u, 'sale', [$L('گارد', '1', '150')], ['party_id' => $party]);
$line2 = (int)BizInvoices::get($u, $sale2)['lines'][0]['id'];
$outs = $race(6, "\$r = BizInvoices::createReturn({$u}, {$sale2}, [{$line2} => '1'], ['full' => true, 'account_id' => " . $acc($u) . "]); echo \$r['ok'] ? 'OK' : 'NO';");
$okN = count(array_filter($outs, fn($o) => $o === 'OK'));
T::same(1, $okN, '⛔ شش برگشتِ هم‌زمانِ یک قلمِ تکی: فقط یکی می‌گذرد', implode(' | ', $outs));
T::ok(!array_filter($outs, fn($o) => str_starts_with($o, 'EXC')), '⛔ و هیچ‌کدام بن‌بست/استثنا نمی‌گیرد (قفلِ فروشگاه)', implode(' | ', $outs));
T::same(10.0, $stock($u, $AC), 'موجودی دقیقاً یک بار برگشت');

// =================================================================
T::group('۴ — شماره‌ی فاکتور دوباره داده نمی‌شود');
$s3 = $doc($u, 'sale', [$L('گارد', '1', '150')], ['party_id' => $party]);
$num = (int)BizInvoices::get($u, $s3)['number'];
BizInvoices::unissue($u, $s3);
$r = BizInvoices::deleteDraft($u, $s3);
T::ok($r['ok'] && BizInvoices::get($u, $s3)['status'] === 'void', '⛔ پیش‌نویسِ شماره‌دار حذف نمی‌شود، باطل می‌شود', $r['message']);
$s4 = $doc($u, 'sale', [$L('گارد', '1', '150')], ['party_id' => $party]);
T::ok((int)BizInvoices::get($u, $s4)['number'] === $num + 1, '⛔ فاکتورِ بعدی شماره‌ی تازه می‌گیرد، نه همان ' . $num,
    (string)BizInvoices::get($u, $s4)['number']);
$d = BizInvoices::saveDraft($u, 'sale', ['lines' => [$L('گارد', '1', '150')]]);
$r = BizInvoices::deleteDraft($u, (int)$d['id']);
T::ok($r['ok'] && BizInvoices::get($u, (int)$d['id']) === null, 'پیش‌نویسِ بی‌شماره مثلِ قبل حذف می‌شود');

// =================================================================
T::group('۵ — صدورِ هم‌زمان از چند دستگاه');
$before = $stock($u, $AC);
$outs = $race(8, "\$r = BizInvoices::saveDraft({$u}, 'sale', ['party_id' => {$party}, 'lines' => [['item' => 'گارد', 'qty' => '1', 'price' => '150']]]);"
    . " \$i = BizInvoices::issue({$u}, (int)\$r['id'], ['full' => true, 'account_id' => " . $acc($u) . "]); echo \$i['ok'] ? 'OK' : 'NO ' . \$i['message'];");
T::ok(count(array_filter($outs, fn($o) => $o === 'OK')) === 8, '⛔ هشت فروشِ هم‌زمان: همه صادر شدند، بی‌بن‌بست', implode(' | ', $outs));
$nums = $pdo->prepare("SELECT number FROM biz_invoices WHERE user_id = :u AND kind = 'sale' AND number IS NOT NULL");
$nums->execute(['u' => $u]);
$all = array_map('intval', $nums->fetchAll(PDO::FETCH_COLUMN));
T::same(count($all), count(array_unique($all)), '⛔ هیچ شماره‌ی تکراری');
T::same($before - 8, $stock($u, $AC), 'موجودی دقیقاً هشت تا کم شد');

// =================================================================
T::group('۶ — دو بار زدنِ «ثبت»: یک سند (HTTP)');
$root = dirname(__DIR__);
$port = 0;
for ($pp = 9411; $pp <= 9460; $pp++) {
    $sock = @stream_socket_server("tcp://127.0.0.1:$pp", $e1, $e2);
    if ($sock) { fclose($sock); $port = $pp; break; }
}
$log = tempnam(sys_get_temp_dir(), 'bhard');
$srv = $port ? (int)trim((string)shell_exec(sprintf('php -S 127.0.0.1:%d -t %s > %s 2>&1 & echo $!',
    $port, escapeshellarg($root), escapeshellarg($log)))) : 0;
$up = false;
for ($i = 0; $srv && $i < 40; $i++) {
    usleep(150000);
    $sk = @fsockopen('127.0.0.1', $port, $x, $y, 0.3);
    if ($sk) { fclose($sk); $up = true; break; }
}
if (!$up) {
    T::blocked('دو بار زدن (HTTP)', 'سرورِ آزمایشی بالا نیامد');
} else {
    $jar = tempnam(sys_get_temp_dir(), 'bhardjar');
    $req = function (string $path, ?array $post = null) use ($port, $jar): array {
        $ch = curl_init("http://127.0.0.1:{$port}/{$path}");
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar,
            CURLOPT_FOLLOWLOCATION => false, CURLOPT_HEADER => true, CURLOPT_TIMEOUT => 40]);
        if ($post !== null) { curl_setopt($ch, CURLOPT_POST, true); curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post)); }
        $raw = (string)curl_exec($ch);
        $hlen = (int)curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        $loc = preg_match('/^Location:\s*(\S+)/mi', substr($raw, 0, $hlen), $m) ? $m[1] : '';
        return [$code, substr($raw, $hlen), $loc];
    };
    $field = fn(string $html, string $name): string
        => preg_match('/name="' . preg_quote($name, '/') . '"[^>]*value="([^"]+)"/', $html, $m) ? $m[1] : '';
    $sales = fn(int $u): int => (int)$pdo->query("SELECT COUNT(*) FROM biz_invoices WHERE user_id = {$u} AND kind = 'sale' AND status = 'issued'")->fetchColumn();

    $w = $make('web');
    BizProducts::save($w, ['type' => 'goods', 'name' => 'شارژر', 'unit' => 'عدد', 'buy_price' => '100', 'sell_price' => '200', 'opening_qty' => '10']);
    [, $lp] = $req('store/login.php');
    [$c] = $req('store/login.php', ['csrf_token' => $field($lp, 'csrf_token'), 'username' => HPREFIX . 'web', 'password' => HPASS]);
    T::ok($c === 302, 'ورود به فروشگاه', (string)$c);

    [$c, $qs] = $req('store/quick-sale.php');
    $once = $field($qs, '_once');
    T::ok($c === 200 && $once !== '', 'فرمِ فروشِ سریع نشانِ یک‌بارمصرف دارد');
    $post = ['csrf_token' => $field($qs, 'csrf_token'), '_once' => $once, 'action' => 'sale', 'pay_mode' => 'full',
             'account_id' => (string)$acc($w), 'method' => 'cash',
             'lines' => [['item' => 'شارژر', 'qty' => '1', 'price' => '200']]];
    $before = $sales($w);
    [$c1] = $req('store/quick-sale.php', $post);
    [$c2, , $loc2] = $req('store/quick-sale.php', $post);
    T::ok($c1 === 302 && $c2 === 302, 'هر دو ارسال پاسخ گرفتند', "{$c1} {$c2}");
    T::same($before + 1, $sales($w), '⛔ همان فرم دو بار فرستاده شد: یک فروش، نه دو');
    T::ok(str_contains($loc2, 'invoice.php?id='), 'ارسالِ دوم به همان فاکتورِ اول می‌رود', $loc2);

    // فرمِ تازه (نشانِ تازه) فروشِ دوم را می‌سازد — سد فقط تکرار را می‌گیرد
    [, $qs2] = $req('store/quick-sale.php');
    $post['_once'] = $field($qs2, '_once');
    $post['csrf_token'] = $field($qs2, 'csrf_token');
    $req('store/quick-sale.php', $post);
    T::same($before + 2, $sales($w), 'فرمِ تازه فروشِ تازه می‌سازد');

    // شکست نشان را آزاد می‌کند: اصلاح و ارسالِ دوباره پذیرفته است
    [, $qs3] = $req('store/quick-sale.php');
    $bad = $post; $bad['_once'] = $field($qs3, '_once'); $bad['csrf_token'] = $field($qs3, 'csrf_token');
    $bad['lines'] = [['item' => 'شارژر', 'qty' => '999', 'price' => '200']];
    [$c] = $req('store/quick-sale.php', $bad);
    T::same($before + 2, $sales($w), 'فروشِ بیش از موجودی رد شد');
    $bad['lines'] = [['item' => 'شارژر', 'qty' => '1', 'price' => '200']];
    $req('store/quick-sale.php', $bad);
    T::same($before + 3, $sales($w), '⛔ پس از شکست، همان فرمِ اصلاح‌شده ثبت می‌شود (نشان آزاد شد)');

    exec("kill $srv 2>/dev/null");
    @unlink($jar);
}
@unlink($log);

$wipe();
exit(T::report());
