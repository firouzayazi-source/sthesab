<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/signup.php';
require_once __DIR__ . '/../includes/login_throttle.php';
require_once __DIR__ . '/../includes/user_data.php';
require_once __DIR__ . '/../includes/paged_list.php';

Auth::initSession();
Auth::requireAdmin();

/**
 * ⛔ صافی‌ها و ترتیب — تنها مرجع (همان قاعده‌ی `DUE_TABS` و
 *    `AppErrors::FILTERS`). هم منوها از اینجا رندر می‌شوند هم `$_GET`
 *    با همین‌ها سنجیده می‌شود؛ فهرستِ دوم یعنی گزینه‌ای که کاربر
 *    می‌بیند بی‌صدا به پیش‌فرض برمی‌گردد.
 */
/**
 * ⛔ از `Auth::ROLES` ساخته می‌شود، نه یک فهرستِ دستی.
 *
 * تا دیروز `['admin' => 'فقط مدیر', 'user' => 'فقط کاربر']` بود. با
 * آمدنِ «پشتیبان» و «همکار»، صافی **بی‌صدا** عقب می‌ماند: مدیر نقش را
 * می‌داد ولی هیچ راهی برای پیدا کردنِ آن کاربرها نداشت، و خرابی‌اش
 * هیچ خطایی نمی‌داد — همان درسِ `ACTIVE_DAYS` که در سه جا سخت‌کد بود.
 *
 * ⚠ اجتماعِ آرایه‌ها در عبارتِ ثابت مجاز است، پس هنوز `const` می‌ماند
 *   و قاعده ۴۸ دست‌نخورده کار می‌کند.
 */
const USER_ROLE_FILTERS = ['' => 'همه‌ی نقش‌ها'] + Auth::ROLES;

/**
 * ⛔ یک جمله زیرِ هر دو منوی نقش — و این تزئین نیست.
 *
 * نامِ نقش به‌تنهایی نمی‌گوید چه چیزی را باز می‌کند؛ مدیری که
 * «پشتیبان» را انتخاب می‌کند باید همان‌جا بداند که این آدم **فقط**
 * پنلِ تیکت را می‌بیند و نه کاربران و نه پرداخت‌ها. بدونِ آن، تنها
 * راهِ فهمیدنش امتحان کردن روی یک حسابِ واقعی است.
 */
const ROLE_HELP = 'مدیر: همه‌ی پنل. پشتیبان: فقط بخش پشتیبانی و تیکت‌ها. '
    . 'همکار: مثل کاربر عادی — فقط یک برچسب برای شناختنِ افرادِ فروشگاه.';
const USER_STATE_FILTERS = [
    ''         => 'همه‌ی وضعیت‌ها',
    'active'   => 'فعال',
    'inactive' => 'غیرفعال',
    'locked'   => 'قفلِ ورود',
];
const USER_SORTS = [
    'new'  => 'تازه‌ترین',
    'old'  => 'قدیمی‌ترین',
    'name' => 'نام (الفبا)',
    'user' => 'نام کاربری (الفبا)',
];

/**
 * ⛔ بیست‌وپنج، نه `PAGED_LIST_SIZE` (ده).
 *
 * آن عدد برای کارت‌های توریِ `admin/insights.php` انتخاب شد. اینجا یک
 * جدولِ فشرده است و با هزار کاربر، صفحه‌های ده‌تایی یعنی **صد صفحه** —
 * صفحه‌بندی‌ای که خودش به اندازه‌ی فهرستِ بی‌انتها آزاردهنده است.
 * اندازه‌گیری شد: ۲۵ ردیف روی دسکتاپ یک پرده و نیم است و روی موبایل
 * (که جدول کارتی می‌شود) هم تهش با دو کشیدن می‌آید.
 */
const USERS_PAGE_SIZE = 25;

$pdo = Database::getConnection();
$currentUserId = Auth::userId();

$error = '';
$reopenModal = '';

/**
 * ⛔ بعد از هر POST باید به **همین** نما برگردیم، نه به صفحه‌ی اول.
 *
 * با هزار کاربر، «غیرفعال‌سازی» روی صفحه‌ی ۱۲ کاربر را به صفحه‌ی ۱
 * برمی‌گرداند و او باید دوباره دوازده بار ورق بزند — همان خرابیِ
 * بی‌صدایی که `pagedUrl()` برای نبودنش نوشته شد، این بار در مسیرِ
 * ریدایرکت. فقط کلیدهای شناخته‌شده حمل می‌شوند تا `$_GET` دلخواه وارد
 * سرآیندِ `Location` نشود.
 */
