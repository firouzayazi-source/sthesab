<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/csrf.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/sms_login.php';
require_once __DIR__ . '/includes/user_data.php';
require_once __DIR__ . '/includes/plan.php';
// ⚠ صریح لود می‌شود، نه از راهِ غیرمستقیم: `passwordHint()` اینجا رندر
//   می‌شود و همان درسِ `includes/sms.php` است — مصرف‌کننده باید خودش
//   بیاوردش، وگرنه جابه‌جا شدنِ یک require دیگر این صفحه را می‌شکند.
require_once __DIR__ . '/includes/signup.php';

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
    $me['phone']  = $me['phone'] ?? null;
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

// ⛔ حسابی که با شماره موبایل ساخته شده هنوز رمزی ندارد. صفحه باید
//    همین را بگوید و فرم‌هایش را با آن هماهنگ کند، وگرنه کاربر یک فیلدِ
//    «رمز فعلی» می‌بیند که هیچ مقداری برایش درست نیست — یعنی بن‌بست.
// ⚠ از تنها جای این پرسش می‌آید (`userHasPassword()`), نه از خواندنِ
//   مستقیمِ ستون: `$me` بالاتر عمداً `password_hash` را دور انداخته.
$hasPassword = userHasPassword($userId);

// ⚠ شماره وقتی هم نشان داده می‌شود که مدیر ورودِ پیامکی را خاموش کرده
//   باشد ولی این حساب شماره‌ای **دارد**: پنهان کردنش یعنی کاربر نه
//   می‌بیند چه شماره‌ای ثبت است نه می‌تواند عوضش کند، در حالی که همان
//   شماره هنوز یک شناسه‌ی ورود با رمز است.
$showPhoneField = SmsLogin::available() || ($me['phone'] ?? '') !== '';

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
                <input type="file" id="avatarInput" accept="image/*" hidden>
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M23 19a2 2 0 01-2 2H3a2 2 0 01-2-2V8a2 2 0 012-2h4l2-3h6l2 3h4a2 2 0 012 2z"/><circle cx="12" cy="13" r="4"/></svg>
            </label>
        </div>

        <div class="profile-name"><?= h($me['full_name'] ?? '') ?></div>
        <div class="profile-sub">
            <?= h($me['username'] ?? '') ?>
            · <?= h(Auth::roleLabel((string)($me['role'] ?? 'user'))) ?>
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
                <?php /* ⛔ `required` مشروط است، نه همیشگی: کسی که با شماره
                         ثبت‌نام کرده ایمیلی ندارد و اجبارِ آن یعنی نتواند
                         حتی نامش را ذخیره کند. قاعده‌ی سرور همان
                         «دست‌کم یک راهِ بازگشت» است و اینجا فقط بازتابش
                         می‌دهد — اعتبارسنجیِ واقعی در اندپوینت است. */ ?>
                <input type="email" id="pf_email" name="email" maxlength="190"
                       <?= ($me['phone'] ?? '') === '' ? 'required' : '' ?>
                       autocapitalize="none" autocorrect="off" spellcheck="false"
                       autocomplete="email" placeholder="مثلاً: you@gmail.com"
                       value="<?= h($me['email'] ?? '') ?>">
                <p class="hint">
                    <?php if (empty($me['email']) && ($me['phone'] ?? '') !== ''): ?>
                        اختیاری است، چون شماره‌ی موبایلتان ثبت شده. با ثبت ایمیل می‌توانید با آن هم وارد شوید.
                    <?php elseif (empty($me['email'])): ?>
                        هنوز ایمیلی ثبت نکرده‌اید. بدون آن، اگر رمزتان را فراموش کنید راهی برای بازیابی ندارید.
                    <?php else: ?>
                        با همین ایمیل هم می‌توانید وارد شوید، و لینک بازیابی رمز به همین آدرس می‌رود.
                    <?php endif; ?>
                </p>
            </div>
