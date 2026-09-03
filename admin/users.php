<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/signup.php';
require_once __DIR__ . '/../includes/plan.php';

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
        } elseif (mb_strlen($username) > 50) {
            $error = 'نام کاربری نباید بیشتر از ۵۰ کاراکتر باشد.';
        } elseif (!preg_match('/^[a-zA-Z0-9_.]+$/', $username)) {
            $error = 'نام کاربری فقط می‌تواند شامل حروف انگلیسی، عدد، نقطه و آندرلاین باشد.';
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
                    } else {
                        $stmt = $pdo->prepare('UPDATE users SET full_name = :full_name, username = :username, role = :role WHERE id = :id');
                        $stmt->execute([
                            'full_name' => $fullName,
                            'username'  => $username,
                            'role'      => $role,
                            'id'        => $targetId,
                        ]);
                    }
                    if ($emailErr === '') { $emailErr = $saveEmail($targetId); }
                    if ($emailErr !== '') {
                        redirectWithMessage('users.php', 'error',
                            'اطلاعات ذخیره شد، ولی ایمیل ثبت نشد: ' . $emailErr);
                    }
                    redirectWithMessage('users.php', 'success', 'اطلاعات کاربر بروزرسانی شد.');
                } catch (PDOException $e) {
                    error_log('Update User Error: ' . $e->getMessage());
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

        redirectWithMessage('users.php', 'success', $newStatus === 1 ? 'کاربر فعال شد.' : 'کاربر غیرفعال شد.');
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

        try {
            $stmt = $pdo->prepare('DELETE FROM users WHERE id = :id');
            $stmt->execute(['id' => $targetId]);
            redirectWithMessage('users.php', 'success', 'کاربر حذف شد.');
        } catch (PDOException $e) {
            redirectWithMessage('users.php', 'error', 'این کاربر دارای تراکنش ثبت‌شده است و قابل حذف نیست. می‌توانید آن را غیرفعال کنید.');
        }
    } elseif ($action === 'update_login_setting') {
        $requireFullLogin = postParam('require_full_login') === '1' ? '1' : '0';
        setSetting('require_full_login', $requireFullLogin);
        redirectWithMessage('users.php', 'success', 'تنظیمات ورود بروزرسانی شد.');
    } elseif ($action === 'update_plan_setting') {
        setSetting(PLAN_ENFORCE_SETTING, postParam('plan_enforced') === '1' ? '1' : '0');
        setSetting(PLAN_PRICE_SETTING, (string)max(0, (int)sanitizeAmount(postParam('plan_price'))));
        setSetting(PLAN_CARD_SETTING,  digitsOnly(postParam('plan_card'), 19));
        setSetting(PLAN_OWNER_SETTING, mb_substr(trim(postParam('plan_owner')), 0, 100));
        redirectWithMessage('users.php', 'success', 'تنظیمات اشتراک بروزرسانی شد.');
    } elseif ($action === 'review_payment') {
        $pid = (int)postParam('payment_id');
        if (postParam('decision') === 'approve') {
            $r = approvePayment($pid, $currentUserId);
            redirectWithMessage('users.php', $r['ok'] ? 'success' : 'error',
                $r['ok'] ? 'پرداخت تأیید شد. اشتراک تا ' . toJalali($r['until']) . ' تمدید شد.'
                         : ($r['error'] ?? 'تأیید انجام نشد.'));
        }
        $ok = rejectPayment($pid, $currentUserId, postParam('reason'));
        redirectWithMessage('users.php', $ok ? 'success' : 'error',
            $ok ? 'پرداخت رد شد.' : 'رد کردن انجام نشد.');
    } elseif ($action === 'update_signup_setting') {
        // ⛔ پیش‌فرض خاموش است و فقط از همین‌جا روشن می‌شود. یک نصبِ
        //    خصوصی نباید با به‌روزرسانی، بی‌خبر به روی اینترنت باز شود.
        setSetting(SIGNUP_SETTING, postParam('allow_signup') === '1' ? '1' : '0');

        $sup = trim(postParam('support_email'));
        if ($sup !== '' && !filter_var($sup, FILTER_VALIDATE_EMAIL)) {
            redirectWithMessage('users.php', 'error', 'ایمیل پشتیبانی معتبر نیست.');
        }
        setSetting('support_email', $sup);

        redirectWithMessage('users.php', 'success', 'تنظیمات ثبت‌نام بروزرسانی شد.');
    }
}

// ستون ایمیل با migration_password_reset آمده؛ اگر هنوز اجرا نشده باشد
// صفحه باید بدون خطا کار کند.
$hasEmailColumn = usersHaveEmailColumn($pdo);