$backTo = (static function (): string {
    $keep = [];
    foreach (['q', 'role', 'state', 'sort', 'pg_users', 'all'] as $k) {
        $v = $_GET[$k] ?? null;
        if (is_string($v) && $v !== '') { $keep[$k] = $v; }
    }
    return 'users.php' . ($keep ? '?' . http_build_query($keep) : '');
})();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::verifyOrFail(postParam('csrf_token'));
    $action = postParam('action');

    // ایمیل اختیاری است ولی اگر داده شد باید معتبر و یکتا باشد.
    // بدون ایمیل، کاربر نمی‌تواند رمزش را خودش بازیابی کند.
    $emailIn = trim(postParam('email'));
    $emailErr = '';
    $emailCol = usersHaveEmailColumn($pdo);
    if ($emailIn !== '' && (mb_strlen($emailIn) > 190 || !filter_var($emailIn, FILTER_VALIDATE_EMAIL))) {
        $emailErr = 'ایمیل معتبر نیست.';
    }
    // هنگام ساخت کاربر تازه، ایمیل اجباری است: کاربری که ایمیل ندارد
    // نمی‌تواند رمزش را خودش بازیابی کند و کارش به مدیر می‌افتد.
    // در ویرایش اجباری نیست، تا کاربران قدیمیِ بدون ایمیل قفل نشوند.
    if ($emailCol && $action === 'create' && $emailIn === '') {
        $emailErr = 'ایمیل الزامی است — بدون آن کاربر نمی‌تواند رمزش را بازیابی کند.';
    }

    /**
     * ایمیل را جدا از کوئری اصلی می‌نویسیم تا کوئری‌های موجود دست‌نخورده
     * بمانند و اگر ستون هنوز با migration اضافه نشده باشد چیزی نشکند.
     * برمی‌گرداند: '' یعنی موفق، وگرنه متن خطا.
     *
     * ⚠️ ورودی خالی یعنی «دست نزن»، نه «پاک کن».
     *
     * چرا: فرم ویرایش یکی است و برای همه‌ی ردیف‌ها استفاده می‌شود؛
     * مقدار فعلی ایمیل را جاوااسکریپت از data-email پر می‌کند. اگر آن
     * جاوااسکریپت به هر دلیلی اجرا نشود (نسخه‌ی کش‌شده، خطای اسکریپت،
     * مرورگر قدیمی)، فیلد خالی می‌ماند و ذخیره‌ی ساده‌ی همان فرم ایمیلِ
     * ثبت‌شده را پاک می‌کرد. کاربر می‌دید «ایمیل نمی‌مونه». حالا حذف
     * ایمیل از این مسیر ممکن نیست — ایمیل فقط با ایمیل تازه عوض می‌شود.
     */
    $saveEmail = function (int $uid) use ($pdo, $emailIn, $emailCol): string {
        if (!$emailCol) { return ''; }
        return saveUserEmail($pdo, $uid, $emailIn);
    };

    if ($action === 'create') {
        $fullName = postParam('full_name');
        $username = postParam('username');
        $password = $_POST['password'] ?? '';
        $passwordConfirm = $_POST['password_confirm'] ?? '';
        // ⛔ فهرستِ نقش از `Auth::ROLES` است، نه آرایه‌ی محلی — همان
        //    دلیلی که بالای `USER_ROLE_FILTERS` نوشته شده.
        $role = postParam('role', 'user');
        if (!array_key_exists($role, Auth::ROLES)) {
            $role = 'user';
        }

        // ⛔ اعتبارسنجی و ساخت از همان مسیری می‌روند که ثبت‌نامِ
        //    خودسرویس می‌رود (`includes/signup.php`). پیش از این هر دو
        //    نسخه‌ی خودشان را داشتند و قاعده‌ای مثل «کاربر تازه باید کیف
        //    پول داشته باشد» می‌توانست از یکی بیفتد.
        $error = validateNewUser($pdo, $fullName, $username, $emailIn, $password, $passwordConfirm);
        if ($error === '' && $emailErr !== '') { $error = $emailErr; }

        if ($error === '') {
            $res = createUserAccount($pdo, $fullName, $username, $emailIn, $password, $role);
            if (!$res['ok']) {
                $error = $res['error'] ?? 'خطایی در ساخت کاربر رخ داد.';
            } elseif (($res['error'] ?? '') !== '') {
                redirectWithMessage($backTo, 'error',
                    'کاربر ساخته شد، ولی ایمیل ثبت نشد: ' . $res['error']);
            } else {
                redirectWithMessage($backTo, 'success', 'کاربر جدید با موفقیت ساخته شد.');
            }
        }

        if ($error !== '') {
            $reopenModal = 'add';
        }
    } elseif ($action === 'update') {
        $targetId = (int)postParam('user_id');
        $fullName = postParam('full_name');
        $username = postParam('username');
        $role = postParam('role', 'user');
        $password = $_POST['password'] ?? '';
        $passwordConfirm = $_POST['password_confirm'] ?? '';
        if (!array_key_exists($role, Auth::ROLES)) {
            $role = 'user';
        }

        $targetStmt = $pdo->prepare('SELECT * FROM users WHERE id = :id');
        $targetStmt->execute(['id' => $targetId]);
        $targetUser = $targetStmt->fetch();

        if (!$targetUser) {
            $error = 'کاربر مورد نظر یافت نشد.';
        } elseif ($fullName === '' || $username === '') {
            $error = 'نام و نام کاربری الزامی است.';
        } elseif (mb_strlen($fullName) > 100) {
            $error = 'نام و نام خانوادگی نباید بیشتر از ۱۰۰ کاراکتر باشد.';
        // ⛔ قاعده‌ی نام کاربری از `usernameRuleError()` می‌آید، نه یک
        //    الگوی محلی — وگرنه ساخت و ویرایش و پروفایل سه قاعده‌ی
        //    متفاوت داشتند، که دقیقاً همان چیزی بود که کاربرِ نقطه‌دار را
        //    از پروفایلِ خودش بیرون می‌کرد.
        } elseif (($usernameErr = usernameRuleError($username)) !== '') {
            $error = $usernameErr;
        } elseif (($pe = passwordRuleError($password)) !== '') {
            $error = $pe;
        } elseif ($password !== '' && $password !== $passwordConfirm) {
            $error = 'رمز عبور و تکرار آن یکسان نیستند.';
        /*
         * ⛔ شرط `$role !== 'admin'` است نه `$role === 'user'`.
         *
         * تا دیروز فقط دو نقش وجود داشت و آن دو یکی بودند. حالا «مدیر →
         * پشتیبان» و «مدیر → همکار» هم **برداشتنِ مدیریت**اند و از کنارِ
         * شرطِ قدیمی رد می‌شدند: آخرین مدیرِ نصب می‌توانست خودش را
         * «همکار» کند و آن‌وقت **هیچ‌کس** به پنل مدیریت راه نداشت — نه
         * خطایی، نه هشداری، و تنها راهِ برگشت `deploy/user-admin.php`
         * روی SSH بود.
         */
        } elseif ($targetUser['role'] === 'admin' && $role !== 'admin' && (int)$targetUser['id'] === $currentUserId) {
            $error = 'نمی‌توانید نقش مدیریتی خودتان را تغییر دهید.';
        } elseif ($targetUser['role'] === 'admin' && $role !== 'admin' && countOtherActiveAdmins($pdo, $targetId) < 1) {
            $error = 'حداقل باید یک مدیر فعال در سیستم باقی بماند.';
        } else {
            $dupStmt = $pdo->prepare('SELECT id FROM users WHERE username = :username AND id != :id');
            $dupStmt->execute(['username' => $username, 'id' => $targetId]);
            if ($dupStmt->fetch()) {
                $error = 'این نام کاربری قبلاً استفاده شده است.';
            } else {
                try {
                    if ($password !== '') {
                        $hash = password_hash($password, PASSWORD_DEFAULT);
                        $stmt = $pdo->prepare('UPDATE users SET full_name = :full_name, username = :username, role = :role, password_hash = :password_hash WHERE id = :id');
                        $stmt->execute([
                            'full_name'     => $fullName,
                            'username'      => $username,
                            'role'          => $role,
                            'password_hash' => $hash,
                            'id'            => $targetId,
                        ]);
                        // مدیر که رمزِ کسی را عوض می‌کند، معمولاً چون آن
                        // حساب مشکلی دارد. رمزِ تازه به‌تنهایی توکنِ
                        // اپِ آن کاربر را باطل نمی‌کند.
                        revokeAllAccessFor($targetId);
                        Audit::log('auth.password_changed', 'user', $targetId, ['self' => false], null, $targetId);
                    } else {
                        $stmt = $pdo->prepare('UPDATE users SET full_name = :full_name, username = :username, role = :role WHERE id = :id');
                        $stmt->execute([
                            'full_name' => $fullName,
                            'username'  => $username,
                            'role'      => $role,
                            'id'        => $targetId,
                        ]);
                    }
                    // ⛔ فقط **کدام** فیلدها عوض شدند، نه مقدارشان (نام و ایمیل
                    //    محتوای شخصی‌اند)؛ نقش استثناست چون تصمیمِ امنیتی است.
                    $changed = [];
                    if ($fullName !== (string)$targetUser['full_name']) { $changed[] = 'full_name'; }
                    if ($username !== (string)$targetUser['username']) { $changed[] = 'username'; }
                    if ($role !== (string)$targetUser['role']) { $changed[] = 'role'; }
                    Audit::log('user.updated', 'user', $targetId, [
                        'fields' => $changed,
                        'role'   => $role !== (string)$targetUser['role'] ? [$targetUser['role'], $role] : null,
                    ], null, $targetId);
                    if ($emailErr === '') { $emailErr = $saveEmail($targetId); }
                    if ($emailErr !== '') {
                        redirectWithMessage($backTo, 'error',
                            'اطلاعات ذخیره شد، ولی ایمیل ثبت نشد: ' . $emailErr);
                    }
                    // ⛔ شماره از `saveUserPhone()` رد می‌شود، نه یک
                    //    `UPDATE` دستی: نرمال‌سازی و بررسیِ تکراری باید با
                    //    `SmsLogin::normalizePhone()` یکی بماند، وگرنه
                    //    شماره‌ای ذخیره می‌شود که ورودِ پیامکی پیدایش
                    //    نمی‌کند و خرابی **بی‌صداست**. خالی هم یعنی «دست
                    //    نزن» (همان قاعده‌ی ایمیل).
                    $phoneErr = saveUserPhone($pdo, $targetId, postParam('phone'));
                    if ($phoneErr !== '') {
                        redirectWithMessage($backTo, 'error',
                            'اطلاعات ذخیره شد، ولی شماره موبایل ثبت نشد: ' . $phoneErr);
                    }
                    redirectWithMessage($backTo, 'success', 'اطلاعات کاربر بروزرسانی شد.');
                } catch (PDOException $e) {
                    Log::error('admin.update_user_failed', $e);
                    $error = 'خطایی در بروزرسانی کاربر رخ داد.';
                }
            }
        }

        if ($error !== '') {
            $reopenModal = 'edit';
        }
    } elseif ($action === 'toggle_status') {
        $targetId = (int)postParam('user_id');

        if ($targetId === $currentUserId) {
            redirectWithMessage($backTo, 'error', 'نمی‌توانید وضعیت حساب خودتان را تغییر دهید.');
        }

        $targetStmt = $pdo->prepare('SELECT * FROM users WHERE id = :id');
        $targetStmt->execute(['id' => $targetId]);
        $targetUser = $targetStmt->fetch();

        if (!$targetUser) {
            redirectWithMessage($backTo, 'error', 'کاربر مورد نظر یافت نشد.');
        }

        $newStatus = (int)$targetUser['is_active'] === 1 ? 0 : 1;

        if ($targetUser['role'] === 'admin' && $newStatus === 0 && countOtherActiveAdmins($pdo, $targetId) < 1) {
            redirectWithMessage($backTo, 'error', 'حداقل باید یک مدیر فعال در سیستم باقی بماند.');
        }

        $stmt = $pdo->prepare('UPDATE users SET is_active = :status WHERE id = :id');
        $stmt->execute(['status' => $newStatus, 'id' => $targetId]);

        // ⛔ غیرفعال کردن بدونِ این خط فقط جلوی **ورودِ بعدی** را می‌گرفت.
        //    مرورگر و اپی که همان لحظه وارد بودند تا هر وقت خودشان خارج
        //    می‌شدند کار می‌کردند و پیامِ «کاربر غیرفعال شد» دروغ بود.
        //    نشستِ وبِ زنده هم با همین مهر تا یک دقیقه‌ی بعد خالی می‌شود
        //    (`Auth::isLoggedIn()`).
        if ($newStatus === 0) {
            revokeAllAccessFor($targetId);
        }
        Audit::log($newStatus === 1 ? 'user.activated' : 'user.deactivated', 'user', $targetId, [], null, $targetId);

        redirectWithMessage($backTo, 'success', $newStatus === 1
            ? 'کاربر فعال شد.'
            : 'کاربر غیرفعال شد و از همه‌ی دستگاه‌ها خارج می‌شود.');
    } elseif ($action === 'revoke_access') {
        // «خروج از همه‌ی دستگاه‌ها» — برای وقتی که کاربر می‌گوید گوشی‌اش
        // را گم کرده یا حسابش دستِ کسی است، ولی حساب باید باز بماند.
        // همان کاری که تغییرِ رمز می‌کند، بی‌آنکه رمزش عوض شود.
        $targetId = (int)postParam('user_id');

        if ($targetId === $currentUserId) {
            redirectWithMessage($backTo, 'error', 'برای خروج از دستگاه‌های خودتان از پروفایل استفاده کنید.');
        }

        $targetStmt = $pdo->prepare('SELECT id FROM users WHERE id = :id');
        $targetStmt->execute(['id' => $targetId]);
        if (!$targetStmt->fetch()) {
            redirectWithMessage($backTo, 'error', 'کاربر مورد نظر یافت نشد.');
        }

        revokeAllAccessFor($targetId);
        redirectWithMessage($backTo, 'success', 'کاربر از همه‌ی دستگاه‌ها و اپ‌ها خارج می‌شود؛ حسابش باز است و با رمزِ خودش دوباره وارد می‌شود.');
    } elseif ($action === 'unlock_login') {
        // ⛔ سدِ حدسِ رمز بین کاربرِ واقعی و مهاجم فرق نمی‌گذارد، پس
        //    کاربری که رمزش را چند بار غلط زده تا پایانِ پنجره بیرون
        //    می‌ماند و **هیچ کاری هم از دستش برنمی‌آید**. تا امروز راهِ
        //    باز کردنش فقط `deploy/user-admin.php --unlock` از راهِ SSH
        //    بود — یعنی مالکِ نصبی که SSH ندارد اصلاً راهی نداشت.
        $targetName = trim(postParam('username'));
        if ($targetName === '') {
            redirectWithMessage($backTo, 'error', 'کاربر مشخص نشد.');
        }
        LoginThrottle::clear($targetName);
        // ⚠ شناسه از نام پیدا می‌شود فقط برای ستونِ هدف؛ خودِ نام نوشته نمی‌شود.
        $tgt = $pdo->prepare('SELECT id FROM users WHERE username = :u');
        $tgt->execute(['u' => $targetName]);
        $tgtId = (int)$tgt->fetchColumn() ?: null;
        Audit::log('user.login_unlocked', 'user', $tgtId, [], null, $tgtId);
        redirectWithMessage($backTo, 'success',
            'قفلِ ورودِ «' . $targetName . '» باز شد. حالا می‌تواند دوباره رمزش را وارد کند.');
    } elseif ($action === 'delete') {
        $targetId = (int)postParam('user_id');

        if ($targetId === $currentUserId) {
            redirectWithMessage($backTo, 'error', 'نمی‌توانید حساب خودتان را حذف کنید.');
        }

        $targetStmt = $pdo->prepare('SELECT * FROM users WHERE id = :id');
        $targetStmt->execute(['id' => $targetId]);
        $targetUser = $targetStmt->fetch();

        if (!$targetUser) {
            redirectWithMessage($backTo, 'error', 'کاربر مورد نظر یافت نشد.');
        }

        if ($targetUser['role'] === 'admin' && countOtherActiveAdmins($pdo, $targetId) < 1) {
            redirectWithMessage($backTo, 'error', 'حداقل باید یک مدیر فعال در سیستم باقی بماند.');
        }

        // ⛔ از `deleteUserAccount()` رد می‌شود، نه یک `DELETE FROM users`
        //    خام. نسخه‌ی قبلی روی اولین کلیدِ خارجی می‌خورد و پیام می‌داد
        //    «این کاربر تراکنش دارد و قابل حذف نیست» — یعنی **هر کاربری
        //    که یک بار از اپ استفاده کرده باشد اصلاً حذف‌شدنی نبود**، و
        //    این دقیقاً همان کاربری است که می‌خواهد پاک شود. تابعِ مشترک
        //    ترتیبِ جدول‌ها را خودش پیدا می‌کند، همه را در یک تراکنش
        //    می‌برد، و پیش از commit می‌شمارد که از هیچ جدولی بیش از سهمِ
        //    همین کاربر حذف نشده باشد.
        $res = deleteUserAccount($targetId);
        if ($res['ok']) {
            redirectWithMessage($backTo, 'success',
                'کاربر و همه‌ی داده‌هایش حذف شد.');
        }
        redirectWithMessage($backTo, 'error',
            'حذف انجام نشد: ' . ($res['reason'] ?? 'خطای نامشخص') . ' — می‌توانید کاربر را غیرفعال کنید.');
    }
}

