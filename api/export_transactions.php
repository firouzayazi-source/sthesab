<?php
/**
 * خروجیِ CSV از تراکنش‌های **فیلترشده** — همان چیزی که روی صفحه دیده شد.
 *
 * ⛔ POST است نه GET، با CSRF — دقیقاً به دلیلِ `export_data.php`: یک
 *    لینکِ GET را می‌شود در `<img src>` جای دیگری جاسازی کرد و مرورگرِ
 *    کاربرِ واردشده خودش صدایش می‌زند. خروجیِ اینجا می‌تواند کلِ
 *    تاریخچه‌ی مالیِ او باشد.
 *
 * ⛔ صافی‌ها از `buildTransactionFilter()` می‌آیند، نه از کدِ همین فایل.
 *    با نسخه‌ی دوم، کاربر یک فهرست می‌دید و فهرستِ دیگری دانلود می‌کرد —
 *    و کسی فایلِ CSV را با صفحه مقایسه نمی‌کند، پس خرابی **بی‌صداست**.
 */

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/tx_query.php';

Auth::initSession();

/**
 * شکست نباید کاربر را در صفحه‌ی سفیدِ یک‌خطیِ JSON رها کند.
 *
 * ⚠ `Csrf::isJsonRequest()` خصوصی است، پس همان شرط اینجا تکرار می‌شود:
 *   هر دو سرآیند لازم‌اند چون `fetch` های `app.js` `X-Requested-With`
 *   می‌فرستند ولی `Accept: application/json` نه.
 */
function csvFail(string $message, int $status = 400): void
{
    $isAjax = strtolower((string)($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')) === 'xmlhttprequest'
           || str_contains((string)($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json');

    if ($isAjax) {
        header('Content-Type: application/json; charset=utf-8');
        jsonResponse(['success' => false, 'message' => $message], $status);
    }
    redirectWithMessage('../transactions.php', 'error', $message);
}

// ⚠ اینجا عمداً ۴۰۱ است نه هدایت: قاعده‌ی `api/` همین است و
//   `test_api_auth` همه‌ی اندپوینت‌ها را با هم می‌سنجد. کاربرِ واقعی هم
//   به اینجا نمی‌رسد چون دکمه‌اش فقط در `transactions.php` رندر می‌شود
//   که خودش `requireLogin()` دارد.
if (!Auth::isLoggedIn()) {
    header('Content-Type: application/json; charset=utf-8');
    jsonResponse(['success' => false, 'message' => 'ابتدا وارد شوید.'], 401);
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    csvFail('درخواست نامعتبر است.', 405);
}
Csrf::verifyOrFail(postParam('csrf_token'));

$userId = Auth::userId();
$pdo    = Database::getConnection();

try {
    $filter = buildTransactionFilter($userId, $_POST, txWalletList($userId));

    // نامِ حساب فقط اگر جدولش با migration آمده باشد — نصبِ عقب‌مانده
    // هم باید خروجی بگیرد، نه اینکه خطا ببیند.
    $hasWallets = tableExists('wallets');
    $walletSel  = $hasWallets ? ', w.name AS wallet_name' : ", '' AS wallet_name";
    $walletJoin = $hasWallets ? 'LEFT JOIN wallets w ON w.id = t.wallet_id' : '';

    $sql = "
        SELECT t.type, t.amount, t.title, t.note, t.transaction_date,
               c.name AS category_name {$walletSel}
        FROM transactions t
        LEFT JOIN categories c ON c.id = t.category_id
        {$walletJoin}
        {$filter['where']}
        ORDER BY t.transaction_date ASC, t.created_at ASC
    ";
    $stmt = $pdo->prepare($sql);
    Audit::log('data.exported', 'transactions_csv', null, ['kind' => 'csv']);
    $stmt->execute($filter['params']);
} catch (Throwable $e) {
    Log::error('api.export_csv', $e);
    csvFail('خروجی گرفته نشد. اگر تکرار شد به پشتیبانی خبر بدهید.', 500);
}

// ⛔ گزیپی که `db.php` روشن می‌کند باید پیش از فرستادنِ فایل بسته شود،
//    وگرنه چیزی که ذخیره می‌شود دوبار فشرده است و باز نمی‌شود. همان
//    چیزی که یک بار سرِ خروجیِ JSON افتاد.
if (function_exists('ob_get_level')) {
    while (ob_get_level() > 0) { ob_end_clean(); }
}
header_remove('Content-Encoding');

// ⚠ ارقامِ نامِ فایل **لاتین**اند: نامِ حاوی ارقامِ فارسی در سرآیندِ
//   `filename=` غیرمجاز است و مرورگر بی‌سروصدا کنارش می‌گذارد.
$name = 'transactions-' . str_replace('/', '-', toLatinDigits(toJalali(date('Y-m-d')))) . '.csv';

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $name . '"');
header('Cache-Control: no-store, private');

$out = fopen('php://output', 'w');

// ⛔ BOM اجباری است. بدونِ آن، اکسلِ ویندوز فایلِ UTF-8 را با کدپیجِ
//    سیستم می‌خواند و **همه‌ی متنِ فارسی به‌شکلِ علامتِ سؤال و حروفِ
//    درهم** درمی‌آید — فایل باز می‌شود، خطایی نیست، و فقط بی‌فایده
//    است. همان خرابیِ بی‌صدا.
fwrite($out, "\xEF\xBB\xBF");

fputcsv($out, ['تاریخ', 'تاریخ میلادی', 'نوع', 'دسته‌بندی', 'حساب', 'عنوان', 'یادداشت', 'مبلغ (تومان)']);

// ⚠ ردیف‌ها یکی‌یکی خوانده و نوشته می‌شوند، نه `fetchAll()`: با
//   تاریخچه‌ی ده‌هزارتایی، کلِ نتیجه در حافظه‌ی PHP نمی‌نشیند.
while ($row = $stmt->fetch()) {
    fputcsv($out, [
        // تاریخِ شمسی برای خواندن، میلادی برای مرتب‌سازی و فرمولِ اکسل —
        // ⚠ هر دو با ارقامِ لاتین، وگرنه اکسل ستون را متن می‌بیند.
        str_replace('/', '-', toLatinDigits(toJalali($row['transaction_date']))),
        $row['transaction_date'],
        $row['type'] === 'income' ? 'درآمد' : 'هزینه',
        (string)($row['category_name'] ?? ''),
        (string)($row['wallet_name'] ?? ''),
        (string)$row['title'],
        (string)($row['note'] ?? ''),
        // ⛔ عددِ خام، بدونِ `formatMoney()`. با جداکننده و ارقامِ فارسی
        //    اکسل آن را **متن** می‌گیرد و کاربر نمی‌تواند جمعش بزند —
        //    یعنی تنها دلیلِ وجودِ این فایل از بین می‌رفت.
        (int)$row['amount'],
    ]);
}

fclose($out);