<?php endif; ?>

<?php /* ⛔ شماره فقط وقتی نشان داده می‌شود که مدیر ورودِ پیامکی را روشن
         کرده باشد. اگر خاموش است، این فیلد هیچ کاری نمی‌کند و فقط یک
         فیلدِ اضافه در سرراهِ کاربر است — و فیلدی که کاری نمی‌کند از
         نبودنش بدتر است. */ ?>
<?php if ($showPhoneField): ?>
            <div class="form-group">
                <label for="pf_phone">شماره موبایل<?= ($me['phone'] ?? '') === '' ? ' (اختیاری)' : '' ?></label>
                <input type="tel" id="pf_phone" name="phone" maxlength="20"
                       inputmode="tel" autocomplete="tel" placeholder="۰۹۱۲۳۴۵۶۷۸۹"
                       value="<?= h(toPersianDigits($me['phone'] ?? '')) ?>">
                <p class="hint">
                    <?php if (empty($me['phone'])): ?>
                        با ثبت شماره می‌توانید بدون رمز، با کد پیامکی وارد شوید.
                    <?php else: ?>
                        کد ورود به همین شماره فرستاده می‌شود. خالی گذاشتنِ این فیلد شماره را پاک نمی‌کند.
                    <?php endif; ?>
                </p>
            </div>
<?php endif; ?>

            <?php /* ⚠ حسابِ بی‌رمز (ثبت‌نام با شماره) اصلاً این فیلد را
                     نمی‌بیند: هیچ مقداری برایش درست نیست و نشان دادنش
                     یعنی کاربر نتواند پروفایلش را ذخیره کند. */ ?>
            <?php if ($hasPassword): ?>
            <div class="form-group">
                <label for="pf_current_pass_1">رمز عبور فعلی (برای تأیید)</label>
                <input type="password" id="pf_current_pass_1" name="current_password" required autocomplete="current-password">
            </div>
            <?php endif; ?>

            <div id="profileMessage" class="form-message" hidden></div>
            <button type="submit" class="btn btn-primary btn-block" id="profileSubmitBtn">ذخیره</button>
        </form>
    </div>
</div>

<!-- ---------- رمز عبور ---------- -->
<?php /* ⛔ کارتِ حسابِ بی‌رمز **باز** رندر می‌شود، نه جمع‌شده. این تنها
         کاری است که آن کاربر واقعاً باید انجام بدهد و پنهان کردنش پشتِ
         یک تیتر یعنی هرگز انجام نمی‌شود — همان استدلالِ «کارتِ دقیقه‌ی
         اول بالای همه چیز است». */ ?>
<div class="card collapsible-card<?= $hasPassword ? ' collapsed' : '' ?>">
    <div class="collapsible-header">
        <h2 class="card-title" style="margin-bottom:0;"><?= $hasPassword ? 'تغییر رمز عبور' : 'تعیین رمز عبور' ?></h2>
        <span class="collapse-chevron">▾</span>
    </div>
    <div class="collapsible-body">
        <form id="passwordForm" autocomplete="off">
            <?= Csrf::field() ?>

            <?php if ($hasPassword): ?>
            <div class="form-group">
                <label for="pf_old_pass">رمز فعلی</label>
                <input type="password" id="pf_old_pass" name="current_password" required autocomplete="current-password">
            </div>
            <?php else: ?>
            <?php /* ⚠ رنگِ دعوت است نه هشدار: چیزی خراب نشده و حسابش کار
                     می‌کند — فقط یک درِ دوم هنوز باز نشده. */ ?>
            <p class="hint" style="margin-bottom:12px;">
                حساب شما با شماره موبایل ساخته شده و هنوز رمزی ندارد. با تعیین رمز
                می‌توانید از هر دستگاهی بدون کد پیامکی هم وارد شوید.
            </p>
            <?php endif; ?>

            <div class="form-group">
                <label for="pf_new_pass"><?= $hasPassword ? 'رمز جدید' : 'رمز عبور' ?></label>
                <input type="password" id="pf_new_pass" name="new_password" required autocomplete="new-password">
                <p class="hint"><?= h(passwordHint()) ?></p>
            </div>

            <div class="form-group">
                <label for="pf_new_pass2"><?= $hasPassword ? 'تکرار رمز جدید' : 'تکرار رمز' ?></label>
                <input type="password" id="pf_new_pass2" name="new_password_confirm" required autocomplete="new-password">
            </div>

            <div id="passwordMessage" class="form-message" hidden></div>
            <button type="submit" class="btn btn-primary btn-block" id="passwordSubmitBtn"><?= $hasPassword ? 'تغییر رمز' : 'تعیین رمز' ?></button>
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