// ستون ایمیل با migration_password_reset آمده؛ اگر هنوز اجرا نشده باشد
// صفحه باید بدون خطا کار کند.
$hasEmailColumn = usersHaveEmailColumn($pdo);
// ستون شماره با `migration_sms_login` آمده؛ نصبِ عقب‌مانده نباید بشکند.
$hasPhoneColumn = tableHasColumn('users', 'phone');

$cols = 'id, full_name, username, role, is_active, created_at';
if ($hasEmailColumn) { $cols .= ', email'; }
if ($hasPhoneColumn) { $cols .= ', phone'; }

// ⚠ یک کوئری برای کلِ فهرست، نه یکی به‌ازای هر ردیف. جدولش فقط
//   تلاش‌های ۱۵ دقیقه‌ی اخیر را دارد، پس با هزار کاربر هم کوچک است.
$lockCounts = LoginThrottle::failureCounts();

// ---------- صافی‌ها ----------
$q     = trim((string)getParam('q'));
$fRole = (string)getParam('role');
$fState = (string)getParam('state');
$sort  = (string)getParam('sort', 'new');
if (!isset(USER_ROLE_FILTERS[$fRole]))   { $fRole = ''; }
if (!isset(USER_STATE_FILTERS[$fState])) { $fState = ''; }
if (!isset(USER_SORTS[$sort]))           { $sort = 'new'; }

