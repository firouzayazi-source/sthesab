<?php
/**
 * ⛔ ثابتِ ترازِ پول — تنها تستی که «آیا جمع درست است؟» را می‌پرسد.
 *
 * **چرا لازم بود:** مجموعه ۴۳ فایل تست دارد و هر کدام **یک منبعِ پول**
 * را جدا می‌سنجند (`test_money_links` چک و طلب، `test_trades` معامله،
 * `test_installments` اقساط). ولی `walletBalances()` **شش** منبع را با
 * هم جمع می‌زند، و هر خرابیِ واقعیِ این اپ در **درزِ بینِ** آن‌ها بوده:
 * چکِ برگشتی که دو بار شمرده می‌شد، و پولی که با حذفِ حساب بی‌صدا از
 * جمع می‌افتد. هیچ‌کدام از آن تست‌ها درز را نمی‌بینند چون هر کدام فقط
 * به منبعِ خودش نگاه می‌کند.
 *
 * **⛔ دفترِ انتظار در خودِ تست ساخته می‌شود، نه با کوئریِ دوم.** اگر
 * انتظار را با SQL بسازیم، همان منطقِ زیرِ آزمون را دوباره نوشته‌ایم و
 * تست با هر دو **با هم** غلط می‌شود — همان «آزمونی که خودش را می‌سنجد»
 * که یک بار سرِ بخشِ CSV در `test_tx_search` گرفته شد. اینجا تست هر
 * مبلغی را که **خودش درج می‌کند** در `$ledger` می‌گذارد و در پایان
 * همان را با عددِ اپ می‌سنجد.
 *
 * **⛔ و ثابت بودنش مهم‌تر از یک عددِ درست است:** بعد از **هر** تغییرِ
 * پول دوباره سنجیده می‌شود. یک عددِ درستِ لحظه‌ای چیزی را ثابت نمی‌کند؛
 * چیزی که خرابی‌ها را می‌گیرد این است که رابطه **بماند**.
 */

// ---------- نگهبان: فقط خط فرمان ----------
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit('Not found.');
}

require_once __DIR__ . '/lib/assert.php';

T::group('ثابتِ ترازِ پول');

if (!file_exists(__DIR__ . '/../config/config.php')) {
    T::blocked('تست ترازِ پول', 'config/config.php وجود ندارد');
    exit(T::report());
}

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/transactions.php';
require_once __DIR__ . '/../includes/user_data.php';
require_once __DIR__ . '/../includes/undo.php';

try {
    $pdo = Database::getConnection();
} catch (Throwable $e) {
    T::blocked('تست ترازِ پول', 'اتصال به دیتابیس برقرار نشد');
    exit(T::report());
}

$_SESSION = [];                       // `Undo` فقط با همین آرایه کار می‌کند

$TESTU = '__test_money_invariant_user';

// پاک‌سازی از روی ساختارِ واقعیِ دیتابیس، نه فهرستِ دستی — همان درسی که
// `test_api_auth` داد: با آمدنِ هر جدولِ تازه، فهرستِ دستی عقب می‌ماند و
// ساختِ کاربر بی‌صدا شکست می‌خورد.
$cleanup = function () use ($pdo, $TESTU) {
    $st = $pdo->prepare('SELECT id FROM users WHERE username = :u');
    $st->execute(['u' => $TESTU]);
    $old = (int)$st->fetchColumn();
    if ($old) { deleteUserAccount($old); }
    $pdo->prepare('DELETE FROM users WHERE username = :u')->execute(['u' => $TESTU]);
};
$cleanup();

$pdo->prepare(
    "INSERT INTO users (username, password_hash, full_name, role)
     VALUES (:u, :p, 'کاربر تراز', 'user')"
)->execute(['u' => $TESTU, 'p' => password_hash('x', PASSWORD_DEFAULT)]);
$userId = (int)$pdo->lastInsertId();

/** دفترِ انتظار: شناسه‌ی حساب → موجودیِ مورد انتظار (ساخته‌ی خودِ تست) */
$ledger = [];

$mkWallet = function (string $name, string $kind, int $initial, int $order)
        use ($pdo, $userId, &$ledger): int {
    $pdo->prepare(
        'INSERT INTO wallets (user_id, name, kind, initial_balance, sort_order)
         VALUES (:u, :n, :k, :b, :s)'
    )->execute(['u' => $userId, 'n' => $name, 'k' => $kind, 'b' => $initial, 's' => $order]);
    $id = (int)$pdo->lastInsertId();
    $ledger[$id] = $initial;
    return $id;
};

