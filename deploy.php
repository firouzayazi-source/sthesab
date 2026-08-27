<?php
/**
 * deploy.php — استقرار با یک کلیک
 *
 * دو روش دارد:
 *   ۱) دریافت مستقیم از گیت‌هاب (اگر هاست به گیت‌هاب دسترسی داشته باشد)
 *   ۲) آپلود فایل zip و جاگذاری خودکار (همیشه کار می‌کند)
 *
 * در هر دو حالت این‌ها دست‌نخورده می‌مانند:
 *   config/config.php   — تنظیمات دیتابیس شما
 *   uploads/            — رسیدها و عکس‌های پروفایل
 *
 * پیش از استفاده، این دو مقدار را در config.php تعریف کنید:
 *   define('DEPLOY_TOKEN', 'یک-رشته-تصادفی-طولانی');
 *   define('GITHUB_REPO', 'username/repo');
 *   define('GITHUB_BRANCH', 'main');
 */

require_once __DIR__ . '/config/config.php';

if (!defined('DEPLOY_TOKEN') || DEPLOY_TOKEN === '' || DEPLOY_TOKEN === 'CHANGE_ME') {
    http_response_code(500);
    exit('ابتدا DEPLOY_TOKEN را در config/config.php تعریف کنید.');
}
$given = $_GET['token'] ?? $_POST['token'] ?? '';
if (!hash_equals(DEPLOY_TOKEN, (string)$given)) {
    http_response_code(403);
    exit('دسترسی مجاز نیست.');
}

$root = __DIR__;
$log  = [];
$done = false;

function isProtected(string $relative): bool
{
    $protected = ['config/config.php', 'config/config.example.php'];
    if (in_array($relative, $protected, true)) { return true; }
    if (strpos($relative, 'uploads/') === 0) { return true; }
    return false;
}

function copyTree(string $src, string $dst, string $prefix, array &$log): int
{
    $count = 0;
    $items = @scandir($src);
    if ($items === false) { return 0; }

    foreach ($items as $item) {
        if ($item === '.' || $item === '..') { continue; }

        $from = $src . '/' . $item;
        $rel  = $prefix === '' ? $item : $prefix . '/' . $item;
        $to   = $dst . '/' . $item;

        if (in_array($item, ['.git', '.github', '.gitignore', 'README.md', 'node_modules'], true)) {
            continue;
        }

        if (is_dir($from)) {
            if (!is_dir($to) && !@mkdir($to, 0755, true) && !is_dir($to)) {
                $log[] = 'پوشه ساخته نشد: ' . $rel;
                continue;
            }
            $count += copyTree($from, $to, $rel, $log);
            continue;
        }

        if (isProtected($rel)) {
            $log[] = 'محافظت‌شده، دست نخورد: ' . $rel;
            continue;
        }

        if (@copy($from, $to)) {
            $count++;
        } else {
            $log[] = 'کپی نشد: ' . $rel;
        }
    }
    return $count;
}

function rrmdir(string $dir): void
{
    if (!is_dir($dir)) { return; }
    foreach (scandir($dir) as $item) {
        if ($item === '.' || $item === '..') { continue; }
        $path = $dir . '/' . $item;
        is_dir($path) ? rrmdir($path) : @unlink($path);
    }
    @rmdir($dir);
}

function deployFromZip(string $zipPath, string $root, array &$log): bool
{
    if (!class_exists('ZipArchive')) {
        $log[] = 'افزونه ZipArchive روی این هاست فعال نیست.';
        return false;
    }

    $tmp = $root . '/.deploy_tmp';
    rrmdir($tmp);
    if (!@mkdir($tmp, 0755, true)) {
        $log[] = 'پوشه موقت ساخته نشد. دسترسی نوشتن را بررسی کنید.';
        return false;
    }

    $zip = new ZipArchive();
    if ($zip->open($zipPath) !== true) {
        $log[] = 'فایل zip باز نشد.';
        rrmdir($tmp);
        return false;
    }
    $zip->extractTo($tmp);
    $zip->close();

    $source = $tmp;
    $entries = array_values(array_diff(scandir($tmp), ['.', '..']));
    if (count($entries) === 1 && is_dir($tmp . '/' . $entries[0])) {
        $source = $tmp . '/' . $entries[0];
    }

    $n = copyTree($source, $root, '', $log);
    rrmdir($tmp);

    $log[] = $n . ' فایل جاگذاری شد.';
    return $n > 0;
}

function fetchFromGithub(string $root, array &$log): ?string
{
    if (!defined('GITHUB_REPO') || GITHUB_REPO === '') {
        $log[] = 'GITHUB_REPO در config.php تعریف نشده است.';
        return null;
    }
    $branch = defined('GITHUB_BRANCH') ? GITHUB_BRANCH : 'main';
    $url = 'https://codeload.github.com/' . GITHUB_REPO . '/zip/refs/heads/' . rawurlencode($branch);

    $dest = $root . '/.deploy_download.zip';
    $data = null;

    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT        => 60,
            CURLOPT_USERAGENT      => 'daftar-deploy',
        ]);
        $data = curl_exec($ch);
        $err  = curl_error($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($data === false || $code !== 200) {
            $log[] = 'دریافت از گیت‌هاب ناموفق بود' . ($err !== '' ? ' — ' . $err : ' (کد ' . $code . ')');
            $data = null;
        }
    } elseif (ini_get('allow_url_fopen')) {
        $data = @file_get_contents($url);
        if ($data === false) { $log[] = 'دریافت از گیت‌هاب ناموفق بود (allow_url_fopen).'; $data = null; }
    } else {
        $log[] = 'نه cURL فعال است نه allow_url_fopen — دریافت مستقیم ممکن نیست.';
    }

    if ($data === null) { return null; }
    if (@file_put_contents($dest, $data) === false) {
        $log[] = 'ذخیره فایل دانلودشده ناموفق بود.';
        return null;
    }
    return $dest;
}