/**
 * ⛔ قطعه‌های ثابت در آرایه، مقدارها همیشه bind — قاعده ۲ی خودِ پروژه.
 *
 * ⚠ و `%`/`_` فرار داده می‌شوند (`ESCAPE '!'`)، همان کاری که
 *   `includes/tx_query.php` می‌کند: بدونِ آن تایپِ `%` کلِ فهرست را
 *   برمی‌گرداند و مدیر نمی‌فهمد چرا. کاراکترِ فرار عمداً `!` است نه
 *   بک‌اسلش، که در رشته‌ی SQL یک لایه‌ی تفسیرِ دیگر دارد.
 */
$where  = [];
$params = [];

if ($q !== '') {
    // شماره‌ی خالص می‌تواند شناسه باشد یا شماره‌ی موبایل — هر دو سنجیده
    // می‌شوند، چون مدیر هر دو را از گزارشِ کاربر کپی می‌کند.
    $like = '%' . str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $q) . '%';
    $or   = ['u.full_name LIKE :q1 ESCAPE \'!\'', 'u.username LIKE :q2 ESCAPE \'!\''];
    $params['q1'] = $like;
    $params['q2'] = $like;
    if ($hasEmailColumn) { $or[] = 'u.email LIKE :q3 ESCAPE \'!\''; $params['q3'] = $like; }
    if ($hasPhoneColumn) { $or[] = 'u.phone LIKE :q4 ESCAPE \'!\''; $params['q4'] = $like; }
    $digits = toLatinDigits($q);
    if (ctype_digit($digits)) { $or[] = 'u.id = :qid'; $params['qid'] = (int)$digits; }
    $where[] = '(' . implode(' OR ', $or) . ')';
}