<?php /* ⛔ درِ دومِ نصبِ اپ — و بدونش نوارِ پیشنهاد یک‌بارمصرف بود:
         کسی که یک بار «×» را زد دیگر هیچ راهی به APK نداشت. اینجا
         برخلافِ نوار روی هر دستگاهی دیده می‌شود (نه فقط اندروید)، چون
         کاربرِ دسکتاپ ممکن است بخواهد لینک را برای گوشیِ خودش بفرستد.
         اگر APK وجود نداشته باشد اصلاً رندر نمی‌شود. */ ?>
<?php $apkUrl = androidApkUrl(); ?>
<?php if ($apkUrl !== ''): ?>
<div class="card">
    <h2 class="card-title">اپ اندروید</h2>
    <p class="hint" style="margin-bottom:12px;">
        همین برنامه، تمام‌صفحه و بدون نوار آدرس، با یک آیکون روی صفحه‌ی گوشی.
        اگر اندروید هنگام نصب اجازه نداد، در همان پیام «تنظیمات» را بزنید و
        نصب از این مرورگر را روشن کنید.
    </p>
    <a class="btn btn-primary btn-sm" href="<?= h($apkUrl) ?>" download>دریافت فایل نصب</a>
</div>
<?php endif; ?>

<?php /* ⛔ فقط در اپ اندروید دیده می‌شود، و این عمدی است.
         خواندنِ پیامک کارِ لایه‌ی بومی است؛ در مرورگر هیچ راهی برایش
         نیست (نه PWA و نه TWA به SMS دسترسی دارند). نشان دادنِ یک کلید
         که در مرورگر هیچ کاری نمی‌کند دقیقاً همان «دکمه‌ی بی‌کار از
         نبودنش بدتر است» می‌شد — پس با جاوااسکریپت و فقط وقتی که
         نشانه‌ی اپِ اندروید در صفحه باشد ظاهر می‌شود. */ ?>
<div class="card" id="smsCaptureCard" hidden>
    <h2 class="card-title">ثبت خودکار از پیامک بانک</h2>
    <p class="hint" style="margin-bottom:12px;">
        وقتی پیامک برداشت یا واریز برسد، یک اعلان می‌بینید و با زدنش فرم ثبت
        تراکنش از قبل پر شده باز می‌شود. متن پیامک از گوشی شما بیرون نمی‌رود
        و چیزی به سرور فرستاده نمی‌شود.
    </p>
    <a class="btn btn-secondary btn-sm" id="smsCaptureLink"
       href="intent://sms-setup#Intent;scheme=hesabland;action=ir.stland.hesabland.SMS_SETUP;package=ir.stland.hesabland;end">
        تنظیم در اپ اندروید
    </a>

    <?php /* ⛔ پیش‌فرض خاموش، و انتخابش روی **همین دستگاه** می‌ماند نه در
             دیتابیس. دو دلیل: این رفتار فقط جایی معنا دارد که پیامک
             واقعاً می‌رسد (اپ اندروید، با مجوزِ SMS)، پس یک تنظیمِ
             حساب‌محور روی لپ‌تاپ چیزی را روشن می‌کرد که آنجا اصلاً
             اتفاق نمی‌افتد؛ و پاک شدنِ داده‌ی سایت آن را **خاموش**
             می‌کند نه روشن — یعنی شکستش به سمتِ امن است. */ ?>
    <label class="switch" style="margin-top:16px; margin-bottom:0;">
        <input type="checkbox" id="smsAutoToggle">
        <span class="switch-track"><span class="switch-knob"></span></span>
        <span class="switch-text">بدون تأیید ثبت کن</span>
    </label>
    <p class="hint" style="margin:8px 0 0;">
        فقط وقتی خواندنِ پیامک قطعی باشد: واحد پول در متن نوشته شده باشد و
        حساب از روی چهار رقمِ آخرِ کارت پیدا شود. در هر حالتِ دیگر مثل قبل
        فرم پر می‌شود و ثبت با خودِ شماست. هر ثبتِ خودکار بالای صفحه با
        دکمه‌ی «لغو» نشان داده می‌شود.
    </p>