$action = $_POST['action'] ?? '';

if ($action === 'github') {
    $zipPath = fetchFromGithub($root, $log);
    if ($zipPath !== null) {
        $done = deployFromZip($zipPath, $root, $log);
        @unlink($zipPath);
    }
} elseif ($action === 'upload') {
    if (!isset($_FILES['package']) || $_FILES['package']['error'] !== UPLOAD_ERR_OK) {
        $log[] = 'فایلی دریافت نشد.';
    } else {
        $done = deployFromZip($_FILES['package']['tmp_name'], $root, $log);
    }
}

$env = [
    'ZipArchive'      => class_exists('ZipArchive'),
    'cURL'            => function_exists('curl_init'),
    'allow_url_fopen' => (bool)ini_get('allow_url_fopen'),
    'قابل نوشتن'      => is_writable($root),
    'GITHUB_REPO'     => defined('GITHUB_REPO') ? GITHUB_REPO : '—',
];

header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>استقرار</title>
<style>
body { font-family: Tahoma, sans-serif; padding: 16px; background: #f5f5f3; line-height: 2; }
.box { background:#fff; border-radius:14px; padding:16px; margin-bottom:12px; box-shadow:0 1px 3px rgba(0,0,0,.07); }
h2 { font-size:15px; margin-bottom:10px; }
.row { display:flex; justify-content:space-between; border-bottom:1px solid #eee; padding:5px 0; font-size:13px; }
.ok { color:#16a34a; font-weight:bold; } .bad { color:#dc2626; font-weight:bold; }
button { width:100%; padding:14px; border:none; border-radius:10px; background:#2a3563; color:#fff;
         font-size:15px; font-weight:bold; font-family:inherit; margin-top:8px; }
button.alt { background:#eef0f6; color:#2a3563; }
input[type=file] { width:100%; padding:12px; border:1px dashed #ccd; border-radius:10px; background:#fafbff; }
.log { background:#111827; color:#e5e7eb; border-radius:10px; padding:12px; font-size:12px;
       direction:ltr; text-align:left; white-space:pre-wrap; word-break:break-word; max-height:320px; overflow:auto; }
.success { background:#e9f8ef; color:#166534; border-radius:10px; padding:12px; font-weight:bold; }
</style>
</head>
<body>

<?php if ($done): ?>
    <div class="box"><div class="success">✅ استقرار با موفقیت انجام شد.</div></div>
<?php endif; ?>

<?php if (!empty($log)): ?>
<div class="box">
    <h2>گزارش</h2>
    <div class="log"><?= h(implode("\n", $log)) ?></div>
</div>
<?php endif; ?>

<div class="box">
    <h2>روش ۱ — دریافت از گیت‌هاب</h2>
    <p style="font-size:12.5px;color:#666;">
        آخرین نسخه از مخزن <b><?= h($env['GITHUB_REPO']) ?></b> گرفته و جاگذاری می‌شود.
    </p>
    <form method="POST">
        <input type="hidden" name="token" value="<?= h((string)$given) ?>">
        <input type="hidden" name="action" value="github">
        <button type="submit">دریافت و استقرار از گیت‌هاب</button>
    </form>
</div>

<div class="box">
    <h2>روش ۲ — آپلود فایل zip</h2>
    <p style="font-size:12.5px;color:#666;">
        اگر هاست به گیت‌هاب دسترسی ندارد، فایل zip را همین‌جا بدهید تا خودش در جای درست باز شود.
    </p>
    <form method="POST" enctype="multipart/form-data">
        <input type="hidden" name="token" value="<?= h((string)$given) ?>">
        <input type="hidden" name="action" value="upload">
        <input type="file" name="package" accept=".zip" required>
        <button type="submit" class="alt">آپلود و استقرار</button>
    </form>
</div>

<div class="box">
    <h2>وضعیت هاست</h2>
    <?php foreach ($env as $k => $v): ?>
        <div class="row">
            <span><?= h((string)$k) ?></span>
            <span class="<?= is_bool($v) ? ($v ? 'ok' : 'bad') : '' ?>">
                <?= is_bool($v) ? ($v ? 'فعال' : 'غیرفعال') : h((string)$v) ?>
            </span>
        </div>
    <?php endforeach; ?>
</div>

<div class="box">
    <h2>محافظت‌شده‌ها</h2>
    <p style="font-size:12.5px;color:#666;">
        این‌ها در هیچ استقراری رونویسی نمی‌شوند:<br>
        <code>config/config.php</code> و کل پوشه‌ی <code>uploads/</code>
    </p>
</div>

</body>
</html>