if ($fRole !== '') { $where[] = 'u.role = :role'; $params['role'] = $fRole; }
if ($fState === 'active')   { $where[] = 'u.is_active = 1'; }
if ($fState === 'inactive') { $where[] = 'u.is_active = 0'; }
if ($fState === 'locked') {
    // ⚠ «قفل» در SQL نیست، در `login_attempts` است. فهرستِ نام‌ها از همان
    //   یک کوئریِ بالا می‌آید، پس کوئریِ تازه‌ای اضافه نمی‌کند.
    $locked = [];
    foreach ($lockCounts as $name => $c) {
        if ($c >= LoginThrottle::MAX_PER_USER) { $locked[] = $name; }
    }
    if (!$locked) {
        $where[] = '1 = 0';   // ⚠ `IN ()` نحوِ نامعتبر است
    } else {
        $in = [];
        foreach (array_values($locked) as $i => $name) {
            $in[] = ':lk' . $i;
            $params['lk' . $i] = $name;
        }
        $where[] = 'u.username IN (' . implode(', ', $in) . ')';
    }
}

$whereSql = $where ? ' WHERE ' . implode(' AND ', $where) : '';

/**
 * ⛔ ترتیبِ پیش‌فرض «تازه‌ترین» شد، نه «قدیمی‌ترین».
 *
 * با سه کاربر فرقی نداشت. با هزار کاربر، صفحه‌ی اولِ «قدیمی‌ترین» یعنی
 * حساب‌هایی که سال‌هاست دست‌نخورده‌اند، در حالی که کارِ پشتیبانی تقریباً
 * همیشه با تازه‌ترین‌هاست. هر چهار ترتیب در منو هستند، پس چیزی از دست
 * نرفته — فقط پیش‌فرض همان کاری را می‌کند که لازم است.
 *
 * ⚠ نامِ ستون از `USER_SORTS` می‌آید نه از ورودی؛ درجِ مستقیمش امن است.
 */
$orderSql = [
    'new'  => 'u.created_at DESC, u.id DESC',
    'old'  => 'u.created_at ASC, u.id ASC',
    'name' => 'u.full_name ASC, u.id ASC',
    'user' => 'u.username ASC, u.id ASC',
][$sort];

// ---------- شمارش و صفحه‌بندی ----------
// ⚠ یک کوئریِ تجمیعی برای کارتِ خلاصه؛ و **فقط وقتی صافی هست** یک
//   `COUNT` دوم برای صفحه‌بندی. در نمای بدونِ صافی، جمعِ همان کارت
//   خودش مخرجِ صفحه‌بندی است — پس نمای معمول یک کوئری بیشتر نمی‌گیرد.
$sum = $pdo->query(
    'SELECT COUNT(*) AS total, SUM(is_active = 1) AS active, SUM(role = \'admin\') AS admins FROM users'
)->fetch();
$totalUsers  = (int)($sum['total'] ?? 0);
$activeUsers = (int)($sum['active'] ?? 0);
$adminUsers  = (int)($sum['admins'] ?? 0);
$lockedUsers = count(array_filter($lockCounts, fn ($c) => $c >= LoginThrottle::MAX_PER_USER));

if ($whereSql === '') {
    $matched = $totalUsers;
} else {
    $cs = $pdo->prepare('SELECT COUNT(*) FROM users u' . $whereSql);
    $cs->execute($params);
    $matched = (int)$cs->fetchColumn();
}

$pg = pagedWindow($matched, 'users', USERS_PAGE_SIZE);

/**
 * ⛔ `LIMIT` در SQL، نه `array_slice` روی هزار ردیف.
 *
 * `admin/insights.php` عمداً در PHP می‌برد، چون سه فهرستش از **یک**
 * آرایه‌ی از قبل خوانده‌شده می‌آیند. اینجا برعکس است: این فهرست تنها
 * مصرف‌کننده‌ی کوئری است، پس کشیدنِ هزار ردیف برای نشان دادنِ بیست‌وپنج‌تا
 * دقیقاً همان «خزشِ بی‌صدا»یی است که `test_query_budget` برای گرفتنش
 * نوشته شد. توضیحِ کاملِ مرزِ این دو بالای `pagedWindow()` است.
 *
 * ⚠ «همه در یک فهرست» سرِ جایش است و عمداً بی‌سقف: خواسته‌ی صریحِ مالکِ
 *   نصب است و یک انتخابِ آگاهانه، چون عددِ کل همیشه روی نوار نوشته شده.
 */
$limitSql = $pg['all'] ? '' : ' LIMIT ' . (int)$pg['size'] . ' OFFSET ' . (int)$pg['offset'];
// ⚠ نامِ ستون‌ها از ثابت‌های خودِ کد می‌آید نه از ورودی، پس درجِ مستقیمش امن است.
$ls = $pdo->prepare("SELECT {$cols} FROM users u{$whereSql} ORDER BY {$orderSql}{$limitSql}");
$ls->execute($params);
$users = $ls->fetchAll();

$hasFilter = ($q !== '' || $fRole !== '' || $fState !== '');
$pageTitle = 'مدیریت کاربران';
/* جدولِ هفت‌ستونه با یک ستونِ دکمه — در ۷۲۰ پیکسل له می‌شود. */
$pageWide  = true;
include __DIR__ . '/../includes/header.php';
?>

<?php include __DIR__ . '/_nav.php'; ?>

