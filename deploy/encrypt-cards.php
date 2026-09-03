<?php
/**
 * encrypt-cards.php — روشن کردنِ رمزنگاریِ شماره کارت/حساب/شبا،
 *                      و مهاجرتِ ردیف‌های موجود.
 *
 *     php deploy/encrypt-cards.php              وضعیت (بی‌خطر، فقط می‌خواند)
 *     php deploy/encrypt-cards.php --setup      کلید می‌سازد و در config می‌نویسد
 *     php deploy/encrypt-cards.php --apply      ردیف‌های خام را رمز می‌کند
 *     php deploy/encrypt-cards.php --decrypt-all  برگرداندن همه به حالت خام
 *
 * ⛔ خطرناک‌ترین اسکریپتِ این مخزن است، چون داده‌ای را عوض می‌کند که
 *    اگر خراب شود بازگشتی ندارد. سه محافظ دارد و هیچ‌کدام اختیاری نیست:
 *
 *  ۱. **هر ردیف پیش از نوشتن، رفت‌وبرگشت آزموده می‌شود.** رمز می‌شود،
 *     بلافاصله رمزگشایی می‌شود، و اگر با متنِ اصلی مو به مو یکی نبود
 *     کلِ کار متوقف می‌شود. «رمز شد» با «قابل بازگشت است» یکی نیست.
 *
 *  ۲. **همه در یک تراکنش.** نصفه ماندنِ کار یعنی بخشی رمزشده و بخشی
 *     خام با کلیدی که شاید بعداً عوض شود — بدترین حالتِ ممکن.
 *
 *  ۳. **ستون‌ها باید از قبل پهن شده باشند** (`migration_wallet_encrypt`).
 *     روی پیکربندیِ غیر-strict، مقدارِ بلند **بی‌صدا بریده** می‌شود و
 *     آن‌وقت رمزگشایی برای همیشه شکست می‌خورد: داده رفته، بی‌هیچ خطایی.
 *
 * ⛔ و یک هشدارِ عملیاتی که باید دیده شود: بکاپِ دیتابیس بدونِ کلید
 *    **قابل بازیابی نیست**. کلید در `config/config.php` است که در
 *    بکاپِ دیتابیس نمی‌آید.
 */

// ---------- نگهبان: فقط خط فرمان ----------
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit('Not found.');
}

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/crypto.php';

$CONFIG = __DIR__ . '/../config/config.php';

function out(string $s = ''): void { echo $s . "\n"; }
function ok(string $s): void   { echo "\033[0;32m✅ $s\033[0m\n"; }
function warn(string $s): void { echo "\033[0;33m⚠  $s\033[0m\n"; }
function fail(string $s): never { echo "\033[0;31m⛔ $s\033[0m\n"; exit(1); }

$mode = $argv[1] ?? '--status';

