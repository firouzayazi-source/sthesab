<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/csrf.php';
require_once __DIR__ . '/includes/functions.php';

Auth::initSession();
Auth::requireLogin();

$pdo = Database::getConnection();
$userId = Auth::userId();

$exportFrom = startOfJalaliMonth();
$exportTo   = today();

$step = 'upload';
$preview = [];
$errors = [];
$stats = ['valid' => 0, 'invalid' => 0];
$headers = [];
$rawRows = [];

// دسته‌بندی‌ها و حساب‌ها برای تطبیق نام‌ها.
//
// این فهرست دو جا مصرف می‌شود و هر دو حساس‌اند: یکی تطبیق نامِ ستون
// دسته در فایل ورودی، و یکی window.IMPORT_CATEGORIES که به مرورگر
// می‌رود. پس باید از categoryScopeSql() رد شود، وگرنه نامِ دسته‌های
// شخصیِ بقیه‌ی کاربران در سورس همین صفحه دیده می‌شود و یک ردیف وارد
// شده می‌تواند category_id کاربر دیگری را بگیرد.
$catStmt = $pdo->prepare(
    'SELECT id, name, type FROM categories
     WHERE is_active = 1 AND ' . categoryScopeSql() .
    // اگر هم‌نام بودند، مالِ خودِ کاربر برنده است. ستون user_id ممکن است
    // هنوز با migration نیامده باشد، پس مرتب‌سازی هم مشروط است.
    (tableHasColumn('categories', 'user_id') ? ' ORDER BY (user_id IS NULL) DESC' : '')
);
$catStmt->execute(categoryScopeParams($userId));
$catMap = [];
foreach ($catStmt->fetchAll() as $c) {
    $catMap[mb_strtolower(trim($c['name']))] = $c;
}
$walletMap = [];
try {
    $ws = $pdo->prepare('SELECT id, name FROM wallets WHERE user_id = :u');
    $ws->execute(['u' => $userId]);
    foreach ($ws->fetchAll() as $w) {
        $walletMap[mb_strtolower(trim($w['name']))] = (int)$w['id'];
    }
} catch (PDOException $e) { /* ignore */ }
$defaultWalletId = !empty($walletMap) ? reset($walletMap) : null;

/**
 * یک ردیف CSV را اعتبارسنجی و تبدیل می‌کند.
 */