<div class="card">
    <div class="card-header-row">
        <h2 class="card-title">کاربران سیستم</h2>
        <button type="button" class="btn btn-primary btn-sm" data-modal-open="addUserModal">+ کاربر جدید</button>
    </div>

    <?php if ($error && $reopenModal !== 'add' && $reopenModal !== 'edit'): ?>
        <div class="alert alert-error"><?= h($error) ?></div>
    <?php endif; ?>

    <?php /* ⚠ خلاصه‌ی چهارتایی: با هزار کاربر، «چند نفر فعال‌اند» و «چند
             نفر قفل‌اند» سؤال‌هایی هستند که پیش از باز کردنِ فهرست
             پرسیده می‌شوند. هر چیپ خودش صافیِ همان نما هم هست، پس عدد
             به اقدام می‌رسد نه اینکه فقط خوانده شود. */ ?>
    <div class="user-stats">
        <a class="user-stat <?= !$hasFilter ? 'is-current' : '' ?>"
           href="<?= pagedUrl(['q' => null, 'role' => null, 'state' => null, 'pg_users' => null, 'all' => null]) ?>">
            <span class="user-stat-num ltr-num"><?= toPersianDigits($totalUsers) ?></span>
            <span class="user-stat-label">کاربر</span>
        </a>
        <a class="user-stat <?= $fState === 'active' ? 'is-current' : '' ?>"
           href="<?= pagedUrl(['state' => 'active', 'pg_users' => null, 'all' => null]) ?>">
            <span class="user-stat-num ltr-num"><?= toPersianDigits($activeUsers) ?></span>
            <span class="user-stat-label">فعال</span>
        </a>
        <a class="user-stat <?= $fRole === 'admin' ? 'is-current' : '' ?>"
           href="<?= pagedUrl(['role' => 'admin', 'pg_users' => null, 'all' => null]) ?>">
            <span class="user-stat-num ltr-num"><?= toPersianDigits($adminUsers) ?></span>
            <span class="user-stat-label">مدیر</span>
        </a>
        <?php /* ⚠ چیپِ قفل با صفر هم رندر می‌شود — برخلافِ نشانِ خطاها —
                 چون اینجا «صفر» خودش خبرِ خوبی است و جایش در یک ردیفِ
                 چهارتایی ثابت می‌ماند؛ نه یک نشانِ هشدار که با ماندنش
                 آدم را به نادیده گرفتن عادت بدهد. */ ?>
        <a class="user-stat <?= $fState === 'locked' ? 'is-current' : '' ?> <?= $lockedUsers > 0 ? 'is-warn' : '' ?>"
           href="<?= pagedUrl(['state' => 'locked', 'pg_users' => null, 'all' => null]) ?>">
            <span class="user-stat-num ltr-num"><?= toPersianDigits($lockedUsers) ?></span>
            <span class="user-stat-label">قفلِ ورود</span>
        </a>
    </div>

    <?php /* ⛔ فرمِ `GET` است، نه `fetch`: با دکمه‌ی بازگشتِ مرورگر و با
             کپیِ آدرس کار می‌کند، و اگر `app.js` نرسد هم سالم می‌ماند
             (همان درسِ «همه در یک فهرست» که لینک است نه جاوااسکریپت). */ ?>
    <form method="GET" class="user-filters">
        <input type="search" name="q" value="<?= h($q) ?>" class="user-filter-q"
               placeholder="نام، نام کاربری، ایمیل، شماره یا شناسه"
               autocapitalize="none" autocorrect="off" spellcheck="false">
        <select name="role">
            <?php foreach (USER_ROLE_FILTERS as $k => $label): ?>
                <option value="<?= h($k) ?>" <?= $fRole === $k ? 'selected' : '' ?>><?= h($label) ?></option>
            <?php endforeach; ?>
        </select>
        <select name="state">
            <?php foreach (USER_STATE_FILTERS as $k => $label): ?>
                <option value="<?= h($k) ?>" <?= $fState === $k ? 'selected' : '' ?>><?= h($label) ?></option>
            <?php endforeach; ?>
        </select>
        <select name="sort">
            <?php foreach (USER_SORTS as $k => $label): ?>
                <option value="<?= h($k) ?>" <?= $sort === $k ? 'selected' : '' ?>><?= h($label) ?></option>
            <?php endforeach; ?>
        </select>
        <button type="submit" class="btn btn-sm">جست‌وجو</button>
        <?php if ($hasFilter): ?>
            <a class="btn btn-secondary btn-sm" href="<?= h(basename($_SERVER['SCRIPT_NAME'])) ?>">پاک کردن</a>
        <?php endif; ?>
    </form>

    <?php if ($hasFilter): ?>
        <p class="hint user-filter-note">
            <?= toPersianDigits($matched) ?> کاربر از <?= toPersianDigits($totalUsers) ?> با این صافی می‌خواند.
        </p>
    <?php endif; ?>

    <div class="table-wrapper">
        <table class="data-table users-table">
            <thead>
                <tr>
                    <th>نام و نام خانوادگی</th>
                    <th>نام کاربری</th>
<?php if ($hasEmailColumn): ?>                    <th>ایمیل</th>
<?php endif; ?>
                    <th>نقش</th>
                    <th>وضعیت</th>
                    <th>تاریخ عضویت</th>
                    <th class="actions-cell">عملیات</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($users)): ?>
                    <tr><td colspan="<?= $hasEmailColumn ? 7 : 6 ?>" class="empty-row">
                        <?= $hasFilter ? 'با این صافی کاربری پیدا نشد.' : 'کاربری یافت نشد.' ?>
                    </td></tr>
                <?php else: ?>
                    <?php foreach ($users as $u): ?>
                        <tr>
                            <?php $fails = $lockCounts[LoginThrottle::key($u['username'])] ?? 0; ?>
                            <td data-label="نام"><?= h($u['full_name']) ?></td>
                            <td data-label="نام کاربری">
                                <?= h($u['username']) ?>
                                <?php if ($fails > 0): ?>
                                    <?php /* ⚠ نشان فقط وقتی می‌آید که واقعاً تلاشِ ناموفق
                                             در پنجره باشد؛ وگرنه هر ردیف یک برچسبِ
                                             بی‌معنا می‌گرفت. */ ?>
                                    <span class="lock-chip <?= $fails >= LoginThrottle::MAX_PER_USER ? 'is-locked' : '' ?>"
                                          title="<?= $fails >= LoginThrottle::MAX_PER_USER
                                              ? 'ورود این کاربر قفل است' : 'تلاش ناموفق اخیر' ?>">
                                        <?= toPersianDigits($fails) ?> تلاش ناموفق
                                    </span>
                                <?php endif; ?>
                            </td>