$cashId = $mkWallet('کیف پول', 'cash', 250000, 0);
$bankId = $mkWallet('ملی',     'bank', 3000000, 1);
$altId  = $mkWallet('سامان',   'bank', 500000, 2);

/**
 * ⛔ قلبِ تست. دو چیز را با هم می‌سنجد و هر دو لازم‌اند:
 *   ۱. موجودیِ **هر حساب** با دفترِ انتظار بخواند (خطای جابه‌جایی بینِ
 *      دو حساب را می‌گیرد، که جمعِ کل نمی‌گیرد).
 *   ۲. `totalBalance()` با جمعِ همان دفتر بخواند (پولی که در هیچ حسابی
 *      نمی‌نشیند را می‌گیرد).
 */
$checks = 0;
$assertLedger = function (string $label) use ($userId, &$ledger, &$checks) {
    $checks++;
    $rows  = walletBalances($userId);
    $seen  = [];
    $bad   = [];
    foreach ($rows as $w) {
        $id = (int)$w['id'];
        $seen[$id] = (int)$w['balance'];
        if (!array_key_exists($id, $ledger)) {
            $bad[] = "حسابِ ناشناخته #{$id}";
            continue;
        }
        if ($seen[$id] !== $ledger[$id]) {
            $bad[] = "حساب #{$id}: انتظار {$ledger[$id]}  ولی {$seen[$id]}";
        }
    }
    foreach ($ledger as $id => $_) {
        if (!array_key_exists($id, $seen)) { $bad[] = "حساب #{$id} اصلاً برنگشت"; }
    }

    T::ok(!$bad, "تراز پس از «{$label}»", implode('  |  ', $bad));

    $want = array_sum($ledger);
    T::same($want, totalBalance($userId, $rows), "جمعِ کل پس از «{$label}»");
};

$assertLedger('ساختِ حساب‌ها');

// =====================================================================
// منبع ۱ — تراکنش (از مسیرِ واقعیِ نوشتن، نه INSERT دستی)
// =====================================================================
T::group('منبع ۱ — تراکنش');

$catId = null;
$st = $pdo->prepare(
    'SELECT id FROM categories WHERE type = "expense" AND ' . categoryScopeSql() . ' LIMIT 1'
);
$st->execute(categoryScopeParams($userId));
$catId = $st->fetchColumn();
$catId = $catId === false ? null : (int)$catId;

$txIds = [];
$mkTx = function (string $type, int $amount, int $walletId)
        use ($userId, &$ledger, &$txIds, $catId): int {
    $res = txCreate($userId, [
        'type'             => $type,
        'amount'           => (string)$amount,
        'title'            => 'تراکنشِ تراز',
        'transaction_date' => today(),
        'wallet_id'        => (string)$walletId,
        'category_id'      => $catId === null ? '' : (string)$catId,
    ]);
    if (empty($res['ok'])) { return 0; }
    $ledger[$walletId] += ($type === 'income' ? $amount : -$amount);
    $txIds[] = (int)$res['id'];
    return (int)$res['id'];
};

$t1 = $mkTx('income', 1200000, $bankId);
$t2 = $mkTx('expense', 430000, $cashId);
$t3 = $mkTx('expense', 75000, $bankId);

T::ok($t1 && $t2 && $t3, 'هر سه تراکنش از مسیرِ واقعی ثبت شدند');
$assertLedger('ثبتِ سه تراکنش');

// =====================================================================
// منبع ۲ — انتقال بین حساب‌ها (کارمزد هم از مبدأ کم می‌شود)
// =====================================================================
T::group('منبع ۲ — انتقال');

if (tableExists('transfers')) {
    $pdo->prepare(
        'INSERT INTO transfers (user_id, from_wallet_id, to_wallet_id, amount, fee, transfer_date)
         VALUES (:u, :f, :t, :a, :fee, :d)'
    )->execute(['u' => $userId, 'f' => $bankId, 't' => $altId,
                'a' => 600000, 'fee' => 5000, 'd' => today()]);
    $ledger[$bankId] -= (600000 + 5000);
    $ledger[$altId]  += 600000;
    $assertLedger('انتقال با کارمزد');

    // ⚠ کارمزد عمداً به هیچ حسابی اضافه نمی‌شود — از سیستم خارج می‌شود.
    //   پس جمعِ کل باید دقیقاً به اندازه‌ی کارمزد کم شده باشد؛ اگر روزی
    //   کسی آن را به مقصد اضافه کند، همین بررسی قرمز می‌شود.
    T::pass('کارمزدِ انتقال از جمعِ کل کم می‌شود و جایی اضافه نمی‌شود');
} else {
    T::skip('انتقال', 'جدول transfers نیست');
}