function parseImportRow(array $row, array $map, array $catMap, array $walletMap, ?int $defaultWalletId): array
{
    $get = function (string $key) use ($row, $map) {
        $idx = $map[$key] ?? -1;
        return ($idx >= 0 && isset($row[$idx])) ? trim($row[$idx]) : '';
    };

    $out = ['ok' => true, 'error' => ''];

    // ---------- تاریخ ----------
    $rawDate = toLatinDigits($get('date'));
    $rawDate = str_replace(['/', '.'], '-', $rawDate);
    $gDate = null;

    if (preg_match('/^(\d{4})-(\d{1,2})-(\d{1,2})$/', $rawDate, $m)) {
        $y = (int)$m[1]; $mo = (int)$m[2]; $d = (int)$m[3];
        if ($y < 1900) {
            // تاریخ شمسی
            if ($mo >= 1 && $mo <= 12 && $d >= 1 && $d <= 31) {
                $g = jalaliToGregorian($y, $mo, $d);
                $gDate = sprintf('%04d-%02d-%02d', $g[0], $g[1], $g[2]);
            }
        } else {
            $candidate = sprintf('%04d-%02d-%02d', $y, $mo, $d);
            if (isValidDate($candidate)) { $gDate = $candidate; }
        }
    }
    if ($gDate === null) {
        return ['ok' => false, 'error' => 'تاریخ نامعتبر: ' . ($rawDate !== '' ? $rawDate : 'خالی')];
    }
    $out['transaction_date'] = $gDate;

    // ---------- مبلغ ----------
    $amount = sanitizeAmount($get('amount'));
    if ($amount <= 0) {
        return ['ok' => false, 'error' => 'مبلغ نامعتبر یا صفر'];
    }
    $out['amount'] = $amount;

    // ---------- نوع ----------
    $rawType = mb_strtolower($get('type'));
    $incomeWords  = ['income', 'درآمد', 'دریافت', 'دریافتی', 'واریز', '+'];
    $expenseWords = ['expense', 'هزینه', 'پرداخت', 'پرداختی', 'برداشت', '-'];
    $type = null;
    foreach ($incomeWords as $w)  { if ($rawType !== '' && mb_strpos($rawType, $w) !== false) { $type = 'income'; break; } }
    if ($type === null) {
        foreach ($expenseWords as $w) { if ($rawType !== '' && mb_strpos($rawType, $w) !== false) { $type = 'expense'; break; } }
    }
    if ($type === null) {
        return ['ok' => false, 'error' => 'نوع نامشخص (باید درآمد یا هزینه باشد)'];
    }
    $out['type'] = $type;

    // ---------- عنوان ----------
    $title = $get('title');
    if ($title === '') { $title = $type === 'income' ? 'درآمد واردشده' : 'هزینه واردشده'; }
    $out['title'] = mb_substr($title, 0, 255);

    // ---------- دسته‌بندی (اختیاری) ----------
    $out['category_id'] = null;
    $catName = mb_strtolower(trim($get('category')));
    if ($catName !== '' && isset($catMap[$catName]) && $catMap[$catName]['type'] === $type) {
        $out['category_id'] = (int)$catMap[$catName]['id'];
    }

    // ---------- حساب (اختیاری) ----------
    $walletName = mb_strtolower(trim($get('wallet')));
    $out['wallet_id'] = ($walletName !== '' && isset($walletMap[$walletName]))
        ? $walletMap[$walletName]
        : $defaultWalletId;

    // ---------- توضیح ----------
    $note = $get('note');
    $out['note'] = $note !== '' ? mb_substr($note, 0, 1000) : null;

    return $out;
}

// ============================================================
// مرحله ۱ — دریافت فایل و پیش‌نمایش
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && postParam('action') === 'preview') {
    Csrf::verifyOrFail(postParam('csrf_token'));

    if (!isset($_FILES['csv']) || $_FILES['csv']['error'] !== UPLOAD_ERR_OK) {
        $errors[] = 'فایلی دریافت نشد.';
    } elseif ($_FILES['csv']['size'] > 2 * 1024 * 1024) {
        $errors[] = 'حجم فایل نباید بیشتر از ۲ مگابایت باشد.';
    } else {
        $content = file_get_contents($_FILES['csv']['tmp_name']);
        // حذف BOM
        $content = preg_replace('/^\xEF\xBB\xBF/', '', $content);

        $lines = preg_split('/\r\n|\r|\n/', $content);
        $parsed = [];
        foreach ($lines as $line) {
            if (trim($line) === '') { continue; }
            $parsed[] = str_getcsv($line);
        }

        if (count($parsed) < 2) {
            $errors[] = 'فایل باید حداقل یک سطر عنوان و یک سطر داده داشته باشد.';
        } else {
            $headers = array_shift($parsed);
            $rawRows = array_slice($parsed, 0, 300); // سقف ۳۰۰ ردیف در هر بار
            $step = 'map';
        }
    }
}

