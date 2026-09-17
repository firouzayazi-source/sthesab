<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/functions.php';

Auth::initSession();
header('Content-Type: application/json; charset=utf-8');

if (!Auth::isLoggedIn()) { jsonResponse(['success' => false, 'message' => 'ابتدا وارد شوید.'], 401); }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { jsonResponse(['success' => false, 'message' => 'درخواست نامعتبر است.'], 405); }
Csrf::verifyOrFail(postParam('csrf_token'));

$userId = Auth::userId();
$goalId = (int)postParam('goal_id');
$title  = postParam('title');
$target = sanitizeAmount(postParam('target_amount'));
$date   = postParam('target_date');
$color  = postParam('color', '#16794f');

$errors = [];
if ($title === '' || mb_strlen($title) > 150) { $errors[] = 'عنوان هدف الزامی است.'; }
if ($target <= 0) { $errors[] = 'مبلغ هدف باید بزرگ‌تر از صفر باشد.'; }
if ($target > 999999999999) { $errors[] = 'مبلغ بیش از حد بزرگ است.'; }
if ($date !== '' && !isValidDate($date)) { $errors[] = 'تاریخ نامعتبر است.'; }
if (!preg_match('/^#[0-9a-fA-F]{6}$/', $color)) { $color = '#16794f'; }

if (!empty($errors)) { jsonResponse(['success' => false, 'message' => implode(' ', $errors)], 422); }

$pdo = Database::getConnection();

// ⛔ حسابِ ورودی باید مالِ **خودِ کاربر** باشد، وگرنه `NULL`.
//
//    اینجا عمداً از `resolveWalletId()` رد نمی‌شود: آن تابع برای پولِ
//    واقعی است و اگر ورودی نامعتبر باشد **حسابِ پیش‌فرض** را برمی‌گرداند
//    تا پول در هیچ‌کجا گم نشود. اینجا پولی جابه‌جا نمی‌شود و «مشخص نشده»
//    یک انتخابِ درست است — پس صفر یعنی صفر، نه کیف پول.
//
// ⚠ ولی قاعده‌ی مالکیت همان است: بدونِ این شرط هر کاربری می‌توانست
//   هدفش را به حسابِ کاربرِ دیگری بچسباند و نامِ آن حساب را روی کارتِ
//   خودش ببیند — همان نشتی‌ای که یک بار در `data.php` رخ داد.
$walletId = (int)postParam('wallet_id');
$hasWalletCol = tableHasColumn('savings_goals', 'wallet_id');
if ($walletId > 0) {
    $wq = $pdo->prepare('SELECT id FROM wallets WHERE id = :w AND user_id = :u');
    $wq->execute(['w' => $walletId, 'u' => $userId]);
    if (!$wq->fetchColumn()) { $walletId = 0; }
}
$walletParam = $walletId > 0 ? $walletId : null;

try {
    if ($goalId > 0) {
        $own = $pdo->prepare('SELECT id FROM savings_goals WHERE id = :id AND user_id = :u');
        $own->execute(['id' => $goalId, 'u' => $userId]);
        if (!$own->fetch()) { jsonResponse(['success' => false, 'message' => 'هدف یافت نشد.'], 404); }

        // ⚠ ستونِ حساب فقط وقتی نوشته می‌شود که migration آمده باشد،
        //   وگرنه نصبِ عقب‌مانده با «Unknown column» می‌شکست.
        //
        // ⛔ دو کوئریِ **کاملِ جدا**، نه یک رشته‌ی ساخته‌شده با الحاق.
        //    قاعده ۴ در `test_api_contract.php` هر درجِ متغیر در SQL را
        //    رد می‌کند و همین درست است: امروز `$cols` مقدارِ ثابتی از
        //    خودِ کد است، ولی نسخه‌ی بعدی که یک نامِ ستون را از ورودی
        //    بگیرد دقیقاً همین شکل را دارد و آن‌وقت هیچ تستی جلویش را
        //    نمی‌گیرد. (اولین نسخه‌ی همین فایل الحاق داشت و تست گرفتش.)
        $args = ['t' => $title, 'a' => $target, 'd' => $date !== '' ? $date : null,
                 'c' => $color, 'id' => $goalId, 'u' => $userId];
        if ($hasWalletCol) {
            $args['w'] = $walletParam;
            $pdo->prepare(
                'UPDATE savings_goals
                 SET title = :t, target_amount = :a, target_date = :d, color = :c, wallet_id = :w
                 WHERE id = :id AND user_id = :u'
            )->execute($args);
        } else {
            $pdo->prepare(
                'UPDATE savings_goals
                 SET title = :t, target_amount = :a, target_date = :d, color = :c
                 WHERE id = :id AND user_id = :u'
            )->execute($args);
        }
        jsonResponse(['success' => true, 'message' => 'هدف بروزرسانی شد.']);
    }

    $args = ['u' => $userId, 't' => $title, 'a' => $target,
             'd' => $date !== '' ? $date : null, 'c' => $color];
    if ($hasWalletCol) {
        $args['w'] = $walletParam;
        $pdo->prepare(
            'INSERT INTO savings_goals (user_id, title, target_amount, target_date, color, wallet_id)
             VALUES (:u, :t, :a, :d, :c, :w)'
        )->execute($args);
    } else {
        $pdo->prepare(
            'INSERT INTO savings_goals (user_id, title, target_amount, target_date, color)
             VALUES (:u, :t, :a, :d, :c)'
        )->execute($args);
    }
    jsonResponse(['success' => true, 'message' => 'هدف ساخته شد.']);
} catch (PDOException $e) {
    Log::error('api.save_goal', $e);
    jsonResponse(['success' => false, 'message' => 'خطایی رخ داد.'], 500);
}
