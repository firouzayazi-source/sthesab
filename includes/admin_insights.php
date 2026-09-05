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
 * ⛔ تفسیرِ قیف — چون عددِ خام هیچ تصمیمی نمی‌سازد.
 *
 *    این صفحه از اول اعدادِ درستی نشان می‌داد، ولی مالکِ نصب باید خودش
 *    نگاه می‌کرد، نسبت می‌گرفت و نتیجه می‌گرفت. عملاً یعنی هیچ‌وقت.
 *    یک عدد که کسی تفسیرش نکند با نبودنش فرقی ندارد — همان استدلالی که
 *    جمله‌های بینشِ صفحه‌ی خانه را ساخت، این بار برای مدیر.
 *
 * ⛔ و **قدمِ بعدی هم گفته می‌شود، نه فقط تشخیص.** «۳۰٪ افت» یک واقعیت
 *    است؛ «کاربر در دقیقه‌ی اول گیر می‌کند، اول فرم ثبت را ساده کن» یک
 *    تصمیم. تفاوتشان همان چیزی است که این صفحه را از یک داشبوردِ
 *    تزئینی جدا می‌کند.
 *
 * ⚠ با نمونه‌ی خیلی کوچک هیچ تفسیری داده نمی‌شود. با ۳ کاربر، «۳۳٪ افت»
 *   یعنی یک نفر — و تصمیم گرفتن از روی یک نفر بدتر از تصمیم نگرفتن است.
 *
 * ⚠ همیشه یک حکم برمی‌گرداند، هرگز `null`. کارتِ خالی روی صفحه‌ی مدیر
 *   یعنی «چیزی خراب است»، در حالی که «نمونه هنوز کوچک است» خودش یک
 *   حرفِ درست و گفتنی است.
 *
 * @return array{tone:string, headline:string, advice:string}
 */
function funnelVerdict(array $funnel): array
{
    $total = (int)($funnel[0]['n'] ?? 0);
    $one   = (int)($funnel[1]['n'] ?? 0);
    $five  = (int)($funnel[2]['n'] ?? 0);
    $keep  = (int)($funnel[3]['n'] ?? 0);

    if ($total < 5) {
        return [
            'tone'     => 'info',
            'headline' => 'هنوز برای نتیجه‌گیری زود است.',
            'advice'   => 'با ' . toPersianDigits((string)$total) . ' کاربر، هر درصدی فقط یک یا دو نفر است. '
                        . 'تا رسیدن به دست‌کم ۵ کاربر، این اعداد تصمیم‌ساز نیستند.',
        ];
    }

    $pcOne  = (int)round($one  * 100 / max(1, $total));
    $pcFive = $one > 0 ? (int)round($five * 100 / $one) : 0;
    $pcKeep = $one > 0 ? (int)round($keep * 100 / $one) : 0;

    // ⛔ ترتیب اهمیت دارد و از **بالای قیف** شروع می‌شود: تا وقتی کاربر
    //    اولین تراکنش را ثبت نکرده، حرف زدن از ماندگاری بی‌معناست.
    if ($pcOne < 50) {
        return [
            'tone'     => 'bad',
            'headline' => 'مشکل در همان دقیقه‌ی اول است.',
            'advice'   => 'فقط ' . toPersianDigits((string)$pcOne) . '٪ از کسانی که حساب ساخته‌اند '
                        . 'حتی یک تراکنش ثبت کرده‌اند. اضافه کردن قابلیتِ تازه اینجا هیچ کمکی نمی‌کند — '
                        . 'اولین کاری که باید کرد ساده کردنِ همان فرمِ ثبت و مسیرِ رسیدن به آن است.',
        ];
    }

    if ($pcFive < 40) {
        return [
            'tone'     => 'warn',
            'headline' => 'کاربر شروع می‌کند ولی ادامه نمی‌دهد.',
            'advice'   => 'از هر کسی که یک تراکنش ثبت کرده، فقط ' . toPersianDigits((string)$pcFive)
                        . '٪ به ۵ تراکنش رسیده. یعنی ثبت کردن به‌قدر کافی سریع نیست، '
                        . 'یا اپ در ازای زحمتِ ثبت چیزی به کاربر برنمی‌گرداند.',
        ];
    }

    if ($pcKeep < 40) {
        return [
            'tone'     => 'warn',
            'headline' => 'کاربر می‌آید و می‌رود.',
            'advice'   => 'فقط ' . toPersianDigits((string)$pcKeep) . '٪ از کسانی که شروع کرده‌اند '
            // ⚠ بدونِ ستاره‌ی تأکید: این متن با `h()` در HTML چاپ می‌شود،
            //   نه مارک‌داون — ستاره‌ها عیناً روی صفحه دیده می‌شدند.
                        . 'در دو هفته‌ی اخیر فعال بوده‌اند. چیزی لازم است که کاربر را برگرداند: '
                        . 'یادآوریِ سررسید و اعلانِ داخلِ اپ دقیقاً برای همین‌اند — ببینید روشن‌اند یا نه.',
        ];
    }

    return [
        'tone'     => 'good',
        'headline' => 'قیف سالم است.',
        'advice'   => toPersianDigits((string)$pcOne) . '٪ شروع می‌کنند و '
                    . toPersianDigits((string)$pcKeep) . '٪ فعال مانده‌اند. '
                    . 'حالا وقتِ درستی است که سراغِ قابلیتِ تازه یا جذبِ کاربرِ بیشتر بروید.',
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