// =====================================================================
// منبع ۳ — چکِ پاس‌شده
// =====================================================================
T::group('منبع ۳ — چک');

if (tableHasColumn('cheques', 'settle_wallet_id')) {
    $mkCheque = function (string $dir, int $amount, ?int $wid, int $settled)
            use ($pdo, $userId, &$ledger) {
        $pdo->prepare(
            'INSERT INTO cheques (user_id, direction, counterparty_name, amount,
                                  due_date, is_settled, settle_wallet_id)
             VALUES (:u, :d, :n, :a, :dd, :s, :w)'
        )->execute(['u' => $userId, 'd' => $dir, 'n' => 'طرفِ تراز', 'a' => $amount,
                    'dd' => today(), 's' => $settled, 'w' => $wid]);
        if ($settled === 1 && $wid !== null) {
            $ledger[$wid] += ($dir === 'received' ? $amount : -$amount);
        }
    };

    $mkCheque('received', 900000, $bankId, 1);
    $mkCheque('issued',   250000, $bankId, 1);
    $mkCheque('received', 800000, $bankId, 0);   // در جریان — نباید اثر کند
    $mkCheque('received', 700000, null,    1);   // بی‌حساب — نباید اثر کند
    $assertLedger('چکِ پاس‌شده، در جریان، و بی‌حساب');
} else {
    T::skip('چک', 'migration_money_links اجرا نشده');
}

// =====================================================================
// منبع ۴ — پرداختِ طلب و بدهی
// =====================================================================
T::group('منبع ۴ — طلب و بدهی');

if (tableHasColumn('debt_payments', 'wallet_id')) {
    $mkDebt = function (string $dir, int $amount) use ($pdo, $userId): int {
        $pdo->prepare(
            'INSERT INTO debts (user_id, direction, counterparty_name, amount, entry_date, due_date)
             VALUES (:u, :d, :n, :a, :ed, :dd)'
        )->execute(['u' => $userId, 'd' => $dir, 'n' => 'طرفِ تراز',
                    'a' => $amount, 'ed' => today(), 'dd' => today()]);
        return (int)$pdo->lastInsertId();
    };
    $pay = function (int $debtId, string $dir, int $amount, int $wid)
            use ($pdo, $userId, &$ledger) {
        $pdo->prepare(
            'INSERT INTO debt_payments (user_id, debt_id, amount, payment_date, wallet_id)
             VALUES (:u, :d, :a, :dt, :w)'
        )->execute(['u' => $userId, 'd' => $debtId, 'a' => $amount,
                    'dt' => today(), 'w' => $wid]);
        $ledger[$wid] += ($dir === 'receivable' ? $amount : -$amount);
    };

    $recv = $mkDebt('receivable', 1000000);
    $owe  = $mkDebt('payable',     400000);
    $pay($recv, 'receivable', 300000, $cashId);
    $pay($owe,  'payable',    150000, $bankId);
    $assertLedger('وصولِ طلب و پرداختِ بدهی');
} else {
    T::skip('طلب و بدهی', 'migration_money_links اجرا نشده');
}

// =====================================================================
// منبع ۵ — معامله (خرید از حساب، فروش به حساب)
// =====================================================================
T::group('منبع ۵ — معامله');

if (tradesTablesExist($pdo)) {
    $pdo->prepare(
        'INSERT INTO trades (user_id, title, qty, buy_total, side_costs,
                             buy_date, buy_wallet_id)
         VALUES (:u, :n, 1, :t, 0, :d, :w)'
    )->execute(['u' => $userId, 'n' => 'سکه', 't' => 800000, 'd' => today(), 'w' => $bankId]);
    $tradeId = (int)$pdo->lastInsertId();
    $ledger[$bankId] -= 800000;
    $assertLedger('خریدِ معامله');

    $pdo->prepare(
        'INSERT INTO trade_sales (user_id, trade_id, qty, sale_total, sale_date, wallet_id)
         VALUES (:u, :t, 1, :s, :d, :w)'
    )->execute(['u' => $userId, 't' => $tradeId, 's' => 950000, 'd' => today(), 'w' => $altId]);
    $ledger[$altId] += 950000;
    $assertLedger('فروشِ معامله');
} else {
    T::skip('معامله', 'جدول‌های trades نیستند');
}

// =====================================================================
// منبع ۶ — تعدیلِ موجودی (روی `initial_balance` می‌نشیند، نه تراکنش)
// =====================================================================
T::group('منبع ۶ — تعدیلِ موجودی');