</div>

<?php
// ⛔ اعلان روی گوشی — «نوتیف داخل اپ روی آیکون و گوشی بیاد مثل سایر اپ‌ها».
//    فقط وقتی رندر می‌شود که سرور واقعاً بتواند بفرستد (جدول + openssl +
//    کلید). وضعیتِ همین دستگاه را `app.js` از خودِ مرورگر می‌خواند، چون
//    اشتراک مالِ مرورگر است نه کاربر.
require_once __DIR__ . '/includes/push.php';
$__pushKey = Push::available() ? Push::publicKey() : '';
?>
<?php if ($__pushKey !== ''): ?>
<div class="card" id="pushCard" data-key="<?= h($__pushKey) ?>">
    <h2 class="card-title">اعلان روی گوشی</h2>
    <p class="push-state" id="pushState">در حال بررسیِ این دستگاه…</p>
    <div class="push-actions">
        <button type="button" class="btn btn-primary" id="pushOn" hidden>روشن کردنِ اعلان</button>
        <button type="button" class="btn btn-secondary" id="pushTest" hidden>آزمایش اعلان</button>
        <button type="button" class="btn btn-secondary" id="pushOff" hidden>خاموش کردن</button>
    </div>
    <p class="hint">
        سررسیدِ چک و قسط، یادآورها و پاسخِ پشتیبانی — حتی وقتی اپ بسته است — روی
        صفحه‌ی گوشی می‌آید و تعدادِ اعلان‌های نخوانده روی آیکونِ اپ دیده می‌شود.
        روی آیفون اول اپ را به صفحه‌ی اصلی اضافه کنید و از همان آیکون باز کنید.
    </p>
    <div id="pushMsg" class="form-message" hidden></div>
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

    <?php /* ⛔ رادیو است نه دکمه‌ی جاوااسکریپتی: با صفحه‌کلید و صفحه‌خوان
             هم کار می‌کند، و «کدام انتخاب است» از خودِ `checked` خوانده
             می‌شود. مقدارِ اولیه را `app.js` از `data-palette` می‌گذارد،
             چون انتخاب در همین مرورگر ذخیره است و PHP آن را نمی‌داند. */ ?>
    <div class="palette-title">رنگِ برنامه</div>
    <?php $__palDefault = (string)array_key_first(UI_PALETTES); ?>
    <div class="palette-picker" id="palettePicker" role="radiogroup" aria-label="رنگِ برنامه" data-default="<?= h($__palDefault) ?>">
        <?php foreach (UI_PALETTES as $__pal => $__palName): ?>
            <label class="palette-opt">
                <input type="radio" name="ui_palette" value="<?= h($__pal) ?>"<?= $__pal === $__palDefault ? ' checked' : '' ?>>
                <span class="palette-swatch" data-pal="<?= h($__pal) ?>">
                    <span class="palette-check"><svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M20 6 9 17l-5-5"/></svg></span>
                </span>
                <span class="palette-name"><?= h($__palName) ?></span>
            </label>
        <?php endforeach; ?>
    </div>
    <p class="hint">رنگ همان لحظه عوض می‌شود و روی همین دستگاه می‌ماند. حالت شب هم با هر رنگی کار می‌کند.</p>
