<?php
/**
 * آمارِ استفاده برای مدیر — جایگزینِ «تراکنش همه کاربران».
 *
 * ⛔ آن صفحه‌ی قبلی محتوای تراکنشِ همه را نشان می‌داد: هم بی‌فایده بود
 *    (مدیر با فهرستِ خرید و قرضِ بقیه کاری ندارد) هم بدترین شکلِ نقضِ
 *    حریم خصوصی در کلِ اپ. حذف شد.
 *
 * ⛔ چیزی که جایش آمد **عمداً فقط شمارش است، نه محتوا**: چند تراکنش،
 *    آخرین فعالیت کِی. نه عنوان، نه مبلغ، نه طرفِ حساب. مدیر باید
 *    بفهمد کدام بخشِ اپ استفاده می‌شود و کجا کاربر گیر می‌کند — برای
 *    این هیچ‌کدامِ آن جزئیات لازم نیست.
 *
 * ⚠ هیچ ردیابیِ تازه‌ای اضافه نشده و هیچ جدولِ لاگی ساخته نمی‌شود.
 *   همه‌ی این اعداد از داده‌ای که از قبل هست ساخته می‌شوند. اگر روزی
 *   وسوسه شدید «رویداد» ثبت کنید، بدانید که آن یعنی ساختنِ یک ردِ
 *   رفتاری از کاربران که تا امروز وجود نداشته.
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/functions.php';

/**
 * برای هر قابلیت: چند کاربر دست‌کم یک بار استفاده‌اش کرده‌اند.
 *
 * کلیدِ هر قلم `[برچسب, جدول]` است. جدولی که هنوز با migration نیامده
 * باشد رد می‌شود، نه اینکه صفحه را بشکند.
 *
 * @return array<int, array{label:string, users:int, rows:int, share:float}>
 */
function featureAdoption(int $totalUsers): array
{
    $features = [
        ['تراکنش',            'transactions'],
        ['حساب و کیف پول',    'wallets'],
        ['طلب و بدهی',        'debts'],
        ['چک',                'cheques'],
        ['بودجه‌بندی',        'budgets'],
        ['اهداف پس‌انداز',    'savings_goals'],
        ['دارایی',            'assets'],
        ['معاملات',           'trades'],
        ['تراکنش دوره‌ای',    'recurring_transactions'],
        ['اشخاص',             'people'],
        ['انتقال بین حساب‌ها', 'transfers'],
        ['پیوست',             'attachments'],
        ['اپ موبایل (توکن)',  'api_tokens'],
    ];

    $pdo = Database::getConnection();
    $out = [];

    foreach ($features as [$label, $table]) {
        if (!tableExists($table)) { continue; }
        try {
            // نامِ جدول از فهرستِ ثابتِ بالا می‌آید، نه از ورودی کاربر.
            $r = $pdo->query(
                "SELECT COUNT(DISTINCT user_id) AS u, COUNT(*) AS c FROM `{$table}`"
            )->fetch();
        } catch (PDOException $e) {
            continue;
        }
        $users = (int)($r['u'] ?? 0);
        $out[] = [
            'label' => $label,
            'users' => $users,
            'rows'  => (int)($r['c'] ?? 0),
            'share' => $totalUsers > 0 ? round($users * 100 / $totalUsers) : 0,
        ];
    }

    // پراستفاده‌ترین اول — همان چیزی که سؤال است.
    usort($out, fn($a, $b) => $b['users'] <=> $a['users'] ?: $b['rows'] <=> $a['rows']);
    return $out;
}

/**
 * وضعیتِ هر کاربر: چقدر فعال است و آخرین بار کِی چیزی ثبت کرده.
 *
 * ⛔ «آخرین فعالیت» از تاریخِ ثبتِ رکوردها می‌آید، نه از یک لاگِ ورود.
 *    کسی که اپ را باز می‌کند ولی چیزی ثبت نمی‌کند، از دیدِ محصول
 *    فعال نیست — و ما هم نمی‌خواهیم ورودها را ثبت کنیم.
 */
function userActivity(): array
{
    $pdo = Database::getConnection();

    $rows = $pdo->query(
        'SELECT u.id, u.full_name, u.username, u.role, u.is_active, u.created_at,
                (SELECT COUNT(*) FROM transactions t WHERE t.user_id = u.id) AS tx,
                (SELECT MAX(t.created_at) FROM transactions t WHERE t.user_id = u.id) AS last_tx
         FROM users u
         ORDER BY u.created_at ASC'
    )->fetchAll();

    $today = new DateTimeImmutable('today');
    foreach ($rows as $i => $r) {
        $days = null;
        if ($r['last_tx']) {
            $d = new DateTimeImmutable(substr($r['last_tx'], 0, 10));
            $days = (int)$today->diff($d)->days;
        }
        $rows[$i]['days_since'] = $days;
        // سه حالتِ روشن، نه یک عددِ خام: مدیر باید بتواند در یک نگاه
        // بفهمد چه کسی نیاز به کمک دارد.
        $rows[$i]['state'] = $r['tx'] == 0 ? 'never'
            : ($days !== null && $days <= 14 ? 'active' : 'stale');
    }
    return $rows;
}