$pdo->prepare('UPDATE wallets SET initial_balance = initial_balance + 120000
               WHERE id = :i AND user_id = :u')
    ->execute(['i' => $cashId, 'u' => $userId]);
$ledger[$cashId] += 120000;
$assertLedger('تعدیلِ موجودی');

// =====================================================================
// ⛔ ثابت باید بعد از **تغییر** هم برقرار بماند
// =====================================================================
T::group('ثابت پس از ویرایش، حذف، و لغو');

/**
 * ⛔ `txUpdate()` عمداً `wallet_id` را عوض **نمی‌کند** — همان‌طور که بالای
 *    خودش نوشته شده. اینجا با یک `wallet_id`ِ متفاوت صدا زده می‌شود تا
 *    همان تصمیم پین شود: اگر روزی کسی «کاملش کند» و حساب را هم جابه‌جا
 *    کند، پول بی‌سروصدا بین دو حساب می‌پرد و هیچ تستِ دیگری نمی‌بیندش.
 *
 * ⚠ و انتظارِ **اولِ خودم اینجا غلط بود** (فرض کردم حساب هم عوض می‌شود)؛
 *   همین تست گرفتش، نه بازبینیِ چشمی — که دقیقاً کاری است که ازش
 *   انتظار می‌رود.
 */
$res = txUpdate($userId, $t3, [
    'type'             => 'expense',
    'amount'           => '99000',
    'title'            => 'تراکنشِ تراز',
    'transaction_date' => today(),
    'wallet_id'        => (string)$altId,      // عمداً متفاوت — باید نادیده بماند
    'category_id'      => $catId === null ? '' : (string)$catId,
]);
T::ok(!empty($res['ok']), 'ویرایشِ تراکنش انجام شد', json_encode($res, JSON_UNESCAPED_UNICODE));
$ledger[$bankId] += 75000;      // مبلغِ قبلی برگشت
$ledger[$bankId] -= 99000;      // مبلغِ تازه، روی **همان** حساب
$assertLedger('ویرایشِ مبلغ (حساب عمداً جابه‌جا نمی‌شود)');

$st = $pdo->prepare('SELECT wallet_id FROM transactions WHERE id = :i AND user_id = :u');
$st->execute(['i' => $t3, 'u' => $userId]);
T::same($bankId, (int)$st->fetchColumn(),
    'ویرایش حساب را جابه‌جا نمی‌کند (تصمیمِ ثبت‌شده‌ی `txUpdate`)');

// حذف → لغو، و دفتر **نباید** عوض شود: اگر «لغو» ناقص برگرداند، همین
// یک بررسی می‌گیردش. (همان چیزی که سرِ فرزندانِ CASCADE یک بار گرفته شد.)
$token = Undo::capture('transactions', $t2, $userId);
T::ok($token !== null, 'عکسِ پیش از حذف گرفته شد');

$del = txDelete($userId, $t2);
T::ok(!empty($del['ok']), 'حذف انجام شد');
$ledger[$cashId] += 430000;     // هزینه برگشت
$assertLedger('حذفِ تراکنش');

if ($token !== null) {
    $back = Undo::restore($token, $userId);
    T::ok(!empty($back['ok']), 'لغو انجام شد', json_encode($back, JSON_UNESCAPED_UNICODE));
    $ledger[$cashId] -= 430000;  // دوباره همان هزینه
    $assertLedger('لغوِ حذف');
}

// ⚠ و آخرین بررسی: حسابِ **غیرفعال** از جمعِ کل بیرون می‌رود ولی خودش
//   موجودی‌اش را نگه می‌دارد. این دو با هم سنجیده می‌شوند، وگرنه
//   «غیرفعال کردن = صفر شدنِ پول» بی‌صدا از آب درمی‌آمد.
$pdo->prepare('UPDATE wallets SET is_active = 0 WHERE id = :i AND user_id = :u')
    ->execute(['i' => $altId, 'u' => $userId]);

$rows = walletBalances($userId);
$altBalance = null;
foreach ($rows as $w) { if ((int)$w['id'] === $altId) { $altBalance = (int)$w['balance']; } }

T::same($ledger[$altId], $altBalance, 'حسابِ غیرفعال موجودی‌اش را نگه می‌دارد');
T::same(array_sum($ledger) - $ledger[$altId], totalBalance($userId, $rows),
    'ولی از جمعِ کل بیرون می‌رود');

T::pass("ثابتِ تراز {$checks} بار پس از تغییرهای مختلف سنجیده شد");

// ---------------------------------------------------------------
$cleanup();

exit(T::report());