<?php if ($hasEmailColumn): ?>
                            <td data-label="ایمیل"><?= $u['email'] ? h($u['email']) : '<span style="color:var(--muted)">—</span>' ?></td>
<?php endif; ?>
                            <?php /* ⛔ برچسب از `Auth::roleLabel()` — با سه‌گانه‌ی
                                     قبلی، «پشتیبان» و «همکار» هر دو «کاربر»
                                     خوانده می‌شدند و مدیر نمی‌فهمید نقشی که
                                     خودش داده کجا نشسته. */ ?>
                            <td data-label="نقش"><?= h(Auth::roleLabel((string)$u['role'])) ?></td>
                            <td data-label="وضعیت">
                                <span class="status-badge <?= (int)$u['is_active'] === 1 ? 'status-active' : 'status-inactive' ?>">
                                    <?= (int)$u['is_active'] === 1 ? 'فعال' : 'غیرفعال' ?>
                                </span>
                            </td>
                            <td data-label="تاریخ عضویت"><?= toJalali(substr($u['created_at'], 0, 10)) ?></td>
                            <td data-label="عملیات" class="actions-cell">
                                <div class="table-actions">
                                    <button type="button" class="btn btn-secondary btn-sm js-edit-user"
                                        data-id="<?= (int)$u['id'] ?>"
                                        data-full-name="<?= h($u['full_name']) ?>"
                                        data-username="<?= h($u['username']) ?>"
                                        data-email="<?= h($u['email'] ?? '') ?>"
                                        data-phone="<?= h($u['phone'] ?? '') ?>"
                                        data-role="<?= h($u['role']) ?>">ویرایش</button>

                                    <?php if ($fails > 0): ?>
                                        <form method="POST">
                                            <?= Csrf::field() ?>
                                            <input type="hidden" name="action" value="unlock_login">
                                            <input type="hidden" name="username" value="<?= h($u['username']) ?>">
                                            <button type="submit" class="btn btn-secondary btn-sm"
                                                    title="شمارنده‌ی تلاش ناموفق این نام کاربری پاک می‌شود">باز کردن قفل</button>
                                        </form>
                                    <?php endif; ?>

                                    <?php if ((int)$u['id'] !== $currentUserId): ?>
                                        <form method="POST">
                                            <?= Csrf::field() ?>
                                            <input type="hidden" name="action" value="toggle_status">
                                            <input type="hidden" name="user_id" value="<?= (int)$u['id'] ?>">
                                            <button type="submit" class="btn btn-secondary btn-sm">
                                                <?= (int)$u['is_active'] === 1 ? 'غیرفعال‌سازی' : 'فعال‌سازی' ?>
                                            </button>
                                        </form>
                                        <?php if ((int)$u['is_active'] === 1): ?>
                                        <form method="POST" onsubmit="return confirm('این کاربر از همه‌ی مرورگرها و اپ‌ها خارج می‌شود و باید دوباره وارد شود. ادامه؟');">
                                            <?= Csrf::field() ?>
                                            <input type="hidden" name="action" value="revoke_access">
                                            <input type="hidden" name="user_id" value="<?= (int)$u['id'] ?>">
                                            <button type="submit" class="btn btn-secondary btn-sm" title="دستگاه‌های مورد اعتماد، توکن‌های اپ و نشست‌های باز باطل می‌شوند؛ حساب باز می‌ماند">خروج از دستگاه‌ها</button>
                                        </form>
                                        <?php endif; ?>
                                        <?php /* ⚠ اینجا عمداً `confirm()` مانده و «لغو» نشده:
                                                 حذفِ کاربر داده‌ی هر جدولی را می‌برد و
                                                 `Undo` فقط یک ردیف و فرزندانِ CASCADE اش را
                                                 عکس می‌گیرد — همان دلیلی که حذفِ حساب و
                                                 معامله هم `confirm()` نگه داشتند. */ ?>
                                        <form method="POST" onsubmit="return confirm('کاربر و همه‌ی داده‌هایش (تراکنش، حساب، چک، …) برای همیشه حذف می‌شود. این کار برگشت ندارد. ادامه؟');">
                                            <?= Csrf::field() ?>
                                            <input type="hidden" name="action" value="delete">
                                            <input type="hidden" name="user_id" value="<?= (int)$u['id'] ?>">
                                            <button type="submit" class="delete-btn">حذف</button>
                                        </form>
                                    <?php else: ?>
                                        <span class="self-note">(حساب شما)</span>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <?php pagedNav($pg); ?>
</div>

<!-- مودال افزودن کاربر -->
<div class="modal-overlay <?= $reopenModal === 'add' ? 'show' : '' ?>" id="addUserModal">
    <div class="modal-box">
        <div class="modal-header">
            <h3>افزودن کاربر جدید</h3>
            <button type="button" class="modal-close" data-modal-close>&times;</button>
        </div>
        <?php if ($error && $reopenModal === 'add'): ?>
            <div class="alert alert-error"><?= h($error) ?></div>
        <?php endif; ?>
        <form method="POST" autocomplete="off">
            <?= Csrf::field() ?>
            <input type="hidden" name="action" value="create">
            <div class="form-group">
                <label>نام و نام خانوادگی</label>
                <input type="text" name="full_name" required value="<?= $reopenModal === 'add' ? h(postParam('full_name')) : '' ?>">
            </div>
            <div class="form-group">
                <label>نام کاربری</label>
                <input type="text" name="username" required placeholder="فقط حروف انگلیسی و عدد" value="<?= $reopenModal === 'add' ? h(postParam('username')) : '' ?>">
            </div>
<?php if ($hasEmailColumn): ?>
            <div class="form-group">
                <label>ایمیل</label>
                <input type="email" name="email" maxlength="190" required
                       autocapitalize="none" autocorrect="off" spellcheck="false"
                       placeholder="مثلاً: user@gmail.com"
                       value="<?= $reopenModal === 'add' ? h(postParam('email')) : '' ?>">
                <p class="hint">کاربر با همین ایمیل هم می‌تواند وارد شود و رمزش را بازیابی کند.</p>
            </div>