/**
 * قیفِ شروعِ کار — مهم‌ترین عددِ یک محصول.
 *
 * ⛔ «چند نفر ثبت‌نام کردند» بی‌معناست اگر ندانیم چند نفرشان **شروع
 *    کردند**. کاربری که حساب ساخته و هیچ تراکنشی ثبت نکرده، یعنی جایی
 *    در همان دقیقه‌ی اول گیر کرده — و آن دقیقه تنها جایی است که با
 *    اصلاحش همه چیز عوض می‌شود.
 */
function onboardingFunnel(array $activity): array
{
    $total = count($activity);
    $one   = count(array_filter($activity, fn($r) => (int)$r['tx'] >= 1));
    $five  = count(array_filter($activity, fn($r) => (int)$r['tx'] >= 5));
    $keep  = count(array_filter($activity, fn($r) => $r['state'] === 'active'));

    $pc = fn($n) => $total > 0 ? round($n * 100 / $total) : 0;

    return [
        ['label' => 'حساب ساخته',            'n' => $total, 'pc' => 100],
        ['label' => 'اولین تراکنش را ثبت کرده', 'n' => $one,  'pc' => $pc($one)],
        ['label' => 'دست‌کم ۵ تراکنش',        'n' => $five, 'pc' => $pc($five)],
        ['label' => 'در ۱۴ روز اخیر فعال',    'n' => $keep, 'pc' => $pc($keep)],
    ];
}

/**
 * رشدِ ماهانه‌ی کاربران، به تاریخِ شمسی.
 *
 * ⚠ گروه‌بندی روی تاریخِ **میلادی** انجام می‌شود و بعد در PHP به شمسی
 *   تبدیل می‌شود — نه برعکس. ماهِ شمسی وسطِ ماهِ میلادی شروع می‌شود، پس
 *   گروه‌بندیِ SQL روی `YEAR/MONTH` میلادی و برچسبِ شمسی کنارِ هم غلط
 *   می‌شدند. اینجا هر روز جدا شمرده و در PHP در ماهِ شمسیِ خودش جمع
 *   می‌شود.
 */
function userGrowthByJalaliMonth(int $months = 6): array
{
    $pdo = Database::getConnection();
    $rows = $pdo->query(
        'SELECT DATE(created_at) AS d, COUNT(*) AS c FROM users GROUP BY DATE(created_at)'
    )->fetchAll();

    $buckets = [];
    foreach ($rows as $r) {
        [$jy, $jm] = explode('/', toLatinDigits(toJalali($r['d'])));
        $key = $jy . '/' . str_pad($jm, 2, '0', STR_PAD_LEFT);
        $buckets[$key] = ($buckets[$key] ?? 0) + (int)$r['c'];
    }
    krsort($buckets);
    return array_slice($buckets, 0, $months, true);
}

/**
 * چیزهایی که اگر خاموش باشند، بخشی از اپ برای کسی کار نمی‌کند.
 * هر قلم یک جمله‌ی «چه چیزی خراب است» دارد، نه فقط یک وضعیت.
 */
function healthChecks(): array
{
    $out = [];

    $mail = defined('MAIL_METHOD') && MAIL_METHOD !== '';
    $out[] = [
        'ok'    => $mail,
        'label' => 'ارسال ایمیل',
        'note'  => $mail
            ? 'بازیابی رمز و یادآوری سررسید کار می‌کنند.'
            : 'بازیابی رمز و یادآوری سررسید **هیچ‌کدام** کار نمی‌کنند. deploy/mail-setup.sh',
    ];

    $rem = tableExists('notification_prefs');
    $out[] = [
        'ok'    => $rem,
        'label' => 'جدول یادآوری',
        'note'  => $rem ? 'آماده است.' : 'migration_reminders اجرا نشده.',
    ];

    $sup = getSetting('support_email', '') !== '';
    $out[] = [
        'ok'    => $sup,
        'label' => 'ایمیل پشتیبانی',
        'note'  => $sup
            ? 'در صفحه‌ی حریم خصوصی به کاربران نشان داده می‌شود.'
            : 'تنظیم نشده — کاربر راهی برای تماس ندارد.',
    ];

    return $out;
}
