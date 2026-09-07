<?php
/**
 * تغییرِ وضعیتِ یک چک: در انتظار / پاس شد / برگشت خورد / خرج شد.
 *
 * ⛔ چرا این اندپوینت از یک توگلِ دوحالته به چهار وضعیت رسید: چک
 *    تفاوت‌سازترین چیزِ این اپ برای بازار ایران است، ولی مدلش فقط
 *    «پاس شد یا نشد» بود. در واقعیت چکِ دریافتی اغلب **خرج می‌شود**
 *    (به نفر سوم واگذار) و گاهی **برگشت می‌خورد**. کاربر ناچار بود
 *    چکِ خرج‌شده را «پاس شد» بزند — که پول را به اشتباه به حسابش
 *    اضافه می‌کرد — یا حذفش کند و تاریخچه را از دست بدهد.
 *
 * ⚠ پاس شدن چک یعنی پول واقعاً جابه‌جا شده، پس باید معلوم باشد به کدام
 *   حساب رفت. حساب در `cheques.settle_wallet_id` می‌نشیند و
 *   `walletBalances()` همان‌جا جمعش می‌کند — عمداً ردیف تراکنش ساخته
 *   نمی‌شود، چون وصول یک چکِ طلب درآمد نیست و نباید گزارش درآمد/هزینه
 *   را بالا ببرد.
 *
 * ⚠ «خرج شد» و «برگشت خورد» هیچ پولی جابه‌جا نمی‌کنند: چک از جریان
 *   بیرون می‌رود ولی به حسابِ کاربر چیزی اضافه/کم نمی‌شود. برای همین
 *   `settle_wallet_id` در آن دو حالت خالی می‌شود.
 *
 * ⚠ نامِ فایل عمداً عوض نشد. اپ‌های نصب‌شده و کدِ قدیمی همین آدرس را
 *   صدا می‌زنند؛ پارامترِ `status` اختیاری است و بدونش رفتارِ قبلی
 *   (توگلِ پاس/نپاس) کار می‌کند.
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/functions.php';

Auth::initSession();

header('Content-Type: application/json; charset=utf-8');

if (!Auth::isLoggedIn()) {
    jsonResponse(['success' => false, 'message' => 'ابتدا وارد حساب کاربری خود شوید.'], 401);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(['success' => false, 'message' => 'درخواست نامعتبر است.'], 405);
}

Csrf::verifyOrFail(postParam('csrf_token'));

// ⛔ اینجا عمداً `apiRequirePlan()` نیست: این اندپوینت رکوردِ تازه
//    نمی‌سازد، فقط چیزی را که کاربر از قبل ثبت کرده ویرایش/تسویه/حذف
//    می‌کند. بستنش یعنی دفترِ کاربر روی واقعیتِ ماه‌ها پیش یخ می‌زند —
//    و دفترِ غلط از دفترِ نداشته بدتر است. قاعده ۲۶ در
//    `test_api_contract.php` این فهرست را بسته نگه می‌دارد.

$userId   = Auth::userId();
$chequeId = (int)postParam('cheque_id');
if ($chequeId <= 0) {
    jsonResponse(['success' => false, 'message' => 'شناسه نامعتبر است.'], 422);
}

$pdo = Database::getConnection();
$hasStatusCol = tableHasColumn('cheques', 'status');

// ⚠ `SELECT *` عمدی است، نه تنبلی: ستون `status` فقط بعد از
//   migration_cheque_status وجود دارد و ساختنِ فهرستِ ستون‌ها به‌صورت
//   شرطی یعنی درجِ متغیر داخل رشته‌ی SQL — چیزی که قاعده ۴ ممنوع کرده
//   و درست هم می‌گوید (قاعده‌ای که استثنا بگیرد، نادیده گرفته می‌شود).
//   اینجا یک ردیف با شناسه خوانده می‌شود، پس هزینه‌اش صفر است.
$checkStmt = $pdo->prepare('SELECT * FROM cheques WHERE id = :id');
$checkStmt->execute(['id' => $chequeId]);
$cheque = $checkStmt->fetch();

if (!$cheque) {
    jsonResponse(['success' => false, 'message' => 'چک یافت نشد.'], 404);
}

if ((int)$cheque['user_id'] !== $userId) {
    jsonResponse(['success' => false, 'message' => 'شما اجازه تغییر این چک را ندارید.'], 403);
}

// ---------- وضعیتِ مقصد ----------
$requested = (string)postParam('status');
$current   = $hasStatusCol ? (string)$cheque['status'] : ((int)$cheque['is_settled'] === 1 ? 'cleared' : 'pending');

if ($hasStatusCol && in_array($requested, ['pending', 'cleared', 'bounced', 'endorsed'], true)) {
    $newState = $requested;
} else {
    // رفتارِ قدیمی: توگلِ پاس/نپاس. اپ‌های نصب‌شده روی همین‌اند.
    $newState = $current === 'cleared' ? 'pending' : 'cleared';
}

$isSettled = $newState === 'cleared' ? 1 : 0;

// حساب فقط برای «پاس شد» معنا دارد. ستون با migration_money_links
// می‌آید؛ روی نصبی که هنوز اجرا نشده، رفتار قبلی حفظ می‌شود.
$hasWalletCol = tableHasColumn('cheques', 'settle_wallet_id');
$walletId = null;
$walletName = '';
if ($hasWalletCol && $newState === 'cleared') {
    $walletId = resolveWalletId($userId, postParam('wallet_id'));
    if ($walletId !== null) {
        $wn = $pdo->prepare('SELECT name FROM wallets WHERE id = :id AND user_id = :u');
        $wn->execute(['id' => $walletId, 'u' => $userId]);
        $walletName = (string)$wn->fetchColumn();
    }
}

// گیرنده‌ی چکِ خرج‌شده — همان ورودیِ آزادِ personPicker
$endorsedTo = $newState === 'endorsed' ? trim((string)postParam('endorsed_to')) : '';
if (mb_strlen($endorsedTo) > 150) { $endorsedTo = mb_substr($endorsedTo, 0, 150); }

try {
    $pdo->beginTransaction();

    $sets = ['is_settled = :settled', 'settled_at = :settled_at'];
    $params = [
        'settled'    => $isSettled,
        'settled_at' => $isSettled === 1 ? date('Y-m-d H:i:s') : null,
        'id'         => $chequeId,
        'user_id'    => $userId,
    ];
    if ($hasStatusCol) {
        $sets[] = 'status = :status';
        $params['status'] = $newState;
        $sets[] = 'endorsed_to = :endorsed';
        $params['endorsed'] = $newState === 'endorsed' && $endorsedTo !== '' ? $endorsedTo : null;
    }
    if ($hasWalletCol) {
        $sets[] = 'settle_wallet_id = :w';
        $params['w'] = $newState === 'cleared' ? $walletId : null;
    }

    $pdo->prepare('UPDATE cheques SET ' . implode(', ', $sets) . ' WHERE id = :id AND user_id = :user_id')
        ->execute($params);

    // ---------- چکِ برگشتی → طلب ----------
    // ⛔ چکِ دریافتیِ برگشت‌خورده همان لحظه به طلب تبدیل می‌شود؛ پول
    //    نرسیده ولی طرف هنوز بدهکار است. بدون این، کاربر باید دستی یک
    //    طلب می‌ساخت و اگر یادش می‌رفت، مبلغ از دفترش گم می‌شد.
    //    `debts.cheque_id` پیوند را نگه می‌دارد تا برگرداندنِ وضعیت
    //    همان ردیف را پس بگیرد و طلبِ یتیم نماند.
    $debtCreated = false;
    $canLink = $hasStatusCol && tableHasColumn('debts', 'cheque_id');
    if ($canLink) {
        $findDebt = $pdo->prepare('SELECT id FROM debts WHERE cheque_id = :c AND user_id = :u');
        $findDebt->execute(['c' => $chequeId, 'u' => $userId]);
        $linkedDebt = $findDebt->fetchColumn();

        if ($newState === 'bounced' && $cheque['direction'] === 'received' && !$linkedDebt) {
            $pdo->prepare(
                "INSERT INTO debts (user_id, cheque_id, direction, counterparty_name, amount,
                                    paid_amount, entry_date, due_date, note, is_settled)
                 VALUES (:u, :c, 'receivable', :n, :a, 0, :e, :d, :note, 0)"
            )->execute([
                'u' => $userId, 'c' => $chequeId,
                'n' => $cheque['counterparty_name'],
                'a' => (int)$cheque['amount'],
                'e' => today(),
                'd' => $cheque['due_date'],
                'note' => 'از چکِ برگشت‌خورده',
            ]);
            $debtCreated = true;
        } elseif ($newState !== 'bounced' && $linkedDebt) {
            // وضعیت برگردانده شد — طلبِ ساخته‌شده هم پس گرفته می‌شود،
            // ولی فقط اگر کاربر رویش پرداختی ثبت نکرده باشد. وگرنه
            // پاک کردنش یعنی از بین بردنِ کارِ خودِ کاربر.
            $paid = $pdo->prepare('SELECT COUNT(*) FROM debt_payments WHERE debt_id = :d');
            $paid->execute(['d' => $linkedDebt]);
            if ((int)$paid->fetchColumn() === 0) {
                $pdo->prepare('DELETE FROM debts WHERE id = :d AND user_id = :u')
                    ->execute(['d' => $linkedDebt, 'u' => $userId]);
            }
        }
    }

    $pdo->commit();

    $messages = [
        'cleared'  => $walletName !== ''
            ? 'چک پاس شد و مبلغش ' . ($cheque['direction'] === 'received' ? 'به' : 'از')
              . " «{$walletName}» اعمال شد."
            : 'چک پاس شد و به بایگانی رفت.',
        'bounced'  => $debtCreated
            ? 'چک برگشت خورد و به‌عنوان طلب ثبت شد.'
            : 'چک برگشت خورد.',
        'endorsed' => $endorsedTo !== ''
            ? "چک به «{$endorsedTo}» واگذار شد."
            : 'چک خرج شد و از جریان خارج شد.',
        'pending'  => 'به حالت «در انتظار» برگشت و اثرش روی موجودی برداشته شد.',
    ];

    jsonResponse([
        'success'      => true,
        'status'       => $newState,
        'is_settled'   => $isSettled,
        'debt_created' => $debtCreated,
        'message'      => $messages[$newState],
    ]);
} catch (PDOException $e) {
    if ($pdo->inTransaction()) { $pdo->rollBack(); }
    error_log('Toggle Cheque Error: ' . $e->getMessage());
    jsonResponse(['success' => false, 'message' => 'خطایی رخ داد.'], 500);
}