</div>

<?php /* ⛔ «صفحه‌ی خانه» — کنارِ «نمایش»، چون هر دو درباره‌ی «چه چیزی
         می‌بینم» است. فهرست فقط از `HOME_WIDGETS` رندر می‌شود و حالتِ
         هر کلید از همان `$me` ای می‌آید که صفحه با `SELECT *` خوانده —
         هیچ کوئریِ تازه‌ای نیست. اگر ستون هنوز نیامده باشد کارت رندر
         نمی‌شود: کلیدی که ذخیره نمی‌شود بدتر از نبودنش است.
         ⚠ برخلافِ رنگ، این یکی در **حسابِ** کاربر ذخیره می‌شود، پس روی
           همه‌ی دستگاه‌ها یکی است. */ ?>
<?php if ($me && array_key_exists('home_hidden', $me)): ?>
<?php $__homeHidden = homeHiddenParse($me['home_hidden']); ?>
<div class="card home-widgets-card">
    <div class="hw-head">
        <h2 class="card-title" style="margin-bottom:0;">صفحه‌ی خانه</h2>
        <span class="hw-count" id="hwCount" data-total="<?= count(HOME_WIDGETS) ?>"><?= toPersianDigits((string)(count(HOME_WIDGETS) - count($__homeHidden))) ?> از <?= toPersianDigits((string)count(HOME_WIDGETS)) ?> روشن</span>
    </div>
    <p class="hint" style="margin-top:6px;">هر چیزی را که روی خانه لازم ندارید خاموش کنید؛ کارت‌ها خودشان به اندازه‌ی چیزی که می‌ماند کوچک می‌شوند. خاموش کردن هیچ داده‌ای را پاک نمی‌کند.</p>

    <form id="homeWidgetsForm" autocomplete="off">
        <?= Csrf::field() ?>
        <?php foreach (HOME_WIDGET_GROUPS as $__g => $__gName): ?>
            <div class="hw-group-title"><?= h($__gName) ?></div>
            <div class="hw-list">
                <?php foreach (HOME_WIDGETS as $__k => $__w): if ($__w['group'] !== $__g) { continue; } ?>
                    <label class="switch hw-row">
                        <input type="checkbox" name="on[]" value="<?= h($__k) ?>"<?= homeWidgetOn($__homeHidden, $__k) ? ' checked' : '' ?>>
                        <span class="switch-track"><span class="switch-knob"></span></span>
                        <span class="switch-text">
                            <span class="hw-label"><?= h($__w['label']) ?></span>
                            <span class="hw-hint"><?= h($__w['hint']) ?></span>
                        </span>
                    </label>
                <?php endforeach; ?>
            </div>
        <?php endforeach; ?>
        <div class="hw-foot">
            <button type="button" class="btn btn-secondary btn-sm" id="hwAllOn"<?= $__homeHidden === [] ? ' hidden' : '' ?>>همه را روشن کن</button>
            <div id="homeWidgetsMsg" class="form-message" hidden></div>
        </div>
    </form>
</div>
<?php endif; ?>

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

<?php /* ---------- داده‌ی شما مالِ شماست ----------
         ⛔ این بخش برای فروش پیش‌نیاز است، نه تزئین: کاربری که حس کند
         داده‌اش گروگان است پول نمی‌دهد. «هر وقت خواستی همه‌اش را ببر و
         هر وقت خواستی پاکش کن» تنها چیزی است که این حس را از بین
         می‌برد. عمداً هم‌جا با خروج آمده، نه قایم در ته یک منو. */ ?>