$users = $pdo->query($hasEmailColumn
    ? 'SELECT id, full_name, username, email, role, is_active, created_at FROM users ORDER BY created_at ASC'
    : 'SELECT id, full_name, username, role, is_active, created_at FROM users ORDER BY created_at ASC'
)->fetchAll();
$requireFullLoginSetting = getSetting('require_full_login', '0') === '1';
$signupOn      = signupEnabled();
$supportEmail  = getSetting('support_email', '');
$planOn        = planEnforced();
$planPrice     = planMonthlyPrice();
$planCard      = getSetting(PLAN_CARD_SETTING, '');
$planOwner     = getSetting(PLAN_OWNER_SETTING, '');
$pending       = pendingPayments();

$pageTitle = 'مدیریت کاربران';
include __DIR__ . '/../includes/header.php';
?>

<div class="card">
    <h2 class="card-title">تنظیمات ورود</h2>
    <p style="font-size:13px; color:var(--color-gray-500); margin-bottom:12px;">
        به‌صورت پیش‌فرض، بعد از اولین ورود موفق روی هر دستگاه، نام کاربری همان‌جا ذخیره می‌شود و دفعات بعد فقط رمز عبور پرسیده می‌شود. اینکه چه زمانی دوباره رمز پرسیده شود را هر کاربر خودش در «حساب کاربری من» تعیین می‌کند (پیش‌فرض: بدون مهلت). با فعال‌کردن این گزینه، ذخیره‌ی نام کاربری خاموش می‌شود و همه همیشه باید نام کاربری و رمز عبور را کامل وارد کنند.
    </p>
    <form method="POST">
        <?= Csrf::field() ?>
        <input type="hidden" name="action" value="update_login_setting">
        <label class="switch" style="margin-bottom:14px;">
            <input type="checkbox" name="require_full_login" value="1" <?= $requireFullLoginSetting ? 'checked' : '' ?>>
            <span class="switch-track"><span class="switch-knob"></span></span>
            <span class="switch-text">الزام به وارد کردن نام کاربری هنگام ورود</span>
        </label>
        <button type="submit" class="btn btn-secondary btn-sm">ذخیره تنظیمات</button>
    </form>
</div>

<?php if (plansAvailable()): ?>
<div class="card">
    <h2 class="card-title">اشتراک و پرداخت</h2>
    <?php /* ⛔ «اعمال محدودیت» پیش‌فرض خاموش است. یک به‌روزرسانی نباید
             چیزی را از کاربرِ فعلی بگیرد؛ روشن کردنش تصمیمِ مالکِ نصب
             است، نه پیش‌فرضِ کد. */ ?>
    <form method="POST">
        <?= Csrf::field() ?>
        <input type="hidden" name="action" value="update_plan_setting">

        <div class="form-group">
            <label for="plan_price">قیمت هر ماه (تومان)</label>
            <input type="text" id="plan_price" name="plan_price" inputmode="numeric"
                   value="<?= $planPrice > 0 ? h(formatMoney($planPrice)) : '' ?>" placeholder="مثلاً ۵۰٬۰۰۰">
        </div>
        <div class="form-group">
            <label for="plan_card">شماره کارت برای واریز</label>
            <input type="text" id="plan_card" name="plan_card" inputmode="numeric" maxlength="19"
                   value="<?= h($planCard) ?>" placeholder="۱۶ رقم">
        </div>
        <div class="form-group">
            <label for="plan_owner">به نام</label>
            <input type="text" id="plan_owner" name="plan_owner" maxlength="100" value="<?= h($planOwner) ?>">
        </div>

        <label class="switch" style="margin-bottom:14px;">
            <input type="checkbox" name="plan_enforced" value="1" <?= $planOn ? 'checked' : '' ?>>
            <span class="switch-track"><span class="switch-knob"></span></span>
            <span class="switch-text">اعمال محدودیت طرح رایگان</span>
        </label>
        <p class="hint" style="margin-bottom:12px;">
            تا وقتی خاموش است، همه‌ی کاربران همه‌ی امکانات را دارند و
            اشتراک فقط حمایتی است. با روشن کردنش، بخش معاملات، API و
            یادآوری ایمیلی فقط برای مشترکان می‌ماند — هسته‌ی اپ (تراکنش،
            حساب، چک، طلب و بدهی) هرگز بسته نمی‌شود.
        </p>

        <button type="submit" class="btn btn-secondary btn-sm">ذخیره</button>
    </form>

    <?php if ($pending): ?>
        <h3 class="danger-title" style="color:var(--ink); margin-top:20px;">
            پرداخت‌های در انتظار (<?= toPersianDigits(count($pending)) ?>)
        </h3>
        <?php foreach ($pending as $p): ?>
            <div class="pay-row">
                <div>
                    <strong><?= h($p['full_name']) ?></strong>
                    <span class="hint">(<?= h($p['username']) ?>)</span><br>
                    <span class="hint">
                        <?= toPersianDigits($p['months']) ?> ماه ·
                        <?= formatMoney($p['amount']) ?> تومان ·
                        کد: <?= h($p['reference']) ?>
                    </span>
                </div>
                <div style="display:flex; gap:6px;">
                    <form method="POST" style="display:inline;">
                        <?= Csrf::field() ?>
                        <input type="hidden" name="action" value="review_payment">
                        <input type="hidden" name="payment_id" value="<?= (int)$p['id'] ?>">
                        <input type="hidden" name="decision" value="approve">
                        <button type="submit" class="btn btn-primary btn-sm">تأیید</button>
                    </form>
                    <form method="POST" style="display:inline;">
                        <?= Csrf::field() ?>
                        <input type="hidden" name="action" value="review_payment">
                        <input type="hidden" name="payment_id" value="<?= (int)$p['id'] ?>">
                        <input type="hidden" name="decision" value="reject">
                        <button type="submit" class="btn btn-secondary btn-sm">رد</button>
                    </form>
                </div>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>
