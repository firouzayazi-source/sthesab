<?php
/**
 * ⛔ ربات بله/تلگرام (`Bot`، `admin/bot.php`، بخشِ «ربات» در تنظیماتِ فروشگاه، کانالِ سومِ `BizDaily`).
 *
 *   ۱. ذخیره: شکلِ توکن، `getMe`؛ ⛔ رباتِ وب‌هوک‌دار (برنامه‌ی دیگر) رد؛ توکن رمزشده؛ خالی = دست نزن.
 *   ۲. اتصال فقط با کدِ یک‌بارمصرفِ تازه از گفت‌وگوی خصوصی؛ کهنه/گروهی/اشتباه نه؛ offset فقط بعد از پردازش جلو می‌رود.
 *   ۳. فرستادن فقط به گفت‌وگوی وصل‌شده؛ ⛔ بی‌قلاب و با `HESAB_NO_SEND` هیچ درخواستِ بیرونی؛ توکن در خطا نمی‌آید.
 *   ۴. خلاصه‌ی شبانه به ربات هم می‌رود؛ HTTP: فقط صاحب، و صفحه‌ی مدیر.
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit('Not found.'); }

require_once __DIR__ . '/lib/assert.php';
require_once __DIR__ . '/lib/store_http.php';
T::group('ربات بله و تلگرام');
if (!file_exists(__DIR__ . '/../config/config.php')) { T::blocked('ربات', 'config/config.php وجود ندارد'); exit(T::report()); }
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/signup.php';
require_once __DIR__ . '/../includes/user_data.php';
require_once __DIR__ . '/../includes/bot.php';
require_once __DIR__ . '/../includes/biz.php';
require_once __DIR__ . '/../includes/biz_daily.php';

try { $pdo = Database::getConnection(); } catch (Throwable $e) { T::blocked('ربات', 'اتصال به دیتابیس برقرار نشد'); exit(T::report()); }
if (!Bot::ready()) { T::blocked('ربات', 'migration_bot اجرا نشده'); exit(T::report()); }
const BT_PREFIX = '__bot_';
const BT_PASS   = 'Bot#pass9';
$keep = [];
foreach ([Bot::SET_PROVIDER, Bot::SET_TOKEN, Bot::SET_NAME, Bot::SET_OFFSET] as $k) { $keep[$k] = getSetting($k, ''); }
$wipe = function () use ($pdo, $keep) {
    foreach ($pdo->query("SELECT id FROM users WHERE username LIKE '" . BT_PREFIX . "%'")->fetchAll(PDO::FETCH_COLUMN) as $id) { deleteUserAccount((int)$id); }
    foreach ($keep as $k => $v) { setSetting($k, (string)$v); }
};
$wipe();
register_shutdown_function($wipe);
$mk = function (string $n) use ($pdo): int {
    $r = createUserAccount($pdo, 'صاحب ' . $n, BT_PREFIX . $n, BT_PREFIX . $n . '@example.com', BT_PASS);
    Biz::setType((int)$r['id'], 'business');
    return (int)$r['id'];
};
// ---------- پیام‌رسانِ ساختگی ----------
$calls = []; $updates = []; $webhook = '';
Bot::$httpForTests = function (string $url, array $payload) use (&$calls, &$updates, &$webhook): array {
    $calls[] = [$url, $payload];
    $m = substr($url, strrpos($url, '/') + 1);
    // ⚠ عمداً فقط پیشوند: توکنِ بدشکلی که با همین شروع شود را هم «می‌پذیرد» تا سدِ شکل در خودِ `Bot` سنجیده شود
    if (!str_contains($url, '/bot123456:' . str_repeat('A', 30))) { return ['code' => 401, 'body' => json_encode(['ok' => false, 'description' => 'Unauthorized'])]; }
    return match ($m) {
        'getMe' => ['code' => 200, 'body' => json_encode(['ok' => true, 'result' => ['id' => 1, 'username' => 'hesab_test_bot']])],
        'getWebhookInfo' => ['code' => 200, 'body' => json_encode(['ok' => true, 'result' => ['url' => $webhook]])],
        'getUpdates' => ['code' => 200, 'body' => json_encode(['ok' => true, 'result' => array_values(array_filter($updates, fn($x) => $x['update_id'] >= (int)($payload['offset'] ?? 0)))])],
        'sendMessage' => ['code' => 200, 'body' => json_encode(['ok' => true, 'result' => ['message_id' => 9]])],
        default => ['code' => 404, 'body' => '{"ok":false}'],
    };
};
$TOKEN = '123456:' . str_repeat('A', 30);
$sent = function () use (&$calls) { return array_values(array_filter($calls, fn($c) => str_ends_with($c[0], "/sendMessage"))); };

T::group('۱ — ذخیره');
setSetting(Bot::SET_PROVIDER, ''); setSetting(Bot::SET_TOKEN, '');
T::ok(!Bot::saveConfig('bale', 'not-a-token')['ok'], 'شکلِ نادرست رد');
T::ok(!Bot::saveConfig('bale', $TOKEN . '!<>')['ok'] && Bot::provider() === '', '⛔ نویسه‌ی بیگانه در توکن رد (پیش از هر درخواست)');
T::ok(!Bot::saveConfig('bale', '123456:' . str_repeat('B', 30))['ok'], 'توکنِ ناپذیرفته رد');
$webhook = 'https://other-bot.example/hook';
$r = Bot::saveConfig('bale', $TOKEN);
T::ok(!$r['ok'] && str_contains($r['message'], 'وب‌هوک'), '⛔ رباتِ وب‌هوک‌دار (برنامه‌ی دیگر) رد', $r['message']);
T::same('', Bot::provider(), 'و هیچ چیزی ذخیره نشد');
$webhook = '';
T::ok(Bot::saveConfig('bale', $TOKEN)['ok'], 'توکنِ درست ذخیره شد');
T::ok(Bot::configured() && Bot::username() === 'hesab_test_bot', 'روشن، با نامِ ربات');
T::same('https://ble.ir/hesab_test_bot', Bot::chatUrl(), 'پیوندِ گفت‌وگو');
if (class_exists('Crypto') && Crypto::available()) { T::ok(!str_contains((string)getSetting(Bot::SET_TOKEN, ''), str_repeat('A', 30)), '⛔ توکن رمزشده ذخیره شد'); }
T::ok(Bot::saveConfig('telegram', '')['ok'] && Bot::configured() && Bot::provider() === 'telegram', 'توکنِ خالی = دست نزن');
Bot::saveConfig('bale', '');

T::group('۲ — اتصال');
$u = $mk('a'); $v = $mk('b');
T::same(false, Bot::link($u)['linked'], 'پیش از اتصال');
$code = Bot::code($u);
T::ok((bool)preg_match('/^\d{6}$/', $code) && Bot::link($u)['code'] === $code, 'کدِ ۶ رقمی');
$cv = Bot::code($v);
$pdo->prepare('UPDATE bot_links SET code_at = DATE_SUB(NOW(), INTERVAL 2 HOUR) WHERE user_id = :u')->execute(['u' => $v]);
$updates = [
    ['update_id' => 50, 'message' => ['chat' => ['id' => -100, 'type' => 'group'], 'text' => $code]],
    ['update_id' => 51, 'message' => ['chat' => ['id' => 777, 'type' => 'private'], 'text' => '999999']],
    ['update_id' => 52, 'message' => ['chat' => ['id' => 888, 'type' => 'private'], 'text' => $cv]],
    ['update_id' => 53, 'message' => ['chat' => ['id' => 777, 'type' => 'private'], 'text' => '/start ' . toPersianDigits($code)]],
];
$r = Bot::poll();
T::same(1, $r['linked'], 'یک اتصال');
T::same([true, false], [Bot::link($u)['linked'], Bot::link($v)['linked']], '⛔ کدِ کهنه (۲ ساعت) وصل نمی‌شود');
T::same(777, (int)$pdo->query("SELECT chat_id FROM bot_links WHERE user_id = {$u}")->fetchColumn(), '⛔ گفت‌وگوی خصوصی، نه گروهی که همان کد را فرستاد');
T::same('54', (string)getSetting(Bot::SET_OFFSET, ''), 'offset بعد از آخرین پیام');
T::same(null, Bot::link($u)['code'], 'کد مصرف شد');
$updates[] = ['update_id' => 54, 'message' => ['chat' => ['id' => 666, 'type' => 'private'], 'text' => $code]];
Bot::poll();
T::same(777, (int)$pdo->query("SELECT chat_id FROM bot_links WHERE user_id = {$u}")->fetchColumn(), '⛔ کدِ مصرف‌شده دوباره کار نمی‌کند');
T::ok(str_contains(json_encode($sent()[0][1] ?? [], JSON_UNESCAPED_UNICODE), 'وصل شد'), 'پاسخِ «وصل شد» به همان گفت‌وگو');

T::group('۳ — فرستادن');
$calls = [];
T::ok(Bot::send($u, 'سلام'), 'فرستاده شد');
T::same([777, 'سلام'], [$sent()[0][1]['chat_id'] ?? null, $sent()[0][1]['text'] ?? null], 'به گفت‌وگوی همان کاربر');
T::ok(!Bot::send($v, 'x'), 'بی‌اتصال: نه');
$hook = Bot::$httpForTests;
Bot::$httpForTests = null;
putenv('HESAB_NO_SEND=1');
$calls = [];
T::ok(!Bot::send($u, 'واقعی') && $calls === [], '⛔ بی‌قلاب و با HESAB_NO_SEND هیچ درخواستِ بیرونی');
$pb = Bot::poll();
T::ok(!$pb['ok'] && str_contains($pb['message'], 'HESAB_NO_SEND'), '⛔ و ردش از سدِ `Outbound` است، نه از قطعیِ شبکه', $pb['message']);
Bot::$httpForTests = $hook;
$zt = '123456:' . str_repeat('Z', 30);
setSetting(Bot::SET_TOKEN, class_exists('Crypto') && Crypto::available() ? (string)Crypto::encrypt($zt) : $zt);
$bad = Bot::poll();
T::ok(!$bad['ok'], 'توکنِ باطل‌شده: خطا');
T::ok(!str_contains($bad['message'], str_repeat('Z', 30)), '⛔ توکن در پیامِ خطا نمی‌آید');
Bot::saveConfig('bale', $TOKEN);
Bot::unlink($u);
T::ok(!Bot::send($u, 'x'), 'قطعِ اتصال');

T::group('۴ — خلاصه‌ی شبانه و HTTP');
$code = Bot::code($u);
$updates = [['update_id' => 60, 'message' => ['chat' => ['id' => 555, 'type' => 'private'], 'text' => $code]]];
Bot::poll();
Biz::saveSettings($u, ['shop_name' => 'فروشگاهِ ربات', 'phone' => '', 'address' => '', 'invoice_footer' => '']);
require_once __DIR__ . '/../includes/biz_catalog.php';
BizPay::create($u, ['kind' => 'capital', 'amount' => '5000', 'account_id' => (int)BizCash::list($u)[0]['id']]);
BizDaily::$mailForTests = fn() => true;
$calls = [];
T::same(1, BizDaily::run(true, $u)['sent'], 'خلاصه رفت');
T::ok(($sent()[0][1]['chat_id'] ?? null) === 555 && str_contains((string)($sent()[0][1]['text'] ?? ''), 'فروشگاهِ ربات'), '⛔ خلاصه به ربات هم رسید');
$port = stHttpServer();
T::ok($port > 0, 'سرورِ آزمایشی بالا آمد');
if ($port) {
    $c = stHttpClient($port);
    stHttpLogin($c, BT_PREFIX . 'b', BT_PASS);
    [, $h] = $c('store/settings.php');
    T::ok(str_contains($h, 'id="bot"') && str_contains($h, 'ساختِ کدِ اتصال'), 'بخشِ ربات در تنظیمات');
    $c('store/settings.php', ['csrf_token' => stCsrf($h), 'action' => 'bot_code']);
    T::ok(Bot::link($v)['code'] !== null, 'ساختِ کد از فرم');
    [, $h] = $c('store/settings.php');
    T::ok(str_contains($h, toPersianDigits((string)Bot::link($v)['code'])), 'کد روی صفحه');
    $c('store/settings.php', ['csrf_token' => 'x', 'action' => 'bot_unlink']);
    T::ok(Bot::link($v)['code'] !== null, '⛔ بی‌CSRF هیچ');
}

exit(T::report());