<div class="card collapsible-card collapsed">
    <div class="collapsible-header">
        <h2 class="card-title" style="margin-bottom:0;">داده‌ی من</h2>
        <span class="collapse-chevron">▾</span>
    </div>
    <div class="collapsible-body">

        <?php /* ⛔ لینک است، نه فرمِ مستقیم: صفحه‌ی بکاپ دکمه‌ی بازگشت
                 دارد و هر شکستی هم به همان‌جا برمی‌گردد، نه به یک
                 صفحه‌ی JSONِ بی‌راهِ‌برگشت. */ ?>
        <a href="<?= APP_BASE_PATH ?>/backup.php" class="btn btn-secondary btn-block" style="margin-top:14px;">
            گرفتن بکاپ
        </a>
        <p class="hint" style="margin-top:8px;">
            یک فایل با همه‌ی تراکنش‌ها، حساب‌ها، چک‌ها، طلب و بدهی،
            بودجه، پس‌انداز، دارایی و معاملات — بدون رمز عبور.
        </p>

        <div class="danger-zone">
            <h3 class="danger-title">حذف حساب</h3>
            <p class="hint">
                حساب و <strong>همه‌ی</strong> داده‌اش برای همیشه پاک می‌شود.
                راه برگشتی ندارد؛ اگر لازمش دارید اول خروجی بگیرید.
            </p>
            <button type="button" class="btn btn-danger btn-block" id="deleteAccountBtn">حذف حساب من</button>
        </div>

        <?php if (plansAvailable()): $__p = userPlan((int)Auth::userId()); ?>
        <p class="hint version-line" style="margin-top:14px;">
            <a href="<?= APP_BASE_PATH ?>/pro.php">
                <?= $__p['is_pro'] ? 'اشتراک فعال — مدیریت' : 'اشتراک و حمایت' ?>
            </a>
        </p>
        <?php endif; ?>

        <p class="hint version-line">
            نسخه: <?= h(appVersion()) ?> ·
            <a href="<?= APP_BASE_PATH ?>/privacy.php">حریم خصوصی</a>
            <?php $__sup = getSetting('support_email', ''); if ($__sup !== ''): ?>
                · <a href="mailto:<?= h($__sup) ?>">پشتیبانی</a>
            <?php endif; ?>
        </p>
    </div>
</div>

<div class="modal-overlay" id="deleteAccountModal">
    <div class="modal-box">
        <div class="modal-header">
            <h3>حذف حساب</h3>
            <button type="button" class="modal-close" data-modal-close>&times;</button>
        </div>
        <form id="deleteAccountForm" autocomplete="off">
            <?= Csrf::field() ?>
            <p class="hint" style="margin-bottom:14px;">
                همه‌ی داده‌ی شما پاک می‌شود و قابل بازگشت نیست.
            </p>
            <?php /* ⚠ حسابِ بی‌رمز این فیلد را نمی‌بیند — هیچ مقداری
                     برایش درست نیست. سدهایش می‌شود دو تا (نشستِ خودش و
                     تایپِ عبارتِ تأیید)، و اندپوینت هم دقیقاً همین را
                     می‌سنجد. */ ?>
            <?php if ($hasPassword): ?>
            <div class="form-group">
                <label for="del_password">رمز عبور فعلی</label>
                <input type="password" id="del_password" name="password" required>
            </div>
            <?php endif; ?>
            <div class="form-group">
                <label for="del_confirm">برای تأیید بنویسید: <strong>حذف حساب</strong></label>
                <input type="text" id="del_confirm" name="confirm" required placeholder="حذف حساب">
            </div>
            <div id="deleteAccountMessage" class="form-message" hidden></div>
            <div class="modal-actions">
                <button type="submit" class="btn btn-danger" id="deleteAccountSubmit">حذف برای همیشه</button>
                <button type="button" class="btn btn-secondary" data-modal-close>انصراف</button>
            </div>
        </form>
    </div>
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