<?php endif; ?>
            <div class="form-group">
                <label>نقش</label>
                <?php
                /* ⛔ گزینه‌ها از `Auth::ROLES` — و انتخابِ پیش‌فرض
                   **صریح** است، نه «اولین گزینه». `Auth::ROLES` با
                   `admin` شروع می‌شود، پس با تکیه بر ترتیب، هر کاربری
                   که مدیر نقش را دست نمی‌زد **مدیر** ساخته می‌شد —
                   خرابیِ بی‌صدا و از بدترین نوعش. */
                $roleSel = $reopenModal === 'add' ? (string)postParam('role', 'user') : 'user';
                if (!array_key_exists($roleSel, Auth::ROLES)) { $roleSel = 'user'; }
                ?>
                <select name="role">
                    <?php foreach (Auth::ROLES as $rk => $rlabel): ?>
                        <option value="<?= h($rk) ?>" <?= $roleSel === $rk ? 'selected' : '' ?>><?= h($rlabel) ?></option>
                    <?php endforeach; ?>
                </select>
                <p class="hint"><?= h(ROLE_HELP) ?></p>
            </div>
            <div class="form-group">
                <label>رمز عبور</label>
                <input type="password" name="password" required placeholder="<?= h(passwordHint()) ?>">
            </div>
            <div class="form-group">
                <label>تکرار رمز عبور</label>
                <input type="password" name="password_confirm" required>
            </div>
            <div class="modal-actions">
                <button type="button" class="btn btn-secondary" data-modal-close>انصراف</button>
                <button type="submit" class="btn btn-primary">ذخیره</button>
            </div>
        </form>
    </div>
</div>

<!-- مودال ویرایش کاربر -->
<div class="modal-overlay <?= $reopenModal === 'edit' ? 'show' : '' ?>" id="editUserModal">
    <div class="modal-box">
        <div class="modal-header">
            <h3>ویرایش کاربر</h3>
            <button type="button" class="modal-close" data-modal-close>&times;</button>
        </div>
        <?php if ($error && $reopenModal === 'edit'): ?>
            <div class="alert alert-error"><?= h($error) ?></div>
        <?php endif; ?>
        <form method="POST" id="editUserForm" autocomplete="off">
            <?= Csrf::field() ?>
            <input type="hidden" name="action" value="update">
            <input type="hidden" name="user_id" value="<?= $reopenModal === 'edit' ? h(postParam('user_id')) : '' ?>">
            <div class="form-group">
                <label>نام و نام خانوادگی</label>
                <input type="text" name="full_name" required value="<?= $reopenModal === 'edit' ? h(postParam('full_name')) : '' ?>">
            </div>
            <div class="form-group">
                <label>نام کاربری</label>
                <input type="text" name="username" required value="<?= $reopenModal === 'edit' ? h(postParam('username')) : '' ?>">
            </div>
<?php if ($hasEmailColumn): ?>
            <div class="form-group">
                <label>ایمیل</label>
                <input type="email" name="email" maxlength="190"
                       autocapitalize="none" autocorrect="off" spellcheck="false"
                       placeholder="برای بازیابی رمز عبور"
                       value="<?= $reopenModal === 'edit' ? h(postParam('email')) : '' ?>">
                <p class="hint">اگر خالی بماند، ایمیل فعلی کاربر دست‌نخورده می‌ماند.</p>
            </div>
<?php endif; ?>
<?php if ($hasPhoneColumn): ?>
            <?php /* ⛔ بدونِ این فیلد، تنها راهِ ثبتِ شماره برای یک کاربر
                     `deploy/user-admin.php --set-phone` از راهِ SSH بود.
                     همان بن‌بستِ مرغ و تخم‌مرغِ «ورود با کد پیامکی»:
                     روزِ اولی که پنل راه می‌افتد هیچ‌کس شماره ندارد، پس
                     هیچ‌کس نمی‌تواند با پیامک وارد شود — و مالکِ نصب
                     ممکن است خودش هم بیرون مانده باشد. */ ?>
            <div class="form-group">
                <label>شماره موبایل</label>
                <input type="text" name="phone" maxlength="20" inputmode="numeric"
                       dir="ltr" placeholder="۰۹۱۲۳۴۵۶۷۸۹">
                <p class="hint">
                    برای «ورود با کد پیامکی». اگر خالی بماند، شماره‌ی فعلی دست‌نخورده می‌ماند.
                </p>
            </div>
<?php endif; ?>
            <div class="form-group">
                <label>نقش</label>
                <?php
                /* ⚠ مقدارِ واقعی را جاوااسکریپت از `data-role` همان ردیف
                   می‌گذارد؛ این فقط حالتِ «فرم با خطا دوباره باز شد»
                   است. باز هم انتخابِ پیش‌فرض صریح است، نه ترتیبِ
                   گزینه‌ها. */
                $roleSelEdit = $reopenModal === 'edit' ? (string)postParam('role', 'user') : 'user';
                if (!array_key_exists($roleSelEdit, Auth::ROLES)) { $roleSelEdit = 'user'; }
                ?>
                <select name="role">
                    <?php foreach (Auth::ROLES as $rk => $rlabel): ?>
                        <option value="<?= h($rk) ?>" <?= $roleSelEdit === $rk ? 'selected' : '' ?>><?= h($rlabel) ?></option>
                    <?php endforeach; ?>
                </select>
                <p class="hint"><?= h(ROLE_HELP) ?></p>
            </div>
            <div class="form-group">
                <label>رمز عبور جدید (اختیاری)</label>
                <input type="password" name="password" placeholder="خالی بگذارید تا تغییر نکند">
            </div>
            <div class="form-group">
                <label>تکرار رمز عبور جدید</label>
                <input type="password" name="password_confirm">
            </div>
            <div class="modal-actions">
                <button type="button" class="btn btn-secondary" data-modal-close>انصراف</button>
                <button type="submit" class="btn btn-primary">ذخیره تغییرات</button>
            </div>
        </form>
    </div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
