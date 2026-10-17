<?php
/**
 * ⛔ خروجیِ کالاها برای ترب و سایت‌های مقایسه (`BizFeed`، `store/feed.php`، `store/item.php`، بخشِ «خروجی» در `price-list.php`).
 *
 *   ۱. پیش‌فرض خاموش؛ روشن/خاموش/باطل با نمکِ خودش (جدا از لیستِ قیمت).
 *   ۲. ردیف‌ها: فقط صافیِ ذخیره‌شده؛ ⛔ نه قیمتِ خرید، نه شمارِ موجودی، نه خدمت/غیرفعال/بی‌قیمت.
 *   ۳. امضا: فروشگاهِ دیگر (حتی هم‌نمک)، کالای دیگر، کالای بیرون از صافی ۴۰۴.
 *   ۴. HTTP: JSON و CSV (BOM، سدِ فرمول)، صفحه‌ی کالا، و فرمِ تنظیم.
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit('Not found.'); }

require_once __DIR__ . '/lib/assert.php';
require_once __DIR__ . '/lib/store_http.php';
T::group('خروجیِ کالاها');
if (!file_exists(__DIR__ . '/../config/config.php')) { T::blocked('خروجی', 'config/config.php وجود ندارد'); exit(T::report()); }
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/signup.php';
require_once __DIR__ . '/../includes/user_data.php';
require_once __DIR__ . '/../includes/biz.php';
require_once __DIR__ . '/../includes/biz_catalog.php';
require_once __DIR__ . '/../includes/biz_docs.php';
require_once __DIR__ . '/../includes/biz_feed.php';
require_once __DIR__ . '/../includes/biz_pricelist.php';

try { $pdo = Database::getConnection(); } catch (Throwable $e) { T::blocked('خروجی', 'اتصال به دیتابیس برقرار نشد'); exit(T::report()); }
if (!BizFeed::ready()) { T::blocked('خروجی', 'migration_biz_feed اجرا نشده'); exit(T::report()); }
const FD_PREFIX = '__store_feed_';
const FD_PASS   = 'Feed#pass9';
$wipe = function () use ($pdo) {
    foreach ($pdo->query("SELECT id FROM users WHERE username LIKE '" . FD_PREFIX . "%'")->fetchAll(PDO::FETCH_COLUMN) as $id) { deleteUserAccount((int)$id); }
};
$wipe();
register_shutdown_function($wipe);
$mk = function (string $n) use ($pdo): int {
    $r = createUserAccount($pdo, 'صاحب ' . $n, FD_PREFIX . $n, FD_PREFIX . $n . '@example.com', FD_PASS);
    Biz::setType((int)$r['id'], 'business');
    return (int)$r['id'];
};
$u = $mk('a'); $o = $mk('o');
Biz::saveSettings($u, ['shop_name' => 'موبایلِ خروجی', 'phone' => '02144444444', 'address' => 'خیابانِ نمونه', 'invoice_footer' => '']);
$acc = (int)BizCash::list($u)[0]['id'];
BizPay::create($u, ['kind' => 'capital', 'amount' => '900000000', 'account_id' => $acc]);
$sup = (int)BizParties::save($u, ['name' => 'پخش', 'kind' => 'supplier'])['id'];
$A = (int)BizProducts::save($u, ['name' => 'Galaxy A55', 'unit' => 'عدد', 'buy_price' => '21123456', 'sell_price' => '24500000'])['id'];
$B = (int)BizProducts::save($u, ['name' => '=HYPERLINK("x")', 'unit' => 'عدد', 'sell_price' => '150000'])['id'];
$C = (int)BizProducts::save($u, ['name' => 'بی‌قیمت', 'unit' => 'عدد', 'sell_price' => '0'])['id'];
$D = (int)BizProducts::save($u, ['name' => 'ناموجود', 'unit' => 'عدد', 'sell_price' => '99000'])['id'];
$S = (int)BizProducts::save($u, ['type' => 'service', 'name' => 'تعمیرِ صفحه', 'unit' => 'عدد', 'sell_price' => '500000'])['id'];
$d = BizInvoices::saveDraft($u, 'purchase', ['party_id' => $sup, 'lines' => [
    ['product_id' => $A, 'item' => 'Galaxy A55', 'qty' => '7', 'price' => '21123456'], ['product_id' => $B, 'item' => '=HYPERLINK("x")', 'qty' => '3', 'price' => '60000'],
    ['product_id' => $C, 'item' => 'بی‌قیمت', 'qty' => '1', 'price' => '10']]]);
BizInvoices::issue($u, (int)$d['id'], ['full' => true, 'account_id' => $acc]);

T::group('۱ — روشن و خاموش');
$cfg = BizFeed::config($u);
T::ok(!BizFeed::on($cfg) && BizFeed::verifyFeed($u, BizFeed::feedToken($u, '')) === null, 'پیش‌فرض خاموش');
T::ok(BizFeed::save($u, ['in_stock' => '1'], true)['ok'], 'روشن');
$cfg = BizFeed::config($u);
$salt = $cfg['salt'];
T::ok(BizFeed::on($cfg) && BizFeed::verifyFeed($u, BizFeed::feedToken($u, $salt)) !== null, 'امضای درست');
BizPriceList::save($u, ['levels' => ['retail']]);
T::ok(BizPriceList::config($u)['salt'] !== $salt, 'نمکِ جدا از لیستِ قیمت');

T::group('۲ — ردیف‌ها');
$rows = BizFeed::rows($u, $cfg);
T::same([$A, $B], array_column($rows, 'id'), '⛔ فقط موجودِ قیمت‌دارِ انباری (نه بی‌قیمت، نه ناموجود، نه خدمت)');
T::same(['id', 'name', 'category', 'price', 'available'], array_keys($rows[0]), '⛔ هیچ ستونِ دیگری (قیمتِ خرید، موجودی)');
$it = BizFeed::item($u, $rows[0], $salt);
T::same(['p' . $A, 24500000, 245000000, 'instock'], [$it['id'], $it['price'], $it['price_rial'], $it['availability']], 'قالبِ قلم');
BizFeed::save($u, [], true);
$all = array_column(BizFeed::rows($u, BizFeed::config($u)), 'id');
T::ok(in_array($D, $all, true) && !in_array($S, $all, true), 'بی‌«فقط موجود»: ناموجود هم (با برچسب) — ⛔ ولی خدمت هرگز');
BizFeed::save($u, ['in_stock' => '1'], true);

T::group('۳ — امضا');
$tok = BizFeed::feedToken($u, $salt);
T::ok(BizFeed::verifyFeed($o, $tok) === null, '⛔ فروشگاهِ دیگر نه');
$pdo->prepare("INSERT INTO biz_settings (user_id, shop_name, feed) VALUES (:o, '', :j) ON DUPLICATE KEY UPDATE feed = VALUES(feed)")
    ->execute(['o' => $o, 'j' => json_encode(['salt' => $salt, 'in_stock' => true])]);
Biz::forgetSettings($o); unset(Biz::$feedRaw[$o]);
T::ok(BizFeed::verifyFeed($o, $tok) === null, '⛔ هم‌نمک ولی فروشگاهِ دیگر نه');
T::ok(BizFeed::verifyItem($u, $A, BizFeed::itemToken($u, $A, $salt)) !== null, 'صفحه‌ی کالا');
T::ok(BizFeed::verifyItem($u, $B, BizFeed::itemToken($u, $A, $salt)) === null, '⛔ امضای کالای دیگر نه');
T::ok(BizFeed::verifyItem($u, $D, BizFeed::itemToken($u, $D, $salt)) === null, '⛔ کالای بیرون از صافی (ناموجود) نه، حتی با امضای درست');
T::ok(BizFeed::verifyItem($u, $S, BizFeed::itemToken($u, $S, $salt)) === null, '⛔ خدمت نه');

T::group('۴ — HTTP');
$port = stHttpServer();
T::ok($port > 0, 'سرورِ آزمایشی بالا آمد');
if (!$port) { exit(T::report()); }
$anon = stHttpClient($port);
$rel = fn(string $url) => substr($url, strpos($url, 'store/'));
[$c, $h] = $anon($rel(BizFeed::feedUrl($u)));
$j = json_decode($h, true);
T::ok($c === 200 && ($j['count'] ?? 0) === 2 && ($j['shop'] ?? '') === 'موبایلِ خروجی', 'JSON', (string)$c);
T::ok(!str_contains($h, '21123456') && !str_contains($h, '"stock'), '⛔ نه قیمتِ خرید، نه موجودی');
[$c, $ih] = $anon($rel((string)($j['products'][0]['url'] ?? '')));
T::ok($c === 200 && str_contains($ih, 'Galaxy A55') && str_contains($ih, formatMoney(24500000)) && str_contains($ih, 'موجود') && str_contains($ih, 'خیابانِ نمونه'), 'صفحه‌ی کالا از آدرسِ درونِ خروجی');
T::ok(!str_contains($ih, '/store/index.php') && !str_contains($ih, 'login.php'), 'بی‌لینک به داخلِ فروشگاه');
[$c, $csv] = $anon($rel(BizFeed::feedUrl($u, 'csv')));
T::ok($c === 200 && str_starts_with($csv, "\xEF\xBB\xBF") && str_contains($csv, "'=HYPERLINK"), 'CSV با BOM و ⛔ سدِ فرمول');
[$c] = $anon('store/feed.php?' . http_build_query(['s' => $u, 't' => 'x' . substr($tok, 1)]));
T::same(404, $c, 'امضای دست‌کاری‌شده: ۴۰۴');
$cl = stHttpClient($port);
stHttpLogin($cl, FD_PREFIX . 'a', FD_PASS);
[, $h] = $cl('store/price-list.php');
T::ok(str_contains($h, 'id="feed"') && str_contains($h, h(BizFeed::feedUrl($u))), 'بخشِ خروجی در «لیستِ قیمت»');
$cl('store/price-list.php', ['csrf_token' => stCsrf($h), 'action' => 'feed_rotate', 'in_stock' => '1']);
Biz::forgetSettings($u); unset(Biz::$feedRaw[$u]);
[$c] = $anon($rel((string)($j['products'][0]['url'] ?? '')));
T::same(404, $c, '⛔ «باطل کردن» صفحه‌ی کالاها را هم می‌بندد');
[, $h] = $cl('store/price-list.php');
$cl('store/price-list.php', ['csrf_token' => stCsrf($h), 'action' => 'feed']);
Biz::forgetSettings($u); unset(Biz::$feedRaw[$u]);
T::ok(!BizFeed::on(BizFeed::config($u)), 'خاموش از فرم');
T::same(true, BizPriceList::on(BizPriceList::config($u)), 'لیستِ قیمت دست نخورد');

exit(T::report());