// ---------------------------------------------------------------
// ساختِ کلید و نوشتنش در config
// ---------------------------------------------------------------
if ($mode === '--setup') {
    if (!function_exists('sodium_crypto_secretbox')) {
        fail('افزونه‌ی sodium در این نسخه‌ی PHP نیست. رمزنگاری ممکن نیست.');
    }
    if (defined('APP_ENCRYPTION_KEY') && APP_ENCRYPTION_KEY !== '') {
        warn('کلید از قبل تنظیم شده است.');
        out('   اگر عوضش کنید، همه‌ی داده‌ی رمزشده‌ی فعلی خوانده نمی‌شود.');
        out('   برای رمز کردنِ ردیف‌های باقی‌مانده:  php deploy/encrypt-cards.php --apply');
        exit(0);
    }
    if (!is_writable($CONFIG)) {
        fail("فایل {$CONFIG} قابل نوشتن نیست.");
    }

    $key = Crypto::newKey();
    $src = file_get_contents($CONFIG);

    // اگر ثابت از قبل با مقدار خالی هست، همان را پر می‌کنیم؛ وگرنه
    // ته فایل اضافه می‌شود (پیش از تگِ پایانِ PHP، اگر بود).
    // ⚠ آن تگ عمداً اینجا نوشته نشده: در یک کامنتِ `//` هم PHP را
    //   می‌بندد و بقیه‌ی فایل HTML حساب می‌شود.
    if (preg_match("/define\(\s*'APP_ENCRYPTION_KEY'\s*,\s*''\s*\)\s*;/", $src)) {
        $src = preg_replace(
            "/define\(\s*'APP_ENCRYPTION_KEY'\s*,\s*''\s*\)\s*;/",
            "define('APP_ENCRYPTION_KEY', '" . $key . "');",
            $src, 1
        );
    } else {
        $add = "\n// کلید رمزنگاریِ شماره کارت/حساب/شبا — گم شود، داده رفته است.\n"
             . "define('APP_ENCRYPTION_KEY', '" . $key . "');\n";
        $src = preg_replace('/\?>\s*$/', '', $src) . $add;
    }

    // بکاپِ کانفیگ پیش از دست زدن به آن
    $bak = $CONFIG . '.bak-' . date('Ymd-His');
    if (!copy($CONFIG, $bak)) { fail('بکاپ گرفتن از config ناموفق بود.'); }
    if (file_put_contents($CONFIG, $src) === false) { fail('نوشتن در config ناموفق بود.'); }

    ok('کلید ساخته و در config/config.php نوشته شد.');
    out("   بکاپِ کانفیگ قبلی: $bak");
    out('');
    warn('این کلید را جای امنی بیرون از سرور نگه دارید:');
    out("   \033[1m$key\033[0m");
    out('');
    warn('بکاپِ دیتابیس بدونِ این کلید قابل بازیابی نیست.');
    out('');
    out('حالا ردیف‌های موجود را رمز کنید:');
    out('   php deploy/encrypt-cards.php --apply');
    exit(0);
}

// ---------------------------------------------------------------
try {
    $pdo = Database::getConnection();
} catch (Throwable $e) {
    fail('اتصال به دیتابیس برقرار نشد.');
}

if (!tableHasColumn('wallets', 'card_number')) {
    fail('ستون‌های کارت وجود ندارند — اول migration_wallet_cards را اجرا کنید.');
}

// محافظ ۳: ستون‌ها باید پهن شده باشند.
$len = (int)$pdo->query(
    "SELECT COALESCE(MAX(CHARACTER_MAXIMUM_LENGTH), 0)
     FROM information_schema.columns
     WHERE table_schema = DATABASE() AND table_name = 'wallets'
       AND column_name = 'card_number'"
)->fetchColumn();

$FIELDS = Crypto::WALLET_FIELDS;

$rows = $pdo->query(
    'SELECT id, user_id, card_number, account_number, iban FROM wallets'
)->fetchAll();

$plain = $enc = $empty = 0;
foreach ($rows as $r) {
    foreach ($FIELDS as $f) {
        if ($r[$f] === null || $r[$f] === '') { $empty++; }
        elseif (Crypto::isEncrypted($r[$f]))  { $enc++; }
        else                                   { $plain++; }
    }
}

out('');
out("\033[1mوضعیت رمزنگاریِ ستون‌های حساس\033[0m");
out('');
out('  افزونه‌ی sodium:       ' . (function_exists('sodium_crypto_secretbox') ? 'هست' : 'نیست'));
out('  کلید تنظیم شده:        ' . (defined('APP_ENCRYPTION_KEY') && APP_ENCRYPTION_KEY !== '' ? 'بله' : 'خیر'));
out('  رمزنگاری فعال:         ' . (Crypto::available() ? "\033[0;32mبله\033[0m" : "\033[0;33mخیر\033[0m"));
out('  طول ستون card_number:  ' . $len . ($len >= 255 ? '' : "  \033[0;31m← کم است\033[0m"));
out('');
out('  حساب‌ها:               ' . count($rows));
out('  مقدارهای خام:          ' . $plain);
out('  مقدارهای رمزشده:       ' . $enc);
out('  خالی:                  ' . $empty);
out('');

