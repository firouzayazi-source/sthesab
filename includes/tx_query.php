<?php
/**
 * خواندنِ تراکنش‌ها — صافی‌ها و جست‌وجو، یک تعریف برای همه‌ی مصرف‌کننده‌ها.
 *
 * ⛔ چرا فایلِ جدا: `includes/transactions.php` سمتِ **نوشتن** است و
 *    وب و API هر دو از آن رد می‌شوند، دقیقاً به این دلیل که دو نسخه از
 *    منطقِ پول دیر یا زود از هم دور می‌افتند. همین استدلال برای سمتِ
 *    **خواندن** هم درست است: `transactions.php` صافی‌ها را می‌سازد و
 *    `api/export_transactions.php` باید **دقیقاً همان** ردیف‌ها را بدهد.
 *    اگر هر کدام صافیِ خودش را داشت، کاربر چیزی را می‌دید و چیزِ
 *    دیگری را دانلود می‌کرد — و آن خرابی **بی‌صداست**، چون فایلِ CSV
 *    را کسی با صفحه مقایسه نمی‌کند.
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/functions.php';

/**
 * سقفِ طولِ عبارتِ جست‌وجو.
 *
 * نه برای امنیت (پارامتر bind می‌شود) بلکه برای اینکه یک رشته‌ی
 * ده‌کیلوبایتی در `LIKE '%…%'` روی کلِ تاریخچه بی‌فایده کند نشود.
 */
const TX_SEARCH_MAX = 100;

/**
 * عبارتِ خامِ کاربر → عبارتِ جست‌وجوی تمیز.
 *
 * ⛔ تنها جایی که این نرمال‌سازی انجام می‌شود (مثل
 *    `SmsLogin::normalizePhone()`): اگر صفحه و اندپوینتِ خروجی دو
 *    نرمال‌سازیِ متفاوت داشته باشند، فایل با چیزی که روی صفحه دیده شده
 *    نمی‌خواند و هیچ خطایی هم داده نمی‌شود.
 */
function txSearchTerm($raw): string
{
    $t = trim((string)$raw);
    if ($t === '') { return ''; }
    if (function_exists('mb_substr')) {
        return mb_substr($t, 0, TX_SEARCH_MAX, 'UTF-8');
    }
    return substr($t, 0, TX_SEARCH_MAX);
}

/**
 * اگر عبارتِ جست‌وجو یک **عدد** باشد، مقدارش؛ وگرنه `null`.
 *
 * از `toLatinDigits()` رد می‌شود چون کیبوردِ فارسی «۵۰۰٬۰۰۰» می‌دهد و
 * آن با `500000` یکی نیست — همان قاعده‌ی `normalizeDiscountCode()`.
 * جداکننده‌ی سه‌رقمی (`,` `٬` `.` فاصله) هم پاک می‌شود.
 */
function txSearchAmount(string $term): ?int
{
    $latin = toLatinDigits($term);
    // فقط رقم و جداکننده — «۱۴۰۳/۰۵» یا «قبض ۵۰۰» عدد نیست
    if (!preg_match('/^[0-9][0-9,\x{066C}.\x{00A0} ]*$/u', $latin)) { return null; }
    $digits = preg_replace('/[^0-9]/', '', $latin);
    if ($digits === '' || strlen($digits) > 18) { return null; }
    return (int)$digits;
}

