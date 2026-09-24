<?php
/**
 * `deploy/nginx-var.sh` — بستنِ var/ و پیشوندهای جامانده روی نصبِ موجود.
 *
 * ⛔ خرابیِ واقعی که این تست برایش نوشته شد: نسخه‌ی اولِ اسکریپت درست
 *    بعد از خطِ `location ^~ /tests/` درج می‌کرد، و فایلِ سایتِ سرورِ
 *    واقعی — که با vps-setup.shِ قدیمی ساخته شده بود — آن خط را اصلاً
 *    نداشت. اسکریپت آبرومندانه ایستاد ولی var/ باز ماند. حالا جای درج
 *    از **ساختارِ فایل** پیدا می‌شود: پیش از اولین `location ~ \.php`
 *    که مستقیم داخلِ `server` است (عمقِ آکولاد ۱)، نه نسخه‌ی تودرتوی
 *    همان الگو داخلِ `/uploads/` — که درج آنجا nginx -t را می‌شکست.
 *
 * این تست فقط حالتِ نمایشی را اجرا می‌کند (هیچ nginx ای لازم نیست و
 * هیچ فایلی نوشته نمی‌شود). حالتِ `--apply` با nginxِ واقعی روی همین
 * چهار شکلِ فایل دستی سنجیده شد (پیش ۲۰۰، پس ۴۰۴؛ صفحه‌ی ورود ۲۰۰).
 *
 * ⚠ اسکریپت sudo می‌خواهد، پس روی ماشینی که تست با root اجرا نمی‌شود
 *   رد می‌شود (`T::skip` — ابزارِ اختیاری، نه زیرساختِ لازم). قاعده‌ی
 *   شکلِ همین در `test_api_contract.php` (قاعده ۶۲) آنجا می‌ماند.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit('Not found.');
}

require_once __DIR__ . '/lib/assert.php';

$script = realpath(__DIR__ . '/../deploy/nginx-var.sh');

T::group('nginx-var.sh — جای درج از ساختارِ فایل');

if (function_exists('posix_geteuid') ? posix_geteuid() !== 0 : trim((string)shell_exec('id -u')) !== '0') {
    T::skip('nginx-var.sh', 'اسکریپت root می‌خواهد و این تست با root اجرا نمی‌شود');
    exit(T::report());
}

$dir = sys_get_temp_dir() . '/ngvar_' . getmypid();
@mkdir($dir, 0700, true);

function runVar(string $dir, string $name, string $site): array
{
    global $script;
    $file = $dir . '/' . $name;
    file_put_contents($file, $site);
    $before = $site;
    $cmd = 'SITE_FILE=' . escapeshellarg($file) . ' bash ' . escapeshellarg($script) . ' 2>&1';
    exec($cmd, $out, $rc);
    $baks = glob($file . '.bak.*') ?: [];
    return [
        'rc'      => $rc,
        'out'     => implode("\n", $out),
        'added'   => array_values(array_filter($out, fn($l) => strpos($l, '  + ') === 0)),
        'touched' => file_get_contents($file) !== $before || $baks !== [],
    ];
}

// ۱ — شکلِ قدیمیِ vps-setup.sh (همان که روی سرور است) + بلوکِ certbot.
$old = <<<'NGX'
server {
    listen 443 ssl;
    server_name hesab.example;
    root /opt/hesab/app;

    location ^~ /uploads/ {
        location ~ \.php$ { deny all; }
    }

    location = /deploy.php { deny all; return 404; }
    location ~ ^/(config|\.git)/ { deny all; return 404; }
    location ~ \.php$ {
        include snippets/fastcgi-php.conf;
    }
    location / { try_files $uri $uri/ =404; }
}
server {
    listen 80;
    server_name hesab.example; # managed by Certbot
    if ($host = hesab.example) { return 301 https://$host$request_uri; }
    return 404;
}
NGX;
$r = runVar($dir, 'old', $old);
T::same(0, $r['rc'], 'فایلِ قدیمی (بدونِ /tests/) نمایشی موفق است');
T::same(4, count($r['added']), 'هر چهار پیشوندِ جامانده اضافه می‌شوند (var, deploy, tests, mobile)');
T::ok(strpos($r['out'], 'در 1 بلوکِ server') !== false, 'فقط بلوکِ اپ می‌گیرد، نه بلوکِ :80ِ certbot', $r['out']);
$indOk = $r['added'] !== [] && array_reduce($r['added'], fn($c, $l) => $c && preg_match('#^  \+     location \^~ /[a-z]+/ \{#', $l), true);
T::ok($indOk, 'تورفتگیِ خطِ درج‌شده ۴ فاصله است — یعنی سطحِ server، نه داخلِ /uploads/', implode("\n", $r['added']));
foreach (['var', 'deploy', 'tests', 'mobile'] as $p) {
    T::ok(strpos($r['out'], "location ^~ /{$p}/ { deny all; return 404; }") !== false, "خطِ /{$p}/ در خروجی هست");
}
T::ok(!$r['touched'], 'حالتِ نمایشی نه فایل را عوض می‌کند نه پشتیبان می‌سازد');

// ۲ — نصبِ تازه که همه را دارد.
$new = "server {\n    root /x;\n    location ^~ /deploy/ { deny all; return 404; }\n    location ^~ /tests/  { deny all; return 404; }\n"
     . "    location ^~ /var/    { deny all; return 404; }\n    location ^~ /mobile/ { deny all; return 404; }\n"
     . "    location ~ \\.php\$ { include x; }\n}\n";
$r = runVar($dir, 'new', $new);
T::same(0, $r['rc'], 'فایلی که همه را دارد: کدِ صفر');
T::same(0, count($r['added']), 'فایلی که همه را دارد: هیچ خطی اضافه نمی‌شود');
T::ok(strpos($r['out'], 'کاری لازم نیست') !== false, 'فایلی که همه را دارد: صریح می‌گوید کاری لازم نیست');

// ۳ — فقط یکی جا مانده، با تورفتگیِ تب.
$tab = "server {\n\troot /x;\n\tlocation ^~ /tests/ { deny all; return 404; }\n\tlocation ^~ /deploy/ { deny all; return 404; }\n"
     . "\tlocation ^~ /mobile/ { deny all; return 404; }\n\tlocation ~ \\.php\$ { include x; }\n}\n";
$r = runVar($dir, 'tab', $tab);
T::same(1, count($r['added']), 'فقط پیشوندِ جامانده (var) اضافه می‌شود، نه تکراری');
T::ok(isset($r['added'][0]) && strpos($r['added'][0], "\tlocation ^~ /var/") !== false, 'تورفتگی از خطِ لنگر گرفته می‌شود (تب)');

// ۴ — هیچ `location ~ \.php` ای در سطحِ server نیست: حدس نمی‌زند.
$none = "server {\n    root /x;\n    location ^~ /uploads/ {\n        location ~ \\.php\$ { deny all; }\n    }\n    location / { try_files \$uri =404; }\n}\n";
$r = runVar($dir, 'none', $none);
T::same(1, $r['rc'], 'بدونِ لنگرِ سطحِ server: کدِ ۱');
T::ok(strpos($r['out'], 'grep -n -e server -e location') !== false, 'بدونِ لنگر: دستورِ دیدنِ ساختارِ فایل را می‌گوید');
T::ok(!$r['touched'], 'بدونِ لنگر: فایل دست‌نخورده و بی‌پشتیبان (نسخه‌ی اول پیش از یافتنِ لنگر پشتیبان می‌ساخت)');

// ۵ — دو بلوکِ اپ (مثلاً دو server_name): هر کدام یک بار.
$two = "server {\n    root /a;\n    location ~ \\.php\$ { include x; }\n}\nserver {\n    root /b;\n    location ~ \\.php\$ { include x; }\n    location ~ \\.php\$ { include y; }\n}\n";
$r = runVar($dir, 'two', $two);
T::ok(strpos($r['out'], 'در 2 بلوکِ server') !== false, 'دو بلوکِ اپ: هر کدام یک بار (نه دو بار در دومی)', $r['out']);
T::same(8, count($r['added']), 'دو بلوکِ اپ: ۴ خط × ۲ بلوک');

array_map('unlink', glob($dir . '/*') ?: []);
@rmdir($dir);

exit(T::report());