</div>
<?php endif; ?>

<div class="card">
    <h2 class="card-title">ثبت‌نام و پشتیبانی</h2>
    <?php /* ⛔ ثبت‌نام پیش‌فرض خاموش است. یک دفترِ خصوصی نباید با یک
             به‌روزرسانی و بی‌خبر، به روی اینترنت باز شود — کسی که
             می‌خواهد اپ را بفروشد خودش روشنش می‌کند. */ ?>
    <p class="hint" style="margin-bottom:12px;">
        با روشن کردن این گزینه، هر کسی می‌تواند از صفحه‌ی ورود برای خودش
        حساب بسازد. تا وقتی خاموش است، آدرس ثبت‌نام اصلاً وجود ندارد و
        فقط شما می‌توانید کاربر بسازید.
    </p>
    <form method="POST">
        <?= Csrf::field() ?>
        <input type="hidden" name="action" value="update_signup_setting">
        <label class="switch" style="margin-bottom:14px;">
            <input type="checkbox" name="allow_signup" value="1" <?= $signupOn ? 'checked' : '' ?>>
            <span class="switch-track"><span class="switch-knob"></span></span>
            <span class="switch-text">ثبت‌نام آزاد برای همه</span>
        </label>

        <div class="form-group">
            <label for="support_email">ایمیل پشتیبانی (اختیاری)</label>
            <input type="email" id="support_email" name="support_email" maxlength="190"
                   value="<?= h($supportEmail) ?>" placeholder="support@example.com"
                   autocapitalize="none" autocorrect="off" spellcheck="false">
            <p class="hint">در صفحه‌ی حریم خصوصی و پروفایل به کاربران نشان داده می‌شود.</p>
        </div>

        <button type="submit" class="btn btn-secondary btn-sm">ذخیره</button>
    </form>
</div>

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
                    <th>عملیات</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($users)): ?>
                    <tr><td colspan="<?= $hasEmailColumn ? 7 : 6 ?>" class="empty-row">کاربری یافت نشد.</td></tr>
                <?php else: ?>
                    <?php foreach ($users as $u): ?>
                        <tr>
                            <td data-label="نام"><?= h($u['full_name']) ?></td>
                            <td data-label="نام کاربری"><?= h($u['username']) ?></td>
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
                            <td data-label="عملیات">
                                <div style="display:flex; gap:6px; flex-wrap:wrap;">
                                    <button type="button" class="btn btn-secondary btn-sm js-edit-user"
                                        data-id="<?= (int)$u['id'] ?>"
                                        data-full-name="<?= h($u['full_name']) ?>"
                                        data-username="<?= h($u['username']) ?>"
                                        data-email="<?= h($u['email'] ?? '') ?>"
                                        data-role="<?= h($u['role']) ?>">ویرایش</button>

                                    <?php if ((int)$u['id'] !== $currentUserId): ?>
                                        <form method="POST" style="display:inline;">
                                            <?= Csrf::field() ?>
                                            <input type="hidden" name="action" value="toggle_status">
                                            <input type="hidden" name="user_id" value="<?= (int)$u['id'] ?>">
                                            <button type="submit" class="btn btn-secondary btn-sm">
                                                <?= (int)$u['is_active'] === 1 ? 'غیرفعال‌سازی' : 'فعال‌سازی' ?>
                                            </button>
                                        </form>
                                        <form method="POST" style="display:inline;" onsubmit="return confirm('آیا از حذف این کاربر مطمئن هستید؟ این عملیات قابل بازگشت نیست.');">
                                            <?= Csrf::field() ?>
                                            <input type="hidden" name="action" value="delete">
                                            <input type="hidden" name="user_id" value="<?= (int)$u['id'] ?>">
                                            <button type="submit" class="delete-btn">حذف</button>
                                        </form>
                                    <?php else: ?>
                                        <span style="font-size:12px; color:var(--color-gray-500); align-self:center;">(حساب شما)</span>
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
