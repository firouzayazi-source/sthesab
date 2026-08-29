<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/csrf.php';
require_once __DIR__ . '/includes/functions.php';

Auth::initSession();
Auth::requireLogin();

$pdo = Database::getConnection();
$userId = Auth::userId();

// SELECT * می‌گیریم، نه فهرست ستون‌ها.
//
// چرا: قبلاً یک زنجیره‌ی کوئری بود که از کامل‌ترین شروع می‌کرد و با هر
// خطا به ساده‌تر می‌افتاد. مشکلش این بود که ستون‌های مستقل را به هم
// گره می‌زد: روی دیتابیسی که migration_p4 (ستون avatar) اجرا نشده بود،
// کوئری اول می‌شکست و کوئری‌های بعدی هم avatar داشتند، تا می‌رسید به
// آخری که نه avatar داشت و نه email. نتیجه: کاربری که ایمیل ثبت‌شده
// داشت، در پروفایلش می‌دید «هنوز ایمیلی ثبت نکرده‌اید» — یعنی یک
// migration اجرانشده‌ی بی‌ربط، ایمیل را ناپدید می‌کرد.
//
// با * هر ستونی که هست می‌آید و هر کدام نبود، جداگانه null می‌شود.
$stmt = $pdo->prepare('SELECT * FROM users WHERE id = :id');
$stmt->execute(['id' => $userId]);
$me = $stmt->fetch();

if ($me) {
    $me['session_hours'] = $me['session_hours'] ?? 1;
    $me['avatar'] = $me['avatar'] ?? null;
    $me['email']  = $me['email'] ?? null;
    unset($me['password_hash']);   // لازم نیست در این صفحه باشد
}

$devices = [];
try {
    $dStmt = $pdo->prepare('SELECT id, device_label, last_used_at, expires_at, created_at FROM trusted_devices WHERE user_id = :u ORDER BY last_used_at DESC');
    $dStmt->execute(['u' => $userId]);
    $devices = $dStmt->fetchAll();
} catch (PDOException $e) { $devices = []; }

// ستون ایمیل با migration_password_reset اضافه شده. اگر هنوز اجرا نشده
// باشد، صفحه باید بدون خطا کار کند و فقط این بخش را نشان ندهد.
$hasEmailColumn = usersHaveEmailColumn($pdo);

$sessionOptions = [
    1   => 'یک ساعت',
    8   => 'هشت ساعت',
    24  => 'یک روز',
    168 => 'یک هفته',
];

$pageTitle = 'حساب کاربری من';
include __DIR__ . '/includes/header.php';
?>

<div class="card">
    <div class="profile-head">
        <div class="avatar-wrap">
            <?php if (!empty($me['avatar'])): ?>
                <img src="<?= APP_BASE_PATH ?>/uploads/avatars/<?= h($me['avatar']) ?>"
                     alt="" class="profile-avatar profile-avatar-img" id="avatarPreview">
            <?php else: ?>
                <div class="profile-avatar" id="avatarPreview"><?= h(mb_substr($me['full_name'] ?? '؟', 0, 1)) ?></div>
            <?php endif; ?>

            <label class="avatar-camera" title="تغییر تصویر">
                <input type="file" id="avatarInput" accept="image/jpeg,image/png,image/webp" hidden>
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M23 19a2 2 0 01-2 2H3a2 2 0 01-2-2V8a2 2 0 012-2h4l2-3h6l2 3h4a2 2 0 012 2z"/><circle cx="12" cy="13" r="4"/></svg>
            </label>
        </div>

        <div class="profile-name"><?= h($me['full_name'] ?? '') ?></div>
        <div class="profile-sub">
            <?= h($me['username'] ?? '') ?>
            · <?= ($me['role'] ?? '') === 'admin' ? 'مدیر' : 'کاربر' ?>
        </div>

        <div id="avatarMessage" class="form-message" hidden></div>

        <?php if (!empty($me['avatar'])): ?>
            <button type="button" class="delete-btn" id="avatarDeleteBtn" style="margin-top:6px;">حذف تصویر</button>
        <?php endif; ?>
    </div>
</div>

<!-- ---------- نام و نام کاربری ---------- -->
<div class="card collapsible-card collapsed">
    <div class="collapsible-header">
        <h2 class="card-title" style="margin-bottom:0;">نام، نام کاربری و ایمیل</h2>
        <span class="collapse-chevron">▾</span>
    </div>
    <div class="collapsible-body">
        <form id="profileForm" autocomplete="off">
            <?= Csrf::field() ?>

            <div class="form-group">
                <label for="pf_fullname">نام نمایشی</label>
                <input type="text" id="pf_fullname" name="full_name" required maxlength="100" value="<?= h($me['full_name'] ?? '') ?>">
            </div>

            <div class="form-group">
                <label for="pf_username">نام کاربری</label>
                <input type="text" id="pf_username" name="username" required maxlength="50" value="<?= h($me['username'] ?? '') ?>">
                <p class="hint">فقط حروف انگلیسی، عدد و زیرخط. بعد از تغییر، با نام جدید وارد شوید.</p>
            </div>

