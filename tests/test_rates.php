<?php
/**
 * نرخِ روزِ ارز، طلا و سکه (`includes/rates.php`، `includes/biz_rates.php`).
 *
 * **خواسته‌ی مالکِ نصب:** «کلی و دقیق و راحت… هم با کلید هم بدونِ کلید…
 * ارزشِ دارایی به‌روز… و در فروشگاه برای تعیینِ قیمتِ کالاها.»
 *
 * سه لایه:
 *   ۱. خالص (بی‌دیتابیس): خواندنِ پاسخِ هر سه منبع و منبعِ دلخواه، عدد از هر
 *      شکلی، نرخِ ساختنی (اونس ⇐ گرم، ۲۴ ⇐ ۱۸، مثقال)، سدِ جهش، قیمتِ ارزی.
 *   ۲. دریافت با HTTPِ ساختگی (`Rates::$httpForTests`): ترتیبِ منبع‌ها،
 *      صدا نزدنِ منبعِ لازم‌نشده، سقفِ ماهانه، ماندنِ آخرین نرخِ سالم، رد شدنِ
 *      جهش، نرخِ دستی که خودکار رویش نمی‌نویسد، کلیدِ رمزشده، سدِ SSRF.
 *   ۳. مصرف‌کننده‌ها: نوعِ داراییِ وصل‌شده (فقط همان کاربر) و قیمتِ فروشِ
 *      کالای ارزی (فقط همان فروشگاه، گردکردن، ورود از فایل وصل را نمی‌بُرد).
 *
 * ⛔ جدول‌های نرخ سراسری‌اند، پس پیش از هر چیز عکس گرفته و در `finally`
 *    برگردانده می‌شوند — تست نرخِ واقعیِ ماشینِ توسعه را پاک نمی‌کند.
 */

if (PHP_SAPI !== 'cli') { http_response_code(404); exit('Not found.'); }

require_once __DIR__ . '/lib/assert.php';
$root = realpath(__DIR__ . '/..');
if (!file_exists($root . '/config/config.php')) { T::blocked('نرخِ روز', 'config/config.php وجود ندارد'); exit(T::report()); }
require_once $root . '/includes/db.php';
require_once $root . '/includes/functions.php';
require_once $root . '/includes/rates.php';
require_once $root . '/includes/biz_rates.php';

// ---------------------------------------------------------------
T::group('نرخ — خواندنِ پاسخِ منبع‌ها');

$brs = json_encode([
    'gold' => [
        ['symbol' => 'IR_GOLD_18K', 'name' => 'طلای ۱۸ عیار', 'price' => 7250000, 'unit' => 'تومان'],
        ['symbol' => 'IR_GOLD_24K', 'price' => '9,666,000'],
        ['symbol' => 'IR_COIN_EMAMI', 'price' => '۷۸۵۰۰۰۰۰'],
        ['symbol' => 'IR_COIN_HALF', 'price' => 420000000, 'unit' => 'ریال'],
    ],
    'currency' => [['symbol' => 'USD', 'price' => '105000'], ['symbol' => 'XYZ', 'price' => 5]],
    'cryptocurrency' => [['symbol' => 'BTC', 'price' => 1]],
], JSON_UNESCAPED_UNICODE);
T::same(['gold18' => 7250000, 'gold24' => 9666000, 'coin_emami' => 78500000, 'coin_half' => 42000000, 'usd' => 105000],
    Rates::parse('brsapi', $brs), 'BrsApi: نماد ⇒ کد، جداکننده و رقمِ فارسی، ردیفِ «ریال» تقسیم بر ۱۰، نمادِ ناشناخته دور');

$nav = json_encode(['usd_sell' => ['value' => '105,300', 'change' => 1], '18ayar' => ['value' => '7260000'],
                    'sekkeh' => ['value' => '78600000'], 'harat_naghdi_sell' => ['value' => '1'], 'eur' => '120000']);
T::same(['usd' => 105300, 'eur' => 120000, 'gold18' => 7260000, 'coin_emami' => 78600000],
    Rates::parse('navasan', $nav), 'نوسان: کلید ⇒ کد (value یا عددِ خالی)، کلیدِ ناشناخته دور');
T::same(['usd' => 263200, 'aed' => 72350, 'usdt' => 261750],
    Rates::parse('navasan', json_encode(['usd' => ['value' => '263200'], 'aed' => ['value' => '72350'], 'usdt' => ['value' => '261750']])),
    'نوسان: نامِ دوم (usd، aed) وقتی نامِ اول در پاسخ نیست');