/**
 * قطعه‌ی `WHERE` جست‌وجو — بدونِ عبارت، رشته‌ی خالی.
 *
 * چهار جا را می‌گردد: عنوان، یادداشت، نامِ دسته‌بندی، و **مبلغِ دقیق**.
 *
 * ⛔ روی مبلغ عمداً `LIKE` نیست بلکه تطابقِ **دقیق** است. با `LIKE`،
 *    تایپِ «۵۰۰» هم ۵۰۰ را می‌آورد هم ۵٬۰۰۰ و ۱٬۵۰۰ و ۲۵۰٬۵۰۰ — یعنی
 *    نتیجه‌ای که کاربر نمی‌تواند پیش‌بینی کند و به‌مرور از جست‌وجو
 *    ناامید می‌شود. تطابقِ دقیق قابلِ توضیح است: «همان عددی که تایپ
 *    کردی».
 *
 * ⚠ «طرفِ حساب» اینجا نیست چون جدولِ `transactions` اصلاً ستونِ
 *   `counterparty_name` ندارد — آن ستون مالِ `cheques`/`debts`/`trades`
 *   است و گردشِ هر شخص جای خودش را دارد (`person.php?name=…`).
 *
 * @param string $t نامِ مستعارِ جدولِ تراکنش
 * @param string $c نامِ مستعارِ جدولِ دسته‌بندی (باید JOIN شده باشد)
 */
function transactionSearchSql(string $term, string $t = 't', string $c = 'c'): string
{
    if ($term === '') { return ''; }

    // ⛔ سه پارامترِ **جدا** با یک مقدار، نه یک `:q`ِ تکراری.
    //    `EMULATE_PREPARES = false` است (قاعده‌ی `db.php`) و در حالتِ
    //    آماده‌سازیِ بومیِ MySQL، یک نامِ تکراری پشتیبانی نمی‌شود:
    //    `SQLSTATE[HY093] Invalid parameter number`. یعنی صفحه‌ی
    //    تراکنش‌ها با **هر** جست‌وجویی ۵۰۰ می‌داد. `php -l` این را
    //    نمی‌گیرد؛ خودِ تست گرفتش.
    $parts = [
        "{$t}.title LIKE :q_title ESCAPE '!'",
        "{$t}.note  LIKE :q_note  ESCAPE '!'",
        "{$c}.name  LIKE :q_cat   ESCAPE '!'",
    ];
    if (txSearchAmount($term) !== null) {
        $parts[] = "{$t}.amount = :q_amount";
    }
    return '(' . implode(' OR ', $parts) . ')';
}

/**
 * پارامترهای bind شده‌ی جست‌وجو.
 *
 * ⚠ `%` و `_` در ورودیِ کاربر **فرار داده می‌شوند**. بدونِ آن، عبارتِ
 *   `a_b` هر سه‌کاراکتری را می‌آورد و `%` کلِ جدول را — نه یک رخنه،
 *   ولی نتیجه‌ای که کاربر نمی‌فهمد از کجا آمده. (`!` به‌عنوان کاراکترِ
 *   فرار انتخاب شد نه `\`، چون بک‌اسلش در رشته‌ی SQL خودش یک لایه‌ی
 *   دیگر تفسیر دارد.)
 */
function transactionSearchParams(string $term): array
{
    if ($term === '') { return []; }

    $like = '%' . str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $term) . '%';
    $out  = ['q_title' => $like, 'q_note' => $like, 'q_cat' => $like];

    $amount = txSearchAmount($term);
    if ($amount !== null) { $out['q_amount'] = $amount; }

    return $out;
}

/**
 * همه‌ی صافی‌های صفحه‌ی تراکنش‌ها در یک جا.
 *
 * @param int   $userId     همیشه از `Auth::userId()` — هرگز از ورودی
 * @param array $in         ورودیِ خام (`$_GET` یا `$_POST`)
 * @param array $walletList حساب‌های همین کاربر (برای بررسیِ مالکیت)
 *
 * @return array{where:string,params:array,count_join:string,period:string,
 *               type:string,search:string,from_date:string,to_date:string,
 *               wallet_id:int,wallet_name:string}
 */
