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
$tradesOn = tradesEnabled($pdo, $userId);
$tradesColumnReady = usersHaveColumn($pdo, 'trades_enabled');

// فهرست از خودِ Auth می‌آید تا با اعتبارسنجیِ اندپوینت یکی بماند.
$sessionOptions = Auth::SESSION_WINDOWS;
$sessionMinutes = Auth::sessionMinutesFor($userId);

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
<?php if ($tradesColumnReady): ?>
<div class="card">
    <h2 class="card-title">بخش معاملات</h2>
    <label class="switch" style="margin-bottom:0;">
        <input type="checkbox" id="tradesToggle" <?= $tradesOn ? 'checked' : '' ?>>
        <span class="switch-track"><span class="switch-knob"></span></span>
        <span class="switch-text">فعال کردن بخش خرید و فروش</span>
    </label>
    <p class="hint">
        دفتری جدا از درآمد و هزینه: جنسی می‌خرید، بعداً می‌فروشید، و سود
        هر معامله همان‌جا حساب می‌شود. خاموش کردنش چیزی را پاک نمی‌کند.
    </p>
    <div id="tradesToggleMsg" class="form-message" hidden></div>
</div>
<?php endif; ?>

<?php
// یادآوریِ سررسید — فقط وقتی نشان داده می‌شود که هم جدولش آمده باشد و
// هم سرور واقعاً بتواند ایمیل بفرستد. کلیدی که کار نمی‌کند بدتر از
// نبودنش است: کاربر روشنش می‌کند و بعد چکش برگشت می‌خورد.
$remindReady = tableExists('notification_prefs');
require_once __DIR__ . '/includes/mailer.php';
$mailReady   = Mailer::isConfigured();
$remind      = $remindReady ? reminderPrefs($userId) : ['email_on' => true, 'days_before' => 3];
?>
<?php if ($remindReady): ?>
<div class="card">
    <h2 class="card-title">یادآوری سررسید</h2>
    <label class="switch" style="margin-bottom:0;">
        <input type="checkbox" id="remindToggle" <?= $remind['email_on'] ? 'checked' : '' ?>>
        <span class="switch-track"><span class="switch-knob"></span></span>
        <span class="switch-text">خلاصه‌ی روزانه با ایمیل</span>
    </label>

    <div class="remind-days<?= $remind['email_on'] ? ' is-open' : '' ?>" id="remindDays">
        <div class="remind-days-inner">
            <div class="stay-choices-label">چند روز قبل خبر بدهد؟</div>
            <div class="stay-chips">
                <?php foreach (REMINDER_DAYS as $d): ?>
                    <button type="button" class="stay-chip<?= $remind['days_before'] === $d ? ' active' : '' ?>"
                            data-days="<?= $d ?>"><?= toPersianDigits((string)$d) ?> روز</button>
                <?php endforeach; ?>
            </div>
        </div>
    </div>

    <p class="hint">
        اگر چک، طلب، بدهی یا پرداخت دوره‌ای نزدیک باشد، یک ایمیل خلاصه
        می‌گیرید. وقتی چیزی در راه نیست، ایمیلی هم فرستاده نمی‌شود.
        <?php if (!$mailReady): ?>
            <br><strong>ارسال ایمیل روی این سرور هنوز تنظیم نشده</strong> — تا آن موقع
            این تنظیم ذخیره می‌شود ولی ایمیلی نمی‌رود.
        <?php elseif (empty($me['email'])): ?>
            <br><strong>هنوز ایمیلی ثبت نکرده‌اید</strong> — از بخش «نام، نام کاربری و ایمیل» اضافه کنید.
        <?php endif; ?>
    </p>
    <div id="remindMsg" class="form-message" hidden></div>
    <?= Csrf::field() ?>
</div>
<?php endif; ?>

<div class="card">
    <h2 class="card-title">نمایش</h2>
    <label class="switch" style="margin-bottom:0;">
        <input type="checkbox" id="themeAuto">
        <span class="switch-track"><span class="switch-knob"></span></span>
        <span class="switch-text">پیروی خودکار از حالت شب گوشی</span>
    </label>
    <p class="hint">وقتی روشن باشد، با تغییر حالت شب گوشی اپ هم بلافاصله عوض می‌شود. با زدن دکمه‌ی ماه/خورشید بالای صفحه، این گزینه خاموش می‌شود.</p>
</div>