T::same(105300, Rates::parse('navasan', json_encode(['usd' => ['value' => '999999'], 'usd_sell' => ['value' => '105300']]))['usd'],
    'و نامِ اول (usd_sell) بر دومی مقدم است، هر جای پاسخ که باشد');

$bitTickers = json_encode([['symbol' => 'USDT_IRT', 'price' => '106000'], ['symbol' => 'PAXG_IRT', 'price' => '300000000'],
                           ['symbol' => 'BTC_IRT', 'price' => '9']]);
$bitMarkets = json_encode(['count' => 2, 'results' => [['code' => 'USDT_IRT', 'price' => '106000'], ['code' => 'PAXG_IRT', 'price' => '300000000']]]);
$g24 = (int)round(300000000 / Rates::OUNCE_GRAMS);
T::same(['usdt' => 106000, '@gold24' => $g24], Rates::parse('bitpin', $bitTickers), 'بیت‌پین (tickers): تتر و اونس ⇒ گرمِ ۲۴');
T::same(['usdt' => 106000, '@gold24' => $g24], Rates::parse('bitpin', $bitMarkets), 'بیت‌پین (markets، شکلِ قدیمی) همان نتیجه');
T::same($g24, Rates::parse('bitpin', json_encode([['symbol' => 'PAXG_IRT', 'price' => '300000000'], ['symbol' => 'XAUT_IRT', 'price' => '290000000']]))['@gold24'],
    'دو نماد برای یک کد: اولی برنده است (PAXG، نه XAUT که بعدش آمد)');
T::same(['usd' => 105000, 'gold18' => 7200000],
    Rates::parse('custom', json_encode(['data' => ['d' => ['p' => '1050000']], 'g' => [['x' => 72000000]]]), true,
        ['usd' => 'data.d.p', 'gold18' => 'g.0.x', 'eur' => 'nope.x', 'bogus' => 'data.d.p']),
    'منبعِ دلخواه: مسیرِ نقطه‌دار (با اندیسِ آرایه)، ریال ⇒ تومان، مسیرِ ناموجود و کدِ ناشناخته دور');
T::same([], Rates::parse('brsapi', '<html>blocked</html>'), 'پاسخِ غیرِ JSON = هیچ نرخی');
T::same([], Rates::parse('navasan', json_encode(['usd_sell' => ['value' => '0'], 'eur' => '-5', '18ayar' => 'abc'])), 'صفر، منفی و متن = هیچ نرخی');
T::same([null, 60500.0, 60500.5, 7.0, null], [Rates::num(''), Rates::num('۶۰٬۵۰۰'), Rates::num('60500.5'), Rates::num(7), Rates::num('1e5')], 'num()');

T::same('http://api.navasan.tech/latest/?api_key={key}', Rates::expandUrl('navasan', 'http://api.navasan.tech'),
    '⛔ آدرسِ جایگزینِ «فقط پایه» (همان که مالکِ نصب نوشت) ادامه‌ی آدرسِ پیش‌فرض را می‌گیرد');
T::same('http://api.navasan.tech/latest/?api_key={key}', Rates::expandUrl('navasan', 'http://api.navasan.tech/'), 'با اسلشِ آخر هم');
T::same('https://relay.example.com/n.php?k={key}', Rates::expandUrl('navasan', 'https://relay.example.com/n.php?k={key}'), 'آدرسِ کامل دست نمی‌خورد');
T::same('https://x.example/latest/', Rates::expandUrl('navasan', 'https://x.example/latest/'), 'آدرسِ مسیردار هم');
T::same('https://a.example', Rates::expandUrl('custom', 'https://a.example'), 'منبعِ بی‌آدرسِ پیش‌فرض (دلخواه) دست نمی‌خورد');

