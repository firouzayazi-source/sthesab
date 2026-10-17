<?php
/**
 * ⛔ انبارگردانی با اسکن (`BizCount`، `store/count.php`، `store-count.js`).
 *
 *   ۱. اسکن بی‌اثر بر موجودی؛ SKU → کالا، IMEI → کالای همان گوشی، بقیه «ناشناس»؛ «کد×۳»؛ IMEIِ تکراری یک بار.
 *   ۲. مقایسه با `BizStock::haveToday()`؛ «فقط اسکن‌شده» شمرده‌نشده را نمی‌آورد، «همه» صفرش می‌کند.
 *   ۳. گوشی: اسکن‌نشده و بیگانه گزارش، هرگز اصلاح.
 *   ۴. اعمال فقط با تأیید، از `adjustTo()`؛ بسته‌شده دیگر اسکن و اعمال نمی‌گیرد؛ لغو بی‌اثر.
 *   ۵. HTTP و جاوااسکریپت (node): Enter به فهرست می‌رود، نه فرم.
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit('Not found.'); }

require_once __DIR__ . '/lib/assert.php';
require_once __DIR__ . '/lib/store_http.php';
T::group('انبارگردانی با اسکن');
if (!file_exists(__DIR__ . '/../config/config.php')) { T::blocked('انبارگردانی', 'config/config.php وجود ندارد'); exit(T::report()); }
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/signup.php';
require_once __DIR__ . '/../includes/user_data.php';
require_once __DIR__ . '/../includes/biz.php';
require_once __DIR__ . '/../includes/biz_catalog.php';
require_once __DIR__ . '/../includes/biz_docs.php';
require_once __DIR__ . '/../includes/biz_count.php';

try { $pdo = Database::getConnection(); } catch (Throwable $e) { T::blocked('انبارگردانی', 'اتصال به دیتابیس برقرار نشد'); exit(T::report()); }
if (!BizCount::ready()) { T::blocked('انبارگردانی', 'migration_biz_stock_count اجرا نشده'); exit(T::report()); }
const SC2_PREFIX = '__store_count_';
const SC2_PASS   = 'Count#pass9';
$wipe = function () use ($pdo) {
    foreach ($pdo->query("SELECT id FROM users WHERE username LIKE '" . SC2_PREFIX . "%'")->fetchAll(PDO::FETCH_COLUMN) as $id) { deleteUserAccount((int)$id); }
};
$wipe();
register_shutdown_function($wipe);
$mk = function (string $n) use ($pdo): int {
    $r = createUserAccount($pdo, 'صاحب ' . $n, SC2_PREFIX . $n, SC2_PREFIX . $n . '@example.com', SC2_PASS);
    Biz::setType((int)$r['id'], 'business');
    return (int)$r['id'];
};
$imei = function (string $b14): string {
    $s = 0; for ($i = 0; $i < 14; $i++) { $d = (int)$b14[13 - $i]; if ($i % 2 === 0) { $d *= 2; if ($d > 9) { $d -= 9; } } $s += $d; }
    return $b14 . (string)((10 - $s % 10) % 10);
};
$u = $mk('a'); $o = $mk('o');
$acc = (int)BizCash::list($u)[0]['id'];
BizPay::create($u, ['kind' => 'capital', 'amount' => '900000000', 'account_id' => $acc]);
$sup = (int)BizParties::save($u, ['name' => 'پخش', 'kind' => 'supplier'])['id'];
$case  = (int)BizProducts::save($u, ['name' => 'قاب', 'sku' => 'CASE01', 'unit' => 'عدد', 'sell_price' => '100'])['id'];
$glass = (int)BizProducts::save($u, ['name' => 'گلس', 'sku' => 'GL01', 'unit' => 'عدد', 'sell_price' => '50'])['id'];
$cable = (int)BizProducts::save($u, ['name' => 'کابل', 'sku' => 'CB01', 'unit' => 'عدد', 'sell_price' => '70'])['id'];
$phone = (int)BizProducts::save($u, ['type' => 'phone', 'name' => 'A55', 'sku' => 'A55', 'unit' => 'دستگاه', 'sell_price' => '200'])['id'];
$oProd = (int)BizProducts::save($o, ['name' => 'دیگری', 'sku' => 'CASE01', 'unit' => 'عدد', 'sell_price' => '1'])['id'];
$P1 = $imei('35391812000101'); $P2 = $imei('35391812000102'); $X = $imei('35391812000199');
$d = BizInvoices::saveDraft($u, 'purchase', ['party_id' => $sup, 'lines' => [
    ['product_id' => $case, 'item' => 'قاب', 'qty' => '5', 'price' => '40'], ['product_id' => $glass, 'item' => 'گلس', 'qty' => '3', 'price' => '20'],
    ['product_id' => $cable, 'item' => 'کابل', 'qty' => '4', 'price' => '30'],
    ['product_id' => $phone, 'item' => 'A55', 'qty' => '1', 'price' => '150', 'imei1' => $P1, 'imei2' => ''],
    ['product_id' => $phone, 'item' => 'A55', 'qty' => '1', 'price' => '150', 'imei1' => $P2, 'imei2' => '']]]);
T::ok(BizInvoices::issue($u, (int)$d['id'], ['full' => true, 'account_id' => $acc])['ok'], 'خریدِ اولیه');
$stock = fn(int $p) => (float)BizProducts::get($u, $p)['stock_qty'];
$moves = fn() => (int)Database::getConnection()->query('SELECT COUNT(*) FROM biz_stock_moves WHERE user_id = ' . (int)$u)->fetchColumn();
$m0 = $moves();

T::group('۱ — اسکن');
$c = BizCount::open($u, null, 'قفسه‌ی ۱', $u);
T::ok($c['ok'], 'باز شد');
$cid = $c['id'];
$r = BizCount::scan($u, $cid, "CASE01\nCASE01\n" . toPersianDigits('CASE01×2') . "\nGL01*4\n{$P1}\n{$P1}\n{$X}\nZZZ-UNKNOWN\n\n");
T::same([7, 1, 2], [$r['added'], $r['dup'], $r['unknown']], 'هفت اسکن، یک IMEIِ تکراری، دو ناشناس (IMEIِ بی‌سند و کدِ بی‌کالا)', $r['message']);
T::same($m0, $moves(), '⛔ اسکن هیچ حرکتِ انباری نساخت');
T::same(5.0, $stock($case), 'موجودی دست نخورد');
$r2 = BizCount::scan($u, $cid, 'A55');
T::same(1, $r2['unknown'], '⛔ SKUِ گوشی بی‌IMEI شمرده نمی‌شود (ناشناس) — گوشی فقط با IMEI');
T::ok(!BizCount::scan($o, $cid, 'CASE01')['ok'], '⛔ فروشگاهِ دیگر در این شمارش اسکن نمی‌کند');
T::same(0, (int)$pdo->query("SELECT COUNT(*) FROM biz_stock_count_lines WHERE product_id = {$oProd}")->fetchColumn(), 'SKUِ هم‌نامِ فروشگاهِ دیگر هرگز');

T::group('۲ — مقایسه');
$df = BizCount::diff($u, $cid);
$by = []; foreach ($df['rows'] as $x) { $by[$x['id']] = $x; }
T::same([5.0, 4.0, -1.0], [$by[$case]['system'], $by[$case]['counted'], $by[$case]['diff']], 'قاب: دفتر ۵، شمارش ۴ (۱+۱+۲)، یکی کم');
T::same(1.0, $by[$glass]['diff'], 'گلس: یکی اضافه');
T::ok(!isset($by[$cable]), '⛔ «فقط اسکن‌شده»: کابلِ شمرده‌نشده نمی‌آید');
$dfa = BizCount::diff($u, $cid, 'all');
$ba = []; foreach ($dfa['rows'] as $x) { $ba[$x['id']] = $x; }
T::same(-4.0, $ba[$cable]['diff'] ?? null, '«همه»: شمرده‌نشده = صفر');
T::same([$P2], array_column($df['missing'], 'imei'), 'گوشیِ در دفتر ولی اسکن‌نشده');
T::same([$X, 'ZZZ-UNKNOWN', 'A55'], array_column($df['unknown'], 'code'), 'ناشناس‌ها (به ترتیبِ اسکن)');
T::same(true, $by[$phone]['serial'], 'گوشی علامت دارد');

T::group('۳ — اعمال');
T::ok(!BizCount::apply($u, $cid, 'scanned', false)['ok'], '⛔ بی‌تأیید نه');
T::same($m0, $moves(), 'بی‌تأیید هیچ حرکتی');
$unk = $df['unknown'][0]['id'];
T::ok(BizCount::unscan($u, $cid, $unk), 'حذفِ اسکنِ اشتباه');
$ap = BizCount::apply($u, $cid, 'scanned', true);
T::ok($ap['ok'], 'اعمال', $ap['message']);
T::same([4.0, 4.0, 4.0, 2.0], [$stock($case), $stock($glass), $stock($cable), $stock($phone)], '⛔ قاب و گلس به شمارش رسیدند؛ کابل (شمرده‌نشده) و گوشی دست نخوردند');
$mv = $pdo->query("SELECT note FROM biz_stock_moves WHERE user_id = {$u} AND kind = 'adjust' ORDER BY id DESC LIMIT 1")->fetchColumn();
T::ok(str_contains((string)$mv, 'انبارگردانیِ شماره‌ی ۱'), 'از مسیرِ انبارگردانی با یادداشت');
T::same('applied', BizCount::get($u, $cid)['status'], 'بسته شد');
T::ok(!BizCount::scan($u, $cid, 'CASE01')['ok'] && !BizCount::apply($u, $cid, 'scanned', true)['ok'], 'بسته‌شده اسکن و اعمال نمی‌گیرد');
T::ok(!BizCount::unscan($u, $cid, $df['unknown'][1]['id']), 'بسته‌شده حذفِ اسکن نمی‌گیرد');
$c2 = BizCount::open($u, null, '', $u)['id'];
BizCount::scan($u, $c2, "CB01*9");
T::ok(BizCount::cancel($u, $c2), 'لغو');
T::same(4.0, $stock($cable), '⛔ لغو هیچ اثری ندارد');
$c3 = BizCount::open($u, null, '', $u)['id'];
BizCount::scan($u, $c3, "CB01*2");
Biz::saveLock($u, date('Y-m-d'));
$ap3 = BizCount::apply($u, $c3, 'scanned', true);
T::ok(!$ap3['ok'] && BizCount::get($u, $c3)['status'] === 'open', '⛔ دوره‌ی بسته: اعمال نشد و شمارش باز ماند', $ap3['message']);
Biz::saveLock($u, '', true);
T::same(2, BizCount::get($u, $c3)['number'] - 1, 'شماره‌ی پیاپی');

T::group('۴ — HTTP و JS');
$port = stHttpServer();
T::ok($port > 0, 'سرورِ آزمایشی بالا آمد');
if ($port) {
    $h1 = stHttpClient($port);
    stHttpLogin($h1, SC2_PREFIX . 'a', SC2_PASS);
    [$code, $h] = $h1('store/count.php?id=' . $c3);
    T::ok($code === 200 && str_contains($h, 'data-count-scan') && str_contains($h, 'store-count.js') && str_contains($h, 'کابل'), 'صفحه‌ی شمارش', (string)$code);
    $h1('store/count.php?id=' . $c3, ['csrf_token' => stCsrf($h), 'action' => 'scan', 'codes' => "GL01\nGL01"]);
    T::same(2, (int)$pdo->query("SELECT COUNT(*) FROM biz_stock_count_lines WHERE count_id = {$c3} AND product_id = {$glass}")->fetchColumn(), 'اسکن از فرم');
    [, $h] = $h1('store/count.php?id=' . $c3);
    $h1('store/count.php?id=' . $c3, ['csrf_token' => stCsrf($h), 'action' => 'apply', 'scope' => 'scanned']);
    T::same('open', BizCount::get($u, $c3)['status'], '⛔ بی‌تیکِ تأیید از فرم هم نه');
    $h1('store/count.php?id=' . $c3, ['csrf_token' => 'x', 'action' => 'apply', 'scope' => 'scanned', 'confirm' => '1']);
    T::same('open', BizCount::get($u, $c3)['status'], '⛔ بی‌CSRF هیچ');
    $sel = $mk('sel');
    $pdo->prepare("INSERT INTO store_staff (user_id, staff_id, role) VALUES (:o, :s, 'seller')")->execute(['o' => $u, 's' => $sel]);
    $h2 = stHttpClient($port);
    stHttpLogin($h2, SC2_PREFIX . 'sel', SC2_PASS);
    [$code, $h] = $h2('store/count.php?id=' . $c3);
    T::ok($code !== 200 || !str_contains($h, 'data-count-scan'), '⛔ فروشنده (بی‌«کالا») نه', (string)$code);
}
$node = trim((string)shell_exec('command -v node 2>/dev/null'));
if ($node === '') { T::skip('store-count.js', 'node نیست'); exit(T::report()); }
$js = 'const L={};class E{constructor(t,o){this.type=t;this.key=(o||{}).key;this.p=false;}preventDefault(){this.p=true}stopImmediatePropagation(){}}'
    . 'const inp={value:"",l:{},addEventListener(t,f){this.l[t]=f},focus(){}};const list={value:"",l:{},addEventListener(t,f){this.l[t]=f}};const n={textContent:""};'
    . 'const form={querySelector(s){return s==="[data-count-scan]"?inp:s==="[data-count-list]"?list:n}};'
    . 'global.document={querySelector(s){return s==="[data-count-form]"?form:null}};global.navigator={};'
    . 'eval(require("fs").readFileSync(' . json_encode(__DIR__ . '/../assets/js/store-count.js') . ',"utf8"));'
    . 'const out=[];for(const v of ["CASE01"," GL01 ",""]){inp.value=v;const e=new E("keydown",{key:"Enter"});inp.l.keydown(e);out.push(e.p)}'
    . 'console.log(JSON.stringify({list:list.value,n:n.textContent,prevented:out,inp:inp.value}))';
$res = json_decode((string)shell_exec(escapeshellarg($node) . ' -e ' . escapeshellarg($js) . ' 2>&1'), true);
T::same("CASE01\nGL01\n", $res['list'] ?? null, 'Enter کد را به فهرست می‌برد (خالی نه)');
T::same(['۲', [true, true, true], ''], [$res['n'] ?? null, $res['prevented'] ?? null, $res['inp'] ?? null], '⛔ Enter فرم را نمی‌فرستد؛ شمار و پاک شدنِ خانه');

exit(T::report());