if ($mode === '--status') {
    if (!Crypto::available()) {
        out('برای روشن کردن:  php deploy/encrypt-cards.php --setup');
    } elseif ($plain > 0) {
        out('برای رمز کردنِ ردیف‌های خام:  php deploy/encrypt-cards.php --apply');
    } else {
        ok('همه چیز رمزشده است.');
    }
    out('');
    exit(0);
}

// ---------------------------------------------------------------
// مهاجرت
// ---------------------------------------------------------------
if ($mode !== '--apply' && $mode !== '--decrypt-all') {
    fail("گزینه‌ی ناشناخته: $mode");
}
if (!Crypto::available()) {
    fail('رمزنگاری فعال نیست. اول --setup را اجرا کنید.');
}
if ($mode === '--apply' && $len < 255) {
    fail('ستون‌ها هنوز پهن نشده‌اند. اول این را اجرا کنید: bash deploy/migrate.sh --apply');
}

$toEncrypt = $mode === '--apply';
$changed = 0;

$pdo->beginTransaction();
try {
    $upd = $pdo->prepare(
        'UPDATE wallets SET card_number = :c, account_number = :a, iban = :i WHERE id = :id'
    );

    foreach ($rows as $r) {
        $new = [];
        $touched = false;

        foreach ($FIELDS as $f) {
            $v = $r[$f];
            if ($v === null || $v === '') { $new[$f] = $v; continue; }

            if ($toEncrypt) {
                if (Crypto::isEncrypted($v)) { $new[$f] = $v; continue; }
                $cipher = Crypto::encrypt($v);

                // ⛔ محافظ ۱ — رفت‌وبرگشت پیش از نوشتن.
                if (Crypto::decrypt($cipher) !== $v) {
                    throw new RuntimeException(
                        "رفت‌وبرگشتِ حساب #{$r['id']} ستون {$f} نخواند — هیچ چیزی نوشته نشد."
                    );
                }
                // و باید در ستون جا شود، وگرنه بی‌صدا بریده می‌شود.
                if (strlen($cipher) > $len) {
                    throw new RuntimeException(
                        "مقدارِ رمزشده‌ی حساب #{$r['id']} از ستون بلندتر است ({$len})."
                    );
                }
                $new[$f] = $cipher;
                $touched = true;
            } else {
                if (!Crypto::isEncrypted($v)) { $new[$f] = $v; continue; }
                $open = Crypto::decrypt($v);
                if ($open === null) {
                    throw new RuntimeException(
                        "رمزگشاییِ حساب #{$r['id']} ستون {$f} شکست خورد — کلید درست است؟"
                    );
                }
                $new[$f] = $open;
                $touched = true;
            }
        }

        if ($touched) {
            $upd->execute([
                'c'  => $new['card_number'],
                'a'  => $new['account_number'],
                'i'  => $new['iban'],
                'id' => $r['id'],
            ]);
            $changed++;
        }
    }

    $pdo->commit();
} catch (Throwable $e) {
    $pdo->rollBack();
    fail($e->getMessage());
}

// ---------------------------------------------------------------
// سنجشِ بعد از نوشتن — «انجام شد» با «درست شد» یکی نیست
// ---------------------------------------------------------------
$after = $pdo->query('SELECT id, card_number, account_number, iban FROM wallets')->fetchAll();
$bad = [];
foreach ($after as $i => $r) {
    foreach ($FIELDS as $f) {
        $want = $rows[$i][$f];
        if ($want === null || $want === '') { continue; }
        $got = Crypto::decrypt($r[$f]);
        // مقدارِ اصلی ممکن است خودش رمزشده بوده باشد
        $wantPlain = Crypto::isEncrypted($want) ? Crypto::decrypt($want) : $want;
        if ($got !== $wantPlain) { $bad[] = "#{$r['id']} / {$f}"; }
    }
}

out('');
if ($bad) {
    fail('بعد از نوشتن، این‌ها با مقدارِ اصلی نخواندند: ' . implode('، ', $bad));
}
ok(($toEncrypt ? 'رمز شد' : 'به حالت خام برگشت') . ": {$changed} حساب.");
out('   همه‌ی مقدارها بعد از نوشتن دوباره خوانده و با اصل مقایسه شدند.');
out('');