// ---------------------------------------------------------------
T::group('نرخ — وصلِ خودکارِ نوعِ دارایی از روی نام و واحد');
$guessBad = [];
foreach ([['دلار', 'دلار', 'usd'], ['دلار آمریکا', 'عدد', 'usd'], ['دلار کانادا', 'دلار', null], ['دلار', 'گرم', null],
          ['طلا (۱۸ عیار)', 'گرم', 'gold18'], ['طلا', 'گرم', 'gold18'], ['طلای ۲۴ عیار', 'گرم', 'gold24'],
          ['طلا', 'مثقال', 'mesghal'], ['طلا', 'سوت', null], ['طلای آب‌شده', 'گرم', null],
          ['سکه تمام', 'عدد', 'coin_emami'], ['سکه امامی', 'عدد', 'coin_emami'], ['نیم سکه', 'عدد', 'coin_half'],
          ['ربع سکه', 'عدد', 'coin_quarter'], ['سکه گرمی', 'عدد', 'coin_gram'], ['سکه بهار آزادی', 'عدد', 'coin_bahar'],
          ['سکه', 'گرم', null], ['سکه', 'عدد', null], ['نقره', 'گرم', null], ['یورو', 'یورو', 'eur'], ['تتر', 'تتر', 'usdt'],
          ['ملک', 'متر', null]] as [$n, $u, $want]) {
    $got = Rates::guessCode($n, $u);
    if ($got !== $want) { $guessBad[] = "{$n}/{$u} ⇒ " . var_export($got, true) . ' (انتظار ' . var_export($want, true) . ')'; }
}
T::bulk(22, $guessBad, '⛔ guessCode: نام و واحد هر دو باید بخوانند (طلا/مثقال ≠ گرمِ ۱۸، سکه/گرم هیچ)');
$defBad = [];
foreach (defaultAssetTypes() as $at) {
    if (($at['rate'] ?? null) !== Rates::guessCode($at['name'], $at['unit'])) { $defBad[] = $at['name']; }
}
T::bulk(count(defaultAssetTypes()), $defBad, 'نوع‌های پیش‌فرض همان وصلی را دارند که guessCode می‌دهد');

// ---------------------------------------------------------------
T::group('نرخ — نرخِ ساختنی و سدِ جهش');
$got = ['@gold24' => [$g24, 'bitpin'], 'usdt' => [106000, 'bitpin']];
Rates::derive($got);
T::same($g24, $got['gold24'][0], 'گرمِ ۲۴ از اونس');
T::same((int)round($g24 * 0.75), $got['gold18'][0], 'گرمِ ۱۸ = ۲۴ × ۰٫۷۵');
T::same((int)round($got['gold18'][0] * 4.3318), $got['mesghal'][0], 'مثقال = گرمِ ۱۸ × ۴٫۳۳۱۸');
T::ok(str_contains($got['gold18'][1], 'محاسبه‌شده') && str_starts_with($got['gold18'][1], 'bitpin'), 'منبعِ ساختنی «محاسبه‌شده» علامت می‌خورد', $got['gold18'][1]);
T::ok(!isset($got['usd']) && !isset($got['@gold24']), '⛔ دلار از تتر ساخته نمی‌شود؛ کلیدِ کمکیِ اونس نمی‌ماند');
$got = ['gold18' => [7200000, 'navasan'], 'gold24' => [9700000, 'brsapi']];
Rates::derive($got);
T::same(9700000, $got['gold24'][0], 'نرخِ مستقیم بر ساختنی مقدم است');

$fresh = ['price' => 100000, 'fetched_at' => date('Y-m-d H:i:s', time() - 3600)];
T::same(null, Rates::jumpError(140000, $fresh), '۴۰٪ پذیرفته');
T::ok(Rates::jumpError(160000, $fresh) !== null, '⛔ ۶۰٪ رد');
T::ok(Rates::jumpError(40000, $fresh) !== null, '⛔ افتِ ۶۰٪ رد');
T::ok(str_contains((string)Rates::jumpError(1000000, $fresh), 'ریال'), 'ده‌برابر: «شاید ریال» گفته می‌شود');
T::same(null, Rates::jumpError(300000, ['price' => 100000, 'fetched_at' => date('Y-m-d H:i:s', time() - 8 * 86400)]), 'نرخِ قبلیِ کهنه‌تر از هفت روز مبنا نیست');
T::same(null, Rates::jumpError(100000, null), 'اولین نرخ پذیرفته');
T::ok(Rates::jumpError(0, null) !== null, 'صفر رد');

// ---------------------------------------------------------------
T::group('نرخ — قیمتِ فروشِ ارزی');
T::same(27563000, BizRates::priceFor(250, 105000, 5, 1000), '۲۵۰ دلار × ۱۰۵٬۰۰۰ × ۱٫۰۵ ⇒ گرد به هزار');
T::same(27560000, BizRates::priceFor(250, 105000, 5, 10000), 'گرد به ده هزار');
T::same(27562500, BizRates::priceFor(250, 105000, 5, 1), 'بی‌گرد');
T::same(25200000, BizRates::priceFor(3.5, 7200000, 0, 1000), '۳٫۵ گرم طلا بی‌سود');
T::same(23625000, BizRates::priceFor(250, 105000, -10, 1000), 'سودِ منفی (تخفیف)');
T::same(27563000, BizRates::priceFor(250, 105000, 5, 777), 'گردکردنِ ناشناخته ⇒ هزار');