<div class="card">
    <h2 class="card-title">ورود و امنیت</h2>

    <?php
    // ⚠ «بدون مهلت» عمداً از فهرست چیپ‌ها بیرون کشیده شده و کلیدِ بالا
    //    شده. بیشترِ کاربران همان را می‌خواهند و نباید مجبور شوند بین ده
    //    گزینه دنبالش بگردند؛ بقیه هم تا کلید را خاموش نکنند چیزی
    //    نمی‌بینند. منوی کشویی با ده گزینه روی گوشی شلوغ بود.
    $stayForever = ($sessionMinutes === 0);
    $timedOptions = array_filter(
        $sessionOptions,
        static fn($k) => (int)$k !== 0,
        ARRAY_FILTER_USE_KEY
    );
    // برچسب‌های کوتاه‌تر برای چیپ‌ها: «هشت ساعت» در چیپ جا نمی‌شود.
    $chipLabels = [
        1 => '۱ دقیقه', 5 => '۵ دقیقه', 15 => '۱۵ دقیقه', 30 => '۳۰ دقیقه',
        60 => '۱ ساعت', 480 => '۸ ساعت', 1440 => '۱ روز',
        10080 => '۱ هفته', 43200 => '۱ ماه',
    ];
    ?>
    <div class="stay" id="stayBox" data-current="<?= (int)$sessionMinutes ?>">
        <?= Csrf::field() ?>

        <label class="stay-hero">
            <span class="stay-hero-text">
                <span class="stay-hero-title">همیشه وارد بمانم</span>
                <span class="stay-hero-sub">بدون مهلت — تا خودتان خارج نشوید.</span>
            </span>
            <span class="switch stay-hero-switch">
                <input type="checkbox" id="stayForever" <?= $stayForever ? 'checked' : '' ?>>
                <span class="switch-track"><span class="switch-knob"></span></span>
            </span>
        </label>

        <?php /* ⚠ همه‌ی محتوا داخل **یک** فرزند است. `grid-template-rows: 0fr`
                 فقط ردیف‌های صریح را جمع می‌کند؛ با دو فرزند، دومی به ردیفِ
                 ضمنیِ auto می‌افتاد و بسته بودنِ بخش یک حفره‌ی خالیِ بلند
                 می‌ساخت — دقیقاً همان شلوغی‌ای که این طراحی برای حذفش بود. */ ?>
        <div class="stay-choices<?= $stayForever ? '' : ' is-open' ?>" id="stayChoices">
            <div class="stay-choices-inner">
                <div class="stay-choices-label">بعد از چقدر بی‌فعالیتی رمز بپرسد؟</div>
                <div class="stay-chips">
                    <?php foreach ($timedOptions as $val => $label): ?>
                        <button type="button" class="stay-chip<?= $sessionMinutes === (int)$val ? ' active' : '' ?>"
                                data-minutes="<?= (int)$val ?>">
                            <?= h($chipLabels[(int)$val] ?? $label) ?>
                        </button>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>

        <p class="stay-note" id="stayNote"></p>
        <div id="sessionMessage" class="form-message" hidden></div>
    </div>

    <div class="devices-block">
        <h3 class="ref-manager-title">دستگاه‌های مورد اعتماد</h3>
        <?php /* ⚠ اینجا پیش از این «تا ۳۰ روز» ثابت نوشته شده بود. عمر کوکیِ
                 دستگاه مورد اعتماد حالا **همان** مهلتِ بالاست، پس عددِ ثابت
                 دروغ می‌شد: کاربری که «۱ ساعت» انتخاب کرده بود همچنان
                 «۳۰ روز» می‌خواند. متن از خودِ تنظیم ساخته می‌شود و
                 `#deviceWindow` با هر تغییر در جاوااسکریپت هم به‌روز می‌شود. */ ?>
        <p class="hint" style="margin-bottom:12px;">
            روی این دستگاه‌ها <span id="deviceWindow"><?= $stayForever
                ? 'تا وقتی خودتان خارج نشوید'
                : 'تا ' . h($chipLabels[$sessionMinutes] ?? $sessionOptions[$sessionMinutes] ?? ($sessionMinutes . ' دقیقه')) . ' پس از آخرین استفاده' ?></span>
            رمز پرسیده نمی‌شود. هنگام ورود، گزینه‌ی
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

<!-- ---------- تنظیم تصویر پروفایل ----------
     تصویری که کاربر از گالری گوشی می‌گیرد تقریباً هیچ‌وقت مربع نیست.
     پیش از این، سرور وسط تصویر را می‌برید — و صورت آدم معمولاً وسط
     کادر نیست. اینجا خودِ کاربر جابه‌جا و بزرگ‌نمایی می‌کند و همان
     چیزی که در دایره می‌بیند ذخیره می‌شود. -->
<div class="modal-overlay" id="avatarCropModal">
    <div class="modal-box avatar-crop-box">
        <div class="modal-header">
            <h3>تنظیم تصویر</h3>
            <button type="button" class="modal-close" id="cropCancel" aria-label="بستن">&times;</button>
        </div>

        <div class="crop-stage" id="cropStage">
            <canvas id="cropCanvas"></canvas>
            <div class="crop-mask"></div>
        </div>

        <p class="hint crop-hint">با انگشت جابه‌جا کنید. برای بزرگ‌نمایی از نوار زیر یا دو انگشت استفاده کنید.</p>

        <div class="crop-controls">
            <button type="button" class="crop-zoom-btn" id="cropZoomOut" aria-label="کوچک‌تر">−</button>
            <input type="range" id="cropZoom" min="1" max="4" step="0.01" value="1" aria-label="بزرگ‌نمایی">
            <button type="button" class="crop-zoom-btn" id="cropZoomIn" aria-label="بزرگ‌تر">+</button>
        </div>

        <div class="crop-actions">
            <button type="button" class="btn btn-secondary btn-sm" id="cropReset">از نو</button>
            <button type="button" class="btn btn-primary btn-block" id="cropSave">ذخیره تصویر</button>
        </div>
    </div>
</div>

<meta name="csrf-token" content="<?= Csrf::token() ?>">

<?php include __DIR__ . '/includes/footer.php'; ?>