// ============================================================
// مرحله ۲ — تأیید نهایی و ثبت
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && postParam('action') === 'commit') {
    Csrf::verifyOrFail(postParam('csrf_token'));

    $payload = postParam('payload');
    $decoded = json_decode($payload, true);

    if (!is_array($decoded) || empty($decoded)) {
        $errors[] = 'داده‌ای برای ثبت پیدا نشد.';
    } else {
        $inserted = 0;
        try {
            $pdo->beginTransaction();
            $ins = $pdo->prepare('
                INSERT INTO transactions (user_id, category_id, wallet_id, type, amount, title, note, transaction_date)
                VALUES (:u, :cat, :wal, :type, :amount, :title, :note, :date)
            ');
            foreach ($decoded as $r) {
                if (!isset($r['amount'], $r['type'], $r['transaction_date'])) { continue; }
                $ins->execute([
                    'u' => $userId,
                    'cat' => $r['category_id'] ?? null,
                    'wal' => $r['wallet_id'] ?? null,
                    'type' => $r['type'] === 'income' ? 'income' : 'expense',
                    'amount' => (int)$r['amount'],
                    'title' => mb_substr((string)$r['title'], 0, 255),
                    'note' => !empty($r['note']) ? mb_substr((string)$r['note'], 0, 1000) : null,
                    'date' => $r['transaction_date'],
                ]);
                $inserted++;
            }
            $pdo->commit();
            $step = 'done';
            $stats['valid'] = $inserted;
        } catch (PDOException $e) {
            $pdo->rollBack();
            error_log('Import commit error: ' . $e->getMessage());
            $errors[] = 'خطا در ثبت. هیچ ردیفی وارد نشد.';
        }
    }
}

$pageTitle = 'خروجی و ورودی اطلاعات';
include __DIR__ . '/includes/header.php';
?>

<?php foreach ($errors as $err): ?>
    <div class="alert alert-error"><?= h($err) ?></div>
<?php endforeach; ?>

<?php if ($step === 'done'): ?>
    <div class="card">
        <div class="alert alert-success"><?= toPersianDigits($stats['valid']) ?> تراکنش با موفقیت وارد شد.</div>
        <a href="transactions.php" class="btn btn-primary btn-block">مشاهده تراکنش‌ها</a>
    </div>
<?php endif; ?>

<?php if ($step === 'upload'): ?>
<!-- ---------- خروجی اکسل ---------- -->
<div class="card">
    <h2 class="card-title">خروجی اکسل</h2>
    <p class="hint" style="margin-bottom:14px;">
        تراکنش‌های یک بازه را به‌صورت فایل CSV بگیرید. فایل مستقیم در اکسل باز می‌شود و فارسی آن هم درست نمایش داده می‌شود.
    </p>

    <form method="GET" action="export_transactions.php" class="report-form">
        <div class="form-group">
            <label>از تاریخ</label>
            <div class="jdp-field">
                <input type="text" class="jdp-display" readonly value="<?= toJalali($exportFrom) ?>">
                <input type="hidden" class="jdp-hidden" name="from_date" value="<?= h($exportFrom) ?>">
            </div>
        </div>
        <div class="form-group">
            <label>تا تاریخ</label>
            <div class="jdp-field">
                <input type="text" class="jdp-display" readonly value="<?= toJalali($exportTo) ?>">
                <input type="hidden" class="jdp-hidden" name="to_date" value="<?= h($exportTo) ?>">
            </div>
        </div>
        <button type="submit" class="btn btn-primary">دانلود فایل</button>
    </form>
</div>

<!-- ---------- ورود از فایل ---------- -->
<div class="card">
    <h2 class="card-title">ورود تراکنش از فایل CSV</h2>
    <form method="POST" enctype="multipart/form-data">
        <?= Csrf::field() ?>
        <input type="hidden" name="action" value="preview">

        <div class="form-group">
            <label for="csvFile">فایل CSV</label>
            <input type="file" id="csvFile" name="csv" accept=".csv,text/csv" required>
        </div>

        <button type="submit" class="btn btn-primary btn-block">بارگذاری و پیش‌نمایش</button>
    </form>

    <div class="import-help">
        <h3 class="ref-manager-title">راهنما</h3>
        <p class="hint">
            فایل باید سطر اول را به‌عنوان عنوان ستون‌ها داشته باشد. در مرحله بعد خودتان مشخص می‌کنید
            کدام ستون به کدام فیلد مربوط است، و قبل از ثبت نهایی پیش‌نمایش می‌بینید.
        </p>
        <p class="hint">
            تاریخ می‌تواند شمسی (۱۴۰۵/۰۶/۰۴) یا میلادی (2026-08-26) باشد.
            نوع تراکنش می‌تواند «درآمد/هزینه» یا «income/expense» باشد.
        </p>
        <p class="hint">
            می‌توانید ابتدا از بخش «خروجی اکسل» یک فایل نمونه بگیرید تا ساختار را ببینید.
        </p>
    </div>
</div>
<?php endif; ?>

<?php if ($step === 'map'): ?>
<div class="card">
    <h2 class="card-title">تطبیق ستون‌ها</h2>
    <p class="hint" style="margin-bottom:14px;">
        <?= toPersianDigits(count($rawRows)) ?> سطر داده خوانده شد. مشخص کنید هر فیلد از کدام ستون بیاید.
    </p>

    <form method="POST" id="mapForm">
        <?= Csrf::field() ?>
        <input type="hidden" name="action" value="commit">
        <input type="hidden" name="payload" id="importPayload">

        <?php
        $fields = [
            'date' => 'تاریخ *',
            'amount' => 'مبلغ *',
            'type' => 'نوع (درآمد/هزینه) *',
            'title' => 'عنوان',
            'category' => 'دسته‌بندی',
            'wallet' => 'حساب',
            'note' => 'توضیح',
        ];
        // حدس خودکار بر اساس نام ستون
        $guesses = [
            'date' => ['تاریخ', 'date'],
            'amount' => ['مبلغ', 'amount', 'price'],
            'type' => ['نوع', 'type'],
            'title' => ['عنوان', 'title', 'شرح', 'desc'],
            'category' => ['دسته', 'category'],
            'wallet' => ['حساب', 'wallet', 'کیف'],
            'note' => ['توضیح', 'note', 'یادداشت'],
        ];
        ?>

        <div class="map-grid">
            <?php foreach ($fields as $key => $label): ?>
                <?php
                $guessIdx = -1;
                foreach ($headers as $i => $hName) {
                    foreach ($guesses[$key] as $g) {
                        if (mb_stripos($hName, $g) !== false) { $guessIdx = $i; break 2; }
                    }
                }
                ?>
                <div class="form-group">
                    <label><?= h($label) ?></label>
                    <select class="map-select" data-field="<?= h($key) ?>">
                        <option value="-1">— ندارد —</option>
                        <?php foreach ($headers as $i => $hName): ?>
                            <option value="<?= (int)$i ?>" <?= $guessIdx === $i ? 'selected' : '' ?>>
                                <?= h($hName !== '' ? $hName : 'ستون ' . toPersianDigits($i + 1)) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            <?php endforeach; ?>
        </div>

        <button type="button" class="btn btn-secondary btn-block" id="previewBtn">بررسی و پیش‌نمایش</button>

        <div id="previewArea" hidden>
            <div class="import-summary" id="importSummary"></div>
            <div class="table-wrapper">
                <table class="data-table" id="previewTable"></table>
            </div>
            <button type="submit" class="btn btn-primary btn-block btn-large" id="commitBtn" style="margin-top:14px;">ثبت نهایی</button>
        </div>
    </form>
</div>

<script>
    window.IMPORT_ROWS = <?= json_encode($rawRows, JSON_UNESCAPED_UNICODE) ?>;
    window.IMPORT_CATEGORIES = <?= json_encode(array_values(array_map(fn($c) => ['name' => $c['name'], 'id' => (int)$c['id'], 'type' => $c['type']], $catMap)), JSON_UNESCAPED_UNICODE) ?>;
    window.IMPORT_WALLETS = <?= json_encode(array_map(fn($k, $v) => ['name' => $k, 'id' => $v], array_keys($walletMap), array_values($walletMap)), JSON_UNESCAPED_UNICODE) ?>;
    window.IMPORT_DEFAULT_WALLET = <?= $defaultWalletId !== null ? (int)$defaultWalletId : 'null' ?>;
</script>
<?php endif; ?>

<meta name="csrf-token" content="<?= Csrf::token() ?>">

<?php include __DIR__ . '/includes/footer.php'; ?>