// ---------------------------------------------------------------
T::group('نرخ — دریافت');
try { $pdo = Database::getConnection(); }
catch (Throwable $e) { T::blocked('نرخِ روز', 'اتصال به دیتابیس برقرار نشد'); exit(T::report()); }
if (!Rates::available() || !tableHasColumn('asset_types', 'rate_code') || !tableHasColumn('biz_products', 'rate_code')) {
    T::blocked('نرخِ روز', 'migration_rates اجرا نشده'); exit(T::report());
}

$snapRates = $pdo->query('SELECT * FROM market_rates')->fetchAll();
$snapSrc   = $pdo->query('SELECT * FROM rate_sources')->fetchAll();
$restore = function () use ($pdo, $snapRates, $snapSrc) {
    $pdo->exec('DELETE FROM market_rates');
    $pdo->exec('DELETE FROM rate_sources');
    foreach ([['market_rates', $snapRates], ['rate_sources', $snapSrc]] as [$t, $rows]) {
        foreach ($rows as $r) {
            $cols = array_keys($r);
            $pdo->prepare('INSERT INTO ' . $t . ' (' . implode(',', array_map(fn($c) => "`$c`", $cols)) . ') VALUES ('
                . implode(',', array_map(fn($c) => ':' . $c, $cols)) . ')')->execute($r);
        }
    }
};
$USERS = ['__rate_a', '__rate_b'];
$purge = function () use ($pdo, $USERS) {
    require_once __DIR__ . '/../includes/user_data.php';
    foreach ($USERS as $un) {
        $st = $pdo->prepare('SELECT id FROM users WHERE username = :u');
        $st->execute(['u' => $un]);
        foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $id) {
            foreach (['biz_products', 'biz_settings', 'assets', 'asset_types'] as $t) {
                try { $pdo->prepare("DELETE FROM `$t` WHERE user_id = :u")->execute(['u' => $id]); } catch (Throwable $e) {}
            }
            try { deleteUserAccount((int)$id); } catch (Throwable $e) {}
            try { $pdo->prepare('DELETE FROM users WHERE id = :i')->execute(['i' => $id]); } catch (Throwable $e) {}
        }
    }
};