<?php if ($hasEmailColumn): ?>
            <div class="form-group">
                <label for="pf_email">ایمیل</label>
                <input type="email" id="pf_email" name="email" maxlength="190" required
                       autocapitalize="none" autocorrect="off" spellcheck="false"
                       autocomplete="email" placeholder="مثلاً: you@gmail.com"
                       value="<?= h($me['email'] ?? '') ?>">
                <p class="hint">
                    <?php if (empty($me['email'])): ?>
                        هنوز ایمیلی ثبت نکرده‌اید. بدون آن، اگر رمزتان را فراموش کنید راهی برای بازیابی ندارید.
                    <?php else: ?>
                        با همین ایمیل هم می‌توانید وارد شوید، و لینک بازیابی رمز به همین آدرس می‌رود.
                    <?php endif; ?>
                </p>
            </div>
<?php endif; ?>

            <div class="form-group">
                <label for="pf_current_pass_1">رمز عبور فعلی (برای تأیید)</label>
                <input type="password" id="pf_current_pass_1" name="current_password" required autocomplete="current-password">
            </div>

            <div id="profileMessage" class="form-message" hidden></div>
            <button type="submit" class="btn btn-primary btn-block" id="profileSubmitBtn">ذخیره</button>
        </form>
    </div>
</div>

<!-- ---------- رمز عبور ---------- -->
<div class="card collapsible-card collapsed">
    <div class="collapsible-header">
        <h2 class="card-title" style="margin-bottom:0;">تغییر رمز عبور</h2>
        <span class="collapse-chevron">▾</span>
    </div>
    <div class="collapsible-body">
        <form id="passwordForm" autocomplete="off">
            <?= Csrf::field() ?>

            <div class="form-group">
                <label for="pf_old_pass">رمز فعلی</label>
                <input type="password" id="pf_old_pass" name="current_password" required autocomplete="current-password">
            </div>

            <div class="form-group">
                <label for="pf_new_pass">رمز جدید</label>
                <input type="password" id="pf_new_pass" name="new_password" required autocomplete="new-password">
                <p class="hint">حداقل ۶ کاراکتر.</p>
            </div>

            <div class="form-group">
                <label for="pf_new_pass2">تکرار رمز جدید</label>
                <input type="password" id="pf_new_pass2" name="new_password_confirm" required autocomplete="new-password">
            </div>

            <div id="passwordMessage" class="form-message" hidden></div>
            <button type="submit" class="btn btn-primary btn-block" id="passwordSubmitBtn">تغییر رمز</button>
        </form>
    </div>
</div>

<!-- ---------- ورود و امنیت ---------- -->
<div class="card">
    <h2 class="card-title">نمایش</h2>
    <label class="inline-check" style="margin-bottom:0;">
        <input type="checkbox" id="themeAuto">
        <span>پیروی خودکار از حالت شب گوشی</span>
    </label>
    <p class="hint">وقتی روشن باشد، با تغییر حالت شب گوشی اپ هم بلافاصله عوض می‌شود. با زدن دکمه‌ی ماه/خورشید بالای صفحه، این گزینه خاموش می‌شود.</p>
</div>

<div class="card">
    <h2 class="card-title">ورود و امنیت</h2>

    <form id="sessionForm" autocomplete="off">
        <?= Csrf::field() ?>
        <div class="form-group">
            <label for="pf_session_hours">تا چه مدت بدون فعالیت وارد بمانم؟</label>
            <select id="pf_session_hours" name="session_hours">
                <?php foreach ($sessionOptions as $val => $label): ?>
                    <option value="<?= (int)$val ?>" <?= (int)($me['session_hours'] ?? 1) === $val ? 'selected' : '' ?>><?= h($label) ?></option>
                <?php endforeach; ?>
            </select>
            <p class="hint">مهلت از آخرین فعالیت شما حساب می‌شود، نه از زمان ورود.</p>
        </div>
        <div id="sessionMessage" class="form-message" hidden></div>
        <button type="submit" class="btn btn-secondary btn-block btn-sm" id="sessionSubmitBtn">ذخیره</button>
    </form>

    <div class="devices-block">
        <h3 class="ref-manager-title">دستگاه‌های مورد اعتماد</h3>
        <p class="hint" style="margin-bottom:12px;">
            روی این دستگاه‌ها تا ۳۰ روز رمز پرسیده نمی‌شود. هنگام ورود، گزینه‌ی
            «این دستگاه را به خاطر بسپار» را بزنید.
        </p>

        <?php if (empty($devices)): ?>
            <p class="ref-empty">دستگاه مورد اعتمادی ثبت نشده است.</p>
        <?php else: ?>
            <?php foreach ($devices as $d): ?>
                <div class="device-row">
                    <div class="device-info">
                        <div class="device-name"><?= h($d['device_label'] ?? 'دستگاه') ?></div>
                        <div class="device-meta">
                            آخرین استفاده: <?= $d['last_used_at'] ? toJalali(substr($d['last_used_at'], 0, 10)) : '—' ?>
                            · تا <?= toJalali(substr($d['expires_at'], 0, 10)) ?>
                        </div>
                    </div>
                    <button class="delete-btn js-revoke-device" data-id="<?= (int)$d['id'] ?>">حذف</button>
                </div>
            <?php endforeach; ?>

            <button type="button" class="btn btn-secondary btn-sm" id="revokeAllBtn" style="margin-top:12px;">
                خروج از همه دستگاه‌ها
            </button>
        <?php endif; ?>
    </div>
</div>

<div class="card">
    <a href="logout.php" class="btn btn-secondary btn-block" onclick="return confirm('از حساب خارج می‌شوید؟')">خروج از حساب</a>
</div>

<meta name="csrf-token" content="<?= Csrf::token() ?>">

<?php include __DIR__ . '/includes/footer.php'; ?>