function buildTransactionFilter(int $userId, array $in, array $walletList): array
{
    $get = static fn(string $k, string $d = '') => isset($in[$k]) ? trim((string)$in[$k]) : $d;

    $period   = $get('period', 'all');
    $type     = $get('type', 'all');
    $search   = txSearchTerm($get('search'));
    $fromDate = $get('from_date');
    $toDate   = $get('to_date');
    $walletId = (int)$get('wallet', '0');

    $conditions = ['t.user_id = :user_id'];
    $params     = ['user_id' => $userId];

    if ($period === 'today') {
        $conditions[] = 't.transaction_date = :p_from';
        $params['p_from'] = today();
    } elseif ($period === 'week') {
        $conditions[] = 't.transaction_date BETWEEN :p_from AND :p_to';
        $params['p_from'] = startOfWeek();
        $params['p_to']   = today();
    } elseif ($period === 'month') {
        $conditions[] = 't.transaction_date BETWEEN :p_from AND :p_to';
        $params['p_from'] = startOfJalaliMonth();
        $params['p_to']   = today();
    } elseif ($period === 'year') {
        $conditions[] = 't.transaction_date BETWEEN :p_from AND :p_to';
        $params['p_from'] = startOfJalaliYear();
        $params['p_to']   = today();
    } elseif ($period === 'custom' && isValidDate($fromDate) && isValidDate($toDate)) {
        $conditions[] = 't.transaction_date BETWEEN :p_from AND :p_to';
        $params['p_from'] = $fromDate;
        $params['p_to']   = $toDate;
    }

    if ($type === 'income' || $type === 'expense') {
        $conditions[] = 't.type = :type';
        $params['type'] = $type;
    }

    $searchSql = transactionSearchSql($search);
    if ($searchSql !== '') {
        $conditions[] = $searchSql;
        $params += transactionSearchParams($search);
    }

    // ⛔ مالکیتِ حساب همین‌جا سنجیده می‌شود و نه در صفحه: با سنجشِ
    //    جداگانه در هر مصرف‌کننده، اولین مسیری که یادش برود شناسه‌ی
    //    حسابِ کاربرِ دیگری را می‌پذیرد. فهرست از بیرون می‌آید تا
    //    کوئریِ تازه‌ای اضافه نشود — صفحه از قبل آن را دارد.
    $walletName = '';
    if ($walletId > 0) {
        foreach ($walletList as $w) {
            if ((int)$w['id'] === $walletId) { $walletName = (string)$w['name']; break; }
        }
        if ($walletName === '') {
            $walletId = 0;              // مالِ این کاربر نیست — نادیده گرفته می‌شود
        } else {
            $conditions[] = 't.wallet_id = :wallet_id';
            $params['wallet_id'] = $walletId;
        }
    }

    // ⚠ JOIN فقط وقتی که واقعاً جست‌وجویی در کار باشد.
    //   کوئریِ شمارش امروز هیچ JOIN ای ندارد و گروهِ «فهرستِ کوتاه کلِ
    //   تاریخچه را نمی‌خواند» در `test_query_budget` سقفِ ردیفِ خوانده‌شده
    //   دارد؛ JOIN بی‌قید آن سقف را برای بازدیدِ **عادی** بالا می‌برد،
    //   یعنی هزینه‌ی یک قابلیت را به کسی می‌دهد که از آن استفاده نمی‌کند.
    $countJoin = $search === ''
        ? ''
        : 'LEFT JOIN categories c ON c.id = t.category_id';

    return [
        'where'       => 'WHERE ' . implode(' AND ', $conditions),
        'params'      => $params,
        'count_join'  => $countJoin,
        'period'      => $period,
        'type'        => $type,
        'search'      => $search,
        'from_date'   => $fromDate,
        'to_date'     => $toDate,
        'wallet_id'   => $walletId,
        'wallet_name' => $walletName,
    ];
}

/** حساب‌های کاربر — یا آرایه‌ی خالی اگر جدولش هنوز با migration نیامده. */
function txWalletList(int $userId): array
{
    try {
        $st = Database::getConnection()->prepare(
            'SELECT id, name FROM wallets WHERE user_id = :u ORDER BY is_active DESC, sort_order, name'
        );
        $st->execute(['u' => $userId]);
        return $st->fetchAll();
    } catch (PDOException $e) {
        return [];
    }
}