try {
    $pdo->exec('DELETE FROM market_rates');
    $pdo->exec('DELETE FROM rate_sources');
    Rates::forget();

    $src = Rates::sources();
    T::same(['brsapi', 'navasan', 'bitpin', 'custom'], array_keys($src), 'ترتیبِ پیش‌فرض');
    T::same(['bitpin'], array_keys(array_filter($src, fn($s) => $s['enabled'])), 'پیش‌فرض فقط بیت‌پین روشن (بی‌کلید)');

    // --- HTTPِ ساختگی ---
    $calls = [];
    $fake = [];   // پیشوندِ آدرس ⇒ پاسخ
    Rates::$httpForTests = function (string $url) use (&$calls, &$fake): array {
        $calls[] = $url;
        foreach ($fake as $prefix => $resp) {
            if (str_starts_with($url, $prefix)) { return is_string($resp) ? ['ok' => true, 'body' => $resp] : $resp; }
        }
        return ['ok' => false, 'message' => 'به سایت وصل نشد (fake).'];
    };

    // کلید و آدرس
    $r = Rates::saveSource('brsapi', ['enabled' => 1, 'priority' => 10, 'api_key' => 'SECRET-KEY-1']);
    T::ok($r['ok'], 'ذخیره‌ی BrsApi با کلید', $r['message']);
    $raw = (string)$pdo->query("SELECT api_key FROM rate_sources WHERE provider = 'brsapi'")->fetchColumn();
    T::ok(Crypto::available() ? ($raw !== 'SECRET-KEY-1' && str_starts_with($raw, Crypto::PREFIX)) : $raw === 'SECRET-KEY-1',
        Crypto::available() ? '⛔ کلید رمزشده ذخیره می‌شود' : 'بی‌کلیدِ رمزنگاری، خام (رفتارِ Crypto)');
    // ⚠ روی ماشینی که `APP_ENCRYPTION_KEY` ندارد بالایی خام را می‌پذیرد، پس خودِ
    //   مسیرِ نوشتن هم سنجیده می‌شود: کلید فقط از `Crypto::encrypt()` به دیتابیس می‌رود.
    T::ok(str_contains((string)file_get_contents($root . '/includes/rates.php'), "\$par['k'] = Crypto::encrypt(\$key);"),
        '⛔ saveSource() کلید را از Crypto::encrypt() می‌گذراند');
    Rates::saveSource('brsapi', ['enabled' => 1, 'priority' => 10, 'api_key' => '']);
    T::ok(Rates::sources()['brsapi']['has_key'], 'کلیدِ خالی یعنی «دست نزن»');
    T::ok(!Rates::saveSource('custom', ['enabled' => 1, 'url' => 'http://127.0.0.1/rates'])['ok'], '⛔ آدرسِ داخلی (SSRF) رد');
    T::ok(!Rates::saveSource('custom', ['enabled' => 1, 'url' => 'ftp://x.example/a'])['ok'], '⛔ فقط http(s)');
    T::ok(!Rates::saveSource('custom', ['enabled' => 1, 'url' => ''])['ok'], 'منبعِ دلخواهِ روشن بی‌آدرس رد');
    T::ok(!Rates::saveSource('custom', ['enabled' => 0, 'url' => '', 'paths' => ['usd' => 'a;DROP']])['ok'], 'مسیرِ نامعتبر رد');
    T::ok(!Rates::saveSource('nope', [])['ok'], 'منبعِ ناشناخته رد');
    // ⛔ کلیدِ کپی‌شده از تلگرامِ گوشی: نشانه‌ی جهت، نیم‌فاصله، فاصله و شکستِ خط
    Rates::saveSource('navasan', ['enabled' => 1, 'priority' => 20, 'api_key' => "\u{200F}NAV KEY\u{200C}\n\u{200E}"]);
    T::same('NAVKEY', Rates::cleanKey("\u{200F}NAV KEY\u{200C}\n\u{200E}"), 'cleanKey: هیچ فاصله و نویسه‌ی نامرئی‌ای نمی‌ماند');
    Rates::saveSource('bitpin', ['enabled' => 1, 'priority' => 30]);

    $fake = [
        'https://BrsApi.ir/Api/Market/Gold_Currency.php?key=SECRET-KEY-1' => $brs,
        'https://api.navasan.tech/latest/?api_key=NAVKEY' => $nav,
        'https://api.bitpin.ir/api/v1/mkt/tickers/' => $bitTickers,
    ];
    $res = Rates::refresh();
    T::ok(in_array('https://BrsApi.ir/Api/Market/Gold_Currency.php?key=SECRET-KEY-1', $calls, true), 'کلید رمزگشایی و در آدرس گذاشته شد');
    $rates = Rates::all();
    T::same(105000, (int)$rates['usd']['price'], 'دلار از اولین منبع (BrsApi)، نه نوسان');
    T::same('brsapi', $rates['usd']['source'], 'و منبعش ثبت شد');
    T::same(120000, (int)$rates['eur']['price'], 'یورو که BrsApi نداشت از نوسان');
    T::same(106000, (int)$rates['usdt']['price'], 'تتر از بیت‌پین');
    T::same(9666000, (int)$rates['gold24']['price'], 'گرمِ ۲۴ مستقیم از BrsApi، نه ساختنی از اونس');
    T::same((int)round(7250000 * 4.3318), (int)$rates['mesghal']['price'], 'مثقال ساخته شد (هیچ منبعی نداشت)');
    T::ok(!isset($rates['usd']) || $rates['usd']['source'] !== 'bitpin', 'دلار هرگز از تتر');
    T::same(1, (int)$pdo->query("SELECT month_calls FROM rate_sources WHERE provider = 'navasan'")->fetchColumn(), 'یک درخواستِ نوسان شمرده شد');

    // ⛔ https نوسان جواب نداد ⇒ http (نشانیِ راهنمای خودِ نوسان)؛ و آدرسِ «فقط پایه»
    $fakeBak = $fake;
    // خطای هر دو آدرس، با تکه‌ای از پاسخ و کلیدِ پوشانده
    $fake = ['https://api.navasan.tech/' => ['ok' => false, 'message' => 'به سایت وصل نشد (timeout).'],
             'http://api.navasan.tech/'  => ['ok' => false, 'message' => 'سایت پاسخِ 401 داد.', 'body' => '{"error":"invalid api_key NAVKEY"}']];
    $rn = Rates::fetchOne('navasan');
    T::ok(!$rn['ok'] && str_contains($rn['error'], 'https: به سایت وصل نشد') && str_contains($rn['error'], 'http: سایت پاسخِ 401 داد')
        && str_contains($rn['error'], 'invalid api_key ••••') && !str_contains($rn['error'], 'NAVKEY'),
        '⛔ خطا: هر دو آدرس، با پاسخِ خودِ سرویس، و کلید پوشانده', $rn['error']);
    $fake = $fakeBak;
    unset($fake['https://api.navasan.tech/latest/?api_key=NAVKEY']);
    $fake['http://api.navasan.tech/latest/?api_key=NAVKEY'] = $nav;
    $calls = [];
    $rn = Rates::fetchOne('navasan');
    T::ok($rn['ok'] && in_array('http://api.navasan.tech/latest/?api_key=NAVKEY', $calls, true), 'نوسان: https شکست ⇒ http امتحان شد و جواب داد');
    Rates::saveSource('navasan', ['enabled' => 1, 'priority' => 20, 'url' => 'http://api.navasan.tech']);
    $calls = [];
    $rn = Rates::fetchOne('navasan');
    T::ok($rn['ok'] && $calls === ['http://api.navasan.tech/latest/?api_key=NAVKEY'], '⛔ آدرسِ جایگزینِ «فقط پایه»: درخواست به /latest/ با کلید رفت', implode(' ', $calls));
    Rates::saveSource('navasan', ['enabled' => 1, 'priority' => 20, 'url' => '']);
    $fake = $fakeBak;

    // همه پر ⇒ منبعِ بعدی صدا زده نمی‌شود
    $all = json_encode(['currency' => array_map(fn($s) => ['symbol' => $s, 'price' => 100000], ['USD', 'EUR', 'AED', 'USDT']),
        'gold' => array_map(fn($s) => ['symbol' => $s, 'price' => 8000000], ['IR_GOLD_18K', 'IR_GOLD_24K', 'IR_GOLD_MELTED',
            'IR_COIN_EMAMI', 'IR_COIN_BAHAR', 'IR_COIN_HALF', 'IR_COIN_QUARTER', 'IR_COIN_1G'])]);
    $pdo->exec('DELETE FROM market_rates');
    $fake['https://BrsApi.ir/Api/Market/Gold_Currency.php?key=SECRET-KEY-1'] = $all;
    $calls = [];
    $res = Rates::refresh();
    T::same(1, count($calls), '⛔ وقتی اولین منبع همه را داد، بقیه صدا زده نمی‌شوند (سقفِ نوسان حرام نمی‌شود)');
    T::same('لازم نشد', $res['report']['navasan'] ?? '', 'و گزارش می‌گوید «لازم نشد»');
    T::same(12, count($res['written']), 'هر دوازده نرخ نوشته شد');

    // سقفِ ماهانه
    $pdo->exec("UPDATE rate_sources SET month_calls = 120, month_key = '" . date('Y-m') . "' WHERE provider = 'navasan'");
    $fake['https://BrsApi.ir/Api/Market/Gold_Currency.php?key=SECRET-KEY-1'] = ['ok' => false, 'message' => 'blocked'];
    $fake['https://Api.BrsApi.ir/'] = ['ok' => false, 'message' => 'blocked'];   // آدرسِ دومِ همان منبع هم
    $calls = [];
    $res = Rates::refresh();
    T::ok(!in_array('https://api.navasan.tech/latest/?api_key=NAVKEY', $calls, true), '⛔ سقفِ ماهانه‌ی پرشده: نوسان صدا زده نمی‌شود');
    T::ok(str_contains($res['report']['navasan'] ?? '', 'سقف'), 'و گزارش علتش را می‌گوید');
    T::same('https: blocked', (string)$pdo->query("SELECT last_error FROM rate_sources WHERE provider = 'brsapi'")->fetchColumn(), 'خطای منبع ثبت شد (دو آدرس با یک خطا، یک بار)');
    T::ok(in_array('https://Api.BrsApi.ir/Market/Gold_Currency.php?key=SECRET-KEY-1', $calls, true), 'آدرسِ اول جواب نداد ⇒ آدرسِ دومِ همان منبع امتحان شد');
    Rates::forget();
    T::same(100000, Rates::price('usd'), '⛔ منبعِ قطع: آخرین نرخِ سالم می‌ماند');
    T::same(106000, Rates::price('usdt'), 'و منبعِ سالم (بیت‌پین) تتر را تازه کرد');
    $pdo->exec("UPDATE rate_sources SET month_calls = 0 WHERE provider = 'navasan'");

    // جهش
    $fake['https://BrsApi.ir/Api/Market/Gold_Currency.php?key=SECRET-KEY-1'] =
        json_encode(['currency' => [['symbol' => 'USD', 'price' => 1000000]]]);
    $res = Rates::refresh();
    Rates::forget();
    T::ok(isset($res['rejected']['usd']), '⛔ دلارِ ده‌برابر رد شد');
    T::same(100000, Rates::price('usd'), 'و نرخِ قبلی دست نخورد');

    // دستی
    T::ok(Rates::setManual('usd', 110000)['ok'], 'نرخِ دستی');
    $fake['https://BrsApi.ir/Api/Market/Gold_Currency.php?key=SECRET-KEY-1'] = json_encode(['currency' => [['symbol' => 'USD', 'price' => 120000]]]);
    Rates::refresh();
    Rates::forget();
    T::same(110000, Rates::price('usd'), '⛔ دریافتِ خودکار روی نرخِ دستی نمی‌نویسد');
    Rates::setManual('usd', null);
    Rates::refresh();
    Rates::forget();
    T::same(120000, Rates::price('usd'), '«خودکار» که شد، دریافتِ بعدی می‌نویسد');
    T::same(110000, (int)Rates::all()['usd']['prev_price'], 'نرخِ قبلی برای نشانِ تغییر می‌ماند');
    T::ok(!Rates::setManual('nope', 5)['ok'] && !Rates::setManual('usd', 0)['ok'], 'کدِ ناشناخته و صفر رد');

    // ---------------------------------------------------------------
    T::group('نرخ — دارایی و فروشگاه');
    $purge();
    require_once __DIR__ . '/../includes/signup.php';
    require_once __DIR__ . '/../includes/biz.php';
    require_once __DIR__ . '/../includes/biz_catalog.php';
    $ids = [];
    foreach ($USERS as $un) {
        createUserAccount($pdo, 'نرخ آزمون', $un, $un . '@example.com', 'Rate12345');
        $st = $pdo->prepare('SELECT id FROM users WHERE username = :u');
        $st->execute(['u' => $un]);
        $ids[] = (int)$st->fetchColumn();
        Biz::setType(end($ids), 'both');
    }
    [$ua, $ub] = $ids;

    // ⛔ کاربرِ تازه: نوع‌های پیش‌فرض از همان ابتدا وصل‌اند و قیمتشان همین حالا از نرخِ روز
    $pdo->prepare('DELETE FROM asset_types WHERE user_id = :u')->execute(['u' => $ub]);
    try { $pdo->prepare('UPDATE users SET defaults_seeded_at = NULL WHERE id = :u')->execute(['u' => $ub]); } catch (Throwable $e) {}
    seedUserDefaults($ub);
    $seeded = $pdo->prepare('SELECT name, rate_code, current_price FROM asset_types WHERE user_id = :u');
    $seeded->execute(['u' => $ub]);
    $seeded = array_column($seeded->fetchAll(), null, 'name');
    T::ok(($seeded['دلار']['rate_code'] ?? '') === 'usd' && ($seeded['سکه تمام']['rate_code'] ?? '') === 'coin_emami'
        && ($seeded['طلا (۱۸ عیار)']['rate_code'] ?? '') === 'gold18' && isset($seeded['نقره']) && $seeded['نقره']['rate_code'] === null,
        '⛔ کاربرِ تازه: دلار، طلای ۱۸ و سکه‌ی تمام وصل‌اند؛ نقره نه');
    T::same((int)Rates::price('usd'), (int)($seeded['دلار']['current_price'] ?? 0), 'و قیمتِ دلار همین حالا از نرخِ روز آمد');
    $pdo->prepare('DELETE FROM asset_types WHERE user_id = :u')->execute(['u' => $ub]);

    $mk = $pdo->prepare('INSERT INTO asset_types (user_id, name, unit, current_price, rate_code) VALUES (:u, :n, :un, :p, :rc)');
    $mk->execute(['u' => $ua, 'n' => 'طلای آب‌شده', 'un' => 'گرم', 'p' => 5000000, 'rc' => 'gold18']);
    $ta = (int)$pdo->lastInsertId();
    $mk->execute(['u' => $ub, 'n' => 'طلای آب‌شده', 'un' => 'گرم', 'p' => 5000000, 'rc' => null]);
    $tb = (int)$pdo->lastInsertId();
    $pdo->prepare('INSERT INTO assets (user_id, asset_type_id, quantity, unit_price, entry_date) VALUES (:u, :t, 10, 4000000, CURDATE())')
        ->execute(['u' => $ua, 't' => $ta]);

    Rates::setManual('gold18', 7300000);
    $cur = fn(int $id) => (int)$pdo->query('SELECT current_price FROM asset_types WHERE id = ' . $id)->fetchColumn();
    T::same(7300000, $cur($ta), 'نوعِ داراییِ وصل‌شده نرخِ روز را گرفت');
    T::same(5000000, $cur($tb), '⛔ نوعِ وصل‌نشده (کاربرِ دیگر) دست نخورد');
    $sum = assetSummaryRows($ua);
    T::same(73000000, (int)round((float)$sum[0]['total_value']), 'ارزشِ کل در صفحه‌ی دارایی = ۱۰ گرم × نرخِ روز (همان COALESCE، بی‌محاسبه‌ی دوم)');
    T::same(1, Rates::applyToAssets($ub) + 1, 'applyToAssets برای کاربرِ بی‌وصل هیچ ردیفی نمی‌نویسد');
    // ⛔ جداییِ کاربران: نرخ بی‌خبرِ شنونده‌ها عوض شود و فقط برای B اعمال شود — A نباید تکان بخورد.
    $pdo->exec("UPDATE market_rates SET price = 7400000 WHERE code = 'gold18'");
    Rates::forget();
    Rates::applyToAssets($ub);
    T::same(7300000, $cur($ta), '⛔ applyToAssets(B) به نوعِ داراییِ A دست نمی‌زند');
    Rates::applyToAssets($ua);
    T::same(7400000, $cur($ta), 'و applyToAssets(A) همان را تازه می‌کند');

    // فروشگاه
    $pa = BizProducts::save($ua, ['name' => 'گوشی ارزی', 'type' => 'goods', 'unit' => 'عدد', 'sell_price' => '1000']);
    T::ok($pa['ok'], 'کالای A', $pa['message'] ?? '');
    $pb = BizProducts::save($ub, ['name' => 'گوشی ارزی', 'type' => 'goods', 'unit' => 'عدد', 'sell_price' => '1000']);
    $sell = fn(int $id) => (int)$pdo->query('SELECT sell_price FROM biz_products WHERE id = ' . $id)->fetchColumn();

    T::ok(!BizProducts::saveRate($ua, $pa['id'], ['rate_code' => 'bogus', 'rate_base' => '250'])['ok'], 'کدِ نامعتبر رد');
    T::ok(!BizProducts::saveRate($ua, $pa['id'], ['rate_code' => 'usd', 'rate_base' => '0'])['ok'], 'پایه‌ی صفر رد');
    T::ok(!BizProducts::saveRate($ua, $pa['id'], ['rate_code' => 'usd', 'rate_base' => '250', 'rate_margin' => '2000'])['ok'], 'سودِ بیرون از بازه رد');
    T::ok(BizProducts::saveRate($ua, $pa['id'], ['rate_code' => 'usd', 'rate_base' => '۲۵۰', 'rate_margin' => '5.3'])['ok'], 'وصل به دلار');
    T::same(BizRates::priceFor(250, 120000, 5.3, 1000), $sell($pa['id']), 'قیمتِ فروش همین حالا از نرخِ امروز');
    T::same(1000, $sell($pb['id']), '⛔ کالای فروشگاهِ دیگر دست نخورد');

    T::ok(Biz::saveRateRound($ua, 100000)['ok'], 'گردکردنِ صد هزار');
    T::same(BizRates::priceFor(250, 120000, 5.3, 100000), $sell($pa['id']), 'گردکردنِ تازه همین حالا اعمال شد');
    T::ok(BizRates::priceFor(250, 120000, 5.3, 100000) !== BizRates::priceFor(250, 120000, 5.3, 1000), '(و دو گردکردن واقعاً عددِ متفاوتی می‌دهند — سنجش پوچ نیست)');
    T::ok(!Biz::saveRateRound($ua, 777)['ok'], 'گردکردنِ ناشناخته رد');

    Rates::setManual('usd', 130000);
    T::same(BizRates::priceFor(250, 130000, 5.3, 100000), $sell($pa['id']), '⛔ نرخِ تازه (از راهِ Rates::listen) به قیمتِ فروش رسید');

    // ورود از فایل (save بی‌فیلدهای ارزی) وصل را نمی‌بُرد
    BizProducts::save($ua, ['name' => 'گوشی ارزی', 'type' => 'goods', 'unit' => 'عدد', 'sell_price' => '5'], $pa['id']);
    T::same('usd', (string)$pdo->query('SELECT rate_code FROM biz_products WHERE id = ' . (int)$pa['id'])->fetchColumn(),
        '⛔ save() (مثلِ ورود از فایل) وصلِ ارزی را قطع نمی‌کند');
    Rates::setManual('usd', 131000);
    T::same(BizRates::priceFor(250, 131000, 5.3, 100000), $sell($pa['id']), 'و نرخِ بعدی دوباره قیمت را می‌نویسد');

    BizProducts::saveRate($ua, $pa['id'], ['rate_code' => '']);
    Rates::setManual('usd', 140000);
    T::same(BizRates::priceFor(250, 131000, 5.3, 100000), $sell($pa['id']), 'قطع که شد، قیمت همان آخرین عدد می‌ماند و دیگر عوض نمی‌شود');
} finally {
    Rates::$httpForTests = null;
    try { $purge(); } catch (Throwable $e) {}
    $restore();
}

exit(T::report());
