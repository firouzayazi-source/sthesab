<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/signup.php';
require_once __DIR__ . '/../includes/login_throttle.php';
require_once __DIR__ . '/../includes/user_data.php';

Auth::initSession();
Auth::requireAdmin();

$pdo = Database::getConnection();
$currentUserId = Auth::userId();

$error = '';
$reopenModal = '';

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
        $role = postParam('role', 'user');
        if (!in_array($role, ['admin', 'user'], true)) {
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
                redirectWithMessage('users.php', 'error',
                    'کاربر ساخته شد، ولی ایمیل ثبت نشد: ' . $res['error']);
            } else {
                redirectWithMessage('users.php', 'success', 'کاربر جدید با موفقیت ساخته شد.');
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
        if (!in_array($role, ['admin', 'user'], true)) {
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
        } elseif ($password !== '' && mb_strlen($password) < 6) {
            $error = 'رمز عبور جدید باید حداقل ۶ کاراکتر باشد.';
        } elseif ($password !== '' && $password !== $passwordConfirm) {
            $error = 'رمز عبور و تکرار آن یکسان نیستند.';
        } elseif ($targetUser['role'] === 'admin' && $role === 'user' && (int)$targetUser['id'] === $currentUserId) {
            $error = 'نمی‌توانید نقش مدیریتی خودتان را تغییر دهید.';
        } elseif ($targetUser['role'] === 'admin' && $role === 'user' && countOtherActiveAdmins($pdo, $targetId) < 1) {
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
                        redirectWithMessage('users.php', 'error',
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
                        redirectWithMessage('users.php', 'error',
                            'اطلاعات ذخیره شد، ولی شماره موبایل ثبت نشد: ' . $phoneErr);
                    }
                    redirectWithMessage('users.php', 'success', 'اطلاعات کاربر بروزرسانی شد.');
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
            redirectWithMessage('users.php', 'error', 'نمی‌توانید وضعیت حساب خودتان را تغییر دهید.');
        }

        $targetStmt = $pdo->prepare('SELECT * FROM users WHERE id = :id');
        $targetStmt->execute(['id' => $targetId]);
        $targetUser = $targetStmt->fetch();

        if (!$targetUser) {
            redirectWithMessage('users.php', 'error', 'کاربر مورد نظر یافت نشد.');
        }

        $newStatus = (int)$targetUser['is_active'] === 1 ? 0 : 1;

        if ($targetUser['role'] === 'admin' && $newStatus === 0 && countOtherActiveAdmins($pdo, $targetId) < 1) {
            redirectWithMessage('users.php', 'error', 'حداقل باید یک مدیر فعال در سیستم باقی بماند.');
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

        redirectWithMessage('users.php', 'success', $newStatus === 1
            ? 'کاربر فعال شد.'
            : 'کاربر غیرفعال شد و از همه‌ی دستگاه‌ها خارج می‌شود.');
    } elseif ($action === 'revoke_access') {
        // «خروج از همه‌ی دستگاه‌ها» — برای وقتی که کاربر می‌گوید گوشی‌اش
        // را گم کرده یا حسابش دستِ کسی است، ولی حساب باید باز بماند.
        // همان کاری که تغییرِ رمز می‌کند، بی‌آنکه رمزش عوض شود.
        $targetId = (int)postParam('user_id');

        if ($targetId === $currentUserId) {
            redirectWithMessage('users.php', 'error', 'برای خروج از دستگاه‌های خودتان از پروفایل استفاده کنید.');
        }

        $targetStmt = $pdo->prepare('SELECT id FROM users WHERE id = :id');
        $targetStmt->execute(['id' => $targetId]);
        if (!$targetStmt->fetch()) {
            redirectWithMessage('users.php', 'error', 'کاربر مورد نظر یافت نشد.');
        }

        revokeAllAccessFor($targetId);
        redirectWithMessage('users.php', 'success', 'کاربر از همه‌ی دستگاه‌ها و اپ‌ها خارج می‌شود؛ حسابش باز است و با رمزِ خودش دوباره وارد می‌شود.');
    } elseif ($action === 'unlock_login') {
        // ⛔ سدِ حدسِ رمز بین کاربرِ واقعی و مهاجم فرق نمی‌گذارد، پس
        //    کاربری که رمزش را چند بار غلط زده تا پایانِ پنجره بیرون
        //    می‌ماند و **هیچ کاری هم از دستش برنمی‌آید**. تا امروز راهِ
        //    باز کردنش فقط `deploy/user-admin.php --unlock` از راهِ SSH
        //    بود — یعنی مالکِ نصبی که SSH ندارد اصلاً راهی نداشت.
        $targetName = trim(postParam('username'));
        if ($targetName === '') {
            redirectWithMessage('users.php', 'error', 'کاربر مشخص نشد.');
        }
        LoginThrottle::clear($targetName);
        // ⚠ شناسه از نام پیدا می‌شود فقط برای ستونِ هدف؛ خودِ نام نوشته نمی‌شود.
        $tgt = $pdo->prepare('SELECT id FROM users WHERE username = :u');
        $tgt->execute(['u' => $targetName]);
        $tgtId = (int)$tgt->fetchColumn() ?: null;
        Audit::log('user.login_unlocked', 'user', $tgtId, [], null, $tgtId);
        redirectWithMessage('users.php', 'success',
            'قفلِ ورودِ «' . $targetName . '» باز شد. حالا می‌تواند دوباره رمزش را وارد کند.');
    } elseif ($action === 'delete') {
        $targetId = (int)postParam('user_id');

        if ($targetId === $currentUserId) {
            redirectWithMessage('users.php', 'error', 'نمی‌توانید حساب خودتان را حذف کنید.');
        }

        $targetStmt = $pdo->prepare('SELECT * FROM users WHERE id = :id');
        $targetStmt->execute(['id' => $targetId]);
        $targetUser = $targetStmt->fetch();

        if (!$targetUser) {
            redirectWithMessage('users.php', 'error', 'کاربر مورد نظر یافت نشد.');
        }

        if ($targetUser['role'] === 'admin' && countOtherActiveAdmins($pdo, $targetId) < 1) {
            redirectWithMessage('users.php', 'error', 'حداقل باید یک مدیر فعال در سیستم باقی بماند.');
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
            redirectWithMessage('users.php', 'success',
                'کاربر و همه‌ی داده‌هایش حذف شد.');
        }
        redirectWithMessage('users.php', 'error',
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
// ⚠ نامِ ستون‌ها از ثابت‌های خودِ کد می‌آید نه از ورودی، پس درجِ مستقیمش امن است.
$users = $pdo->query("SELECT {$cols} FROM users ORDER BY created_at ASC")->fetchAll();

// ⚠ یک کوئری برای کلِ فهرست، نه یکی به‌ازای هر ردیف.
$lockCounts = LoginThrottle::failureCounts();
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
                    <tr><td colspan="<?= $hasEmailColumn ? 7 : 6 ?>" class="empty-row">کاربری یافت نشد.</td></tr>
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
                            <td data-label="نقش"><?= $u['role'] === 'admin' ? 'مدیر' : 'کاربر' ?></td>
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
                <select name="role">
                    <option value="user" <?= ($reopenModal === 'add' && postParam('role') === 'user') ? 'selected' : '' ?>>کاربر</option>
                    <option value="admin" <?= ($reopenModal === 'add' && postParam('role') === 'admin') ? 'selected' : '' ?>>مدیر</option>
                </select>
            </div>
            <div class="form-group">
                <label>رمز عبور</label>
                <input type="password" name="password" required placeholder="حداقل ۶ کاراکتر">
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
                <select name="role">
                    <option value="user" <?= ($reopenModal === 'edit' && postParam('role') === 'user') ? 'selected' : '' ?>>کاربر</option>
                    <option value="admin" <?= ($reopenModal === 'edit' && postParam('role') === 'admin') ? 'selected' : '' ?>>مدیر</option>
                </select>
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
