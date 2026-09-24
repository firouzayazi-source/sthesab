<?php
/**
 * ورود با کد پیامکی.
 *
 * ⛔ مثل `register.php`: اگر در دسترس نباشد این صفحه **وجود ندارد**
 *    (۴۰۴)، نه اینکه بگوید «غیرفعال است». پیامِ «غیرفعال» به یک اسکنر
 *    می‌گوید اینجا راهِ ورودِ دومی هست که فقط خاموش است.
 *
 * ⛔ **دو مرحله در یک صفحه: شماره → کد. و تمام.**
 *
 *    مرحله‌ی سومِ «یک رمز بگذارید» بود و **پس گرفته شد**: پرسیدنِ
 *    رمز دقیقاً در ثانیه‌ای می‌نشست که کاربر هنوز هیچ دلیلی برای
 *    ماندن نداشت. حالا کد که تأیید شد، حساب ساخته می‌شود و کاربر
 *    مستقیم در خانه است؛ رمز و ایمیل هر وقت خودش خواست، از
 *    پروفایل. استدلالِ کاملش بالای `validateNewUser()`.
 *
 *    شماره در فیلدِ پنهان حمل می‌شود نه در نشست: کاربر
 *    ممکن است پیامک را روی گوشیِ دیگری ببیند، صفحه را ببندد، یا اپ
 *    نشست را جمع کرده باشد. حمل در فرم هیچ چیزی را ناامن نمی‌کند —
 *    خودِ کد است که احراز می‌کند.
 *
 * ⛔ **ولی نتیجه‌ی تأیید در نشست می‌ماند، نه در فرم.** بعد از مرحله‌ی
 *    دوم کد سوخته و تنها چیزِ باقی‌مانده ادعای «این شماره تأیید شد»
 *    است؛ اگر آن هم از مرورگر می‌آمد، هر کسی بدونِ هیچ پیامکی حساب
 *    می‌ساخت. جزئیات بالای `phoneVerifyRemember()`.
 */
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/csrf.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/sms_login.php';
require_once __DIR__ . '/includes/signup.php';

Auth::initSession();

if (!Auth::hasAnyUser()) {
    header('Location: setup.php');
    exit;
}
if (Auth::isLoggedIn()) {
    header('Location: index.php');
    exit;
}
if (!SmsLogin::available()) {
    http_response_code(404);
    exit('Not found.');
}

$error   = '';
$notice  = '';
$needPro = false;
$phone   = trim(postParam('phone'));
// ⚠ نگه داشته می‌شود تا یک اشتباهِ تایپی در رمز، ایمیلِ تایپ‌شده را هم
//   پاک نکند — وگرنه کاربر بارِ دوم بی‌خیالش می‌شود و همان «هرگز» است.
$emailIn = trim(postParam('email'));
$step    = 'phone';
$ip      = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';

// ⛔ ثبت‌نام با شماره پشتِ **دو** کلیدِ مدیر است و از تنها جای این
//    تصمیم پرسیده می‌شود. اگر باز نباشد، این صفحه دقیقاً همان صفحه‌ی
//    ورودِ قبلی است — نه یک صفحه‌ی نیمه‌کاره که وعده‌ی ثبت‌نام بدهد.
$canSignup = phoneSignupEnabled();

/**
 * پایانِ مسیرِ موفق — یک جا برای هر دو راه (ورود و ثبت‌نام).
 *
 * ⚠ با دو نسخه، «به خاطر بسپار» یا `rememberUsername()` دیر یا زود از
 *   یکی می‌افتاد و خرابی‌اش بی‌صداست: کاربر دفعه‌ی بعد باز پیامک
 *   می‌خواست، بی‌آنکه بفهمد چرا.
 */
function finishPhoneLogin(array $user, bool $created, string $warning = ''): void
{
    Auth::establishSession($user, 'sms');

    // ⛔ «این دستگاه را به خاطر بسپار» تیک‌خورده می‌آید و همان چیزی است
    //    که «دفعه‌ی بعد بدون رمز و بدون پیامک» را می‌سازد. بدونش کاربر
    //    هر بار یک پیامکِ دیگر می‌گرفت — با هزینه‌اش، و پشتِ گیتِ Pro.
    if (postParam('trust_device') === '1') {
        Auth::trustThisDevice((int)$user['id']);
    }
    Auth::rememberUsername((string)$user['username']);

    // ⛔ **کاربرِ تازه هم به خانه می‌رود، نه به پروفایل** — و این عوض
    //    شد. استدلالِ قبلی («نامش هنوز کاربر ۱۲۳۴ است و اگر همان اول
    //    نبیند هرگز سراغش نمی‌رود») درست بود ولی جواب را اشتباه
    //    می‌داد: اولین چیزی که کاربرِ تازه‌ی یک دفترِ مالی می‌دید یک
    //    **فرمِ تنظیمات** بود، نه دفترش. کسی که آمده خرجش را ثبت کند،
    //    باید بتواند همان لحظه ثبت کند.
    //
    // ⛔ و پیام **نام کاربری را می‌گوید**. تا امروز نمی‌گفت: کاربر شش
    //    ماه بعد پشتِ صفحه‌ی ورود نمی‌دانست چه چیزی تایپ کند. شناسه از
    //    روزِ اول وجود داشت و فقط **دیده نمی‌شد** — و شناسه‌ای که کاربر
    //    نداند، با نداشتنش فرقی ندارد.
    if ($created) {
        $note = 'حساب شما ساخته شد. نام کاربری شما برای ورودهای بعدی: '
              . toPersianDigits((string)$user['username'])
              . ' — هر وقت خواستید می‌توانید از پروفایل رمز عبور هم بگذارید.';
        if ($warning !== '') { $note .= ' ⚠ ایمیل ثبت نشد: ' . $warning; }
    }

    redirectWithMessage(
        'index.php',
        ($created && $warning !== '') ? 'error' : 'success',
        $created ? $note : 'خوش آمدید.'
    );
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // ⚠ مثل login.php و register.php: توکنِ کهنه بن‌بست نمی‌سازد.
    if (!Csrf::validate(postParam('csrf_token'))) {
        $error = 'نشست شما منقضی شده بود. دوباره تلاش کنید.';
        $step  = postParam('step') === 'code' ? 'code' : 'phone';
    } elseif (postParam('step') === 'code') {
        // ⛔ از `phoneAuthComplete()` رد می‌شود، نه از `verifyCode()` تنها:
        //    تصمیمِ «این کد به ورود می‌رسد یا به ساختِ حساب» یک جا گرفته
        //    می‌شود. با تصمیم‌گیری در همین صفحه، اپِ موبایل یا هر ورودیِ
        //    بعدی نسخه‌ی دومِ آن را می‌نوشت.
        // ⛔ یک مرحله، نه دو. تا دیروز شماره‌ی تازه به مرحله‌ی سومِ «یک
        //    رمز بگذارید» می‌رفت؛ حالا `phoneAuthComplete()` خودش حساب را
        //    می‌سازد و کاربر مستقیم داخل است. رمز و ایمیل هر وقت خودش
        //    خواست، از پروفایل.
        $res = phoneAuthComplete($phone, postParam('code'), $ip);
        if ($res['ok']) {
            finishPhoneLogin($res['user'], (bool)($res['created'] ?? false),
                             (string)($res['warning'] ?? ''));
        }
        $error   = $res['message'];
        $needPro = (bool)($res['need_pro'] ?? false);
        // ⚠ `restart` یعنی نشانه‌ی تأیید دیگر معتبر نیست و کدِ تازه لازم
        //   است — ماندن روی همین مرحله فقط همان خطا را تکرار می‌کرد.
        $step    = ($res['restart'] ?? false) ? 'phone' : 'code';
    } else {
        $res = SmsLogin::requestCode($phone, $ip);
        if ($res['success']) {
            $notice = $res['message'];
            $step   = 'code';
        } else {
            $error = $res['message'];
            // وقتی فقط باید صبر کند، برگرداندنش به مرحله‌ی اول یعنی
            // شماره را دوباره بزند — و کدی که در دست دارد را هدر بدهد.
            $step  = isset($res['wait']) ? 'code' : 'phone';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1, user-scalable=no, viewport-fit=cover">
    <title><?= $canSignup ? 'ورود یا ثبت‌نام با شماره موبایل' : 'ورود با پیامک' ?> | <?= h(APP_NAME) ?></title>
    <?php foreach (assetUrls(['css/style.css']) as $__u): ?>
    <link rel="stylesheet" href="<?= h($__u) ?>">
    <?php endforeach; ?>
    <link rel="apple-touch-icon" href="<?= iconUrl('icon-180.png') ?>">
    <link rel="icon" type="image/png" sizes="32x32" href="<?= iconUrl('icon-32.png') ?>">
    <link rel="manifest" href="<?= APP_BASE_PATH ?>/assets/manifest.php">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <meta name="theme-color" content="#0b5d41">
</head>
<body class="auth-body">
    <div class="auth-box">
        <div class="auth-logo">
            <img src="<?= iconUrl('icon-180.png') ?>" alt="" class="auth-avatar auth-avatar-app" width="76" height="76">
            <h1><?= $canSignup ? 'ورود یا ثبت‌نام' : 'ورود با پیامک' ?></h1>
            <p class="auth-subtitle"><?php
                if ($step === 'code') {
                    echo 'کد پیامک‌شده را وارد کنید';
                } elseif ($canSignup) {
                    echo 'با شماره موبایل — حساب ندارید؟ همین‌جا ساخته می‌شود';
                } else {
                    echo 'شماره موبایلِ ثبت‌شده در حساب';
                }
            ?></p>
        </div>

        <?php if ($error): ?>
            <div class="alert alert-error"><?= h($error) ?></div>
            <?php /* ⛔ پیامِ «نسخه‌ی کامل لازم است» بدونِ راهِ خروج، همان
                     بن‌بستی است که این پروژه جای دیگری برایش تست نوشته:
                     کاربر می‌داند نمی‌تواند وارد شود ولی نمی‌داند بعدش
                     چه کند. پس هر دو درِ باز کنارش گذاشته می‌شوند. */ ?>
            <?php if ($needPro): ?>
                <?php /* ⚠ دکمه‌ی «نسخه‌ی کامل» عمداً اینجا نیست: `pro.php`
                         خودش ورود می‌خواهد، پس این کاربرِ واردنشده را به
                         همین صفحه‌ی ورود برمی‌گرداند — یک حلقه. و او رمز
                         **دارد** (وگرنه اصلاً گیت نمی‌خورد)، پس راهِ
                         درستش همین یک دکمه است. */ ?>
                <a href="<?= APP_BASE_PATH ?>/login.php" class="btn btn-primary btn-block"
                   style="margin:-6px 0 14px;">ورود با رمز عبور</a>
            <?php endif; ?>
        <?php endif; ?>
        <?php if ($notice): ?>
            <div class="alert alert-success"><?= h($notice) ?></div>
        <?php endif; ?>

        <?php if ($step === 'code'): ?>
            <form method="POST" class="auth-form" autocomplete="off">
                <?= Csrf::field() ?>
                <input type="hidden" name="step" value="code">
                <input type="hidden" name="phone" value="<?= h($phone) ?>">

                <div class="form-group">
                    <label for="code">کد <?= toPersianDigits(SmsLogin::CODE_LENGTH) ?> رقمی</label>
                    <?php /* inputmode=numeric تا روی گوشی صفحه‌کلیدِ عددی بیاید،
                             و autocomplete=one-time-code تا iOS خودش کد را از
                             پیامک پیشنهاد بدهد. */ ?>
                    <input type="text" id="code" name="code" required autofocus
                           inputmode="numeric" autocomplete="one-time-code"
                           maxlength="<?= (int)SmsLogin::CODE_LENGTH ?>" class="otp-input"
                           placeholder="<?= str_repeat('•', SmsLogin::CODE_LENGTH) ?>">
                    <p class="hint">
                        به <?= toPersianDigits(h($phone)) ?> فرستاده شد ·
                        اعتبار <?= toPersianDigits(SmsLogin::CODE_TTL_MIN) ?> دقیقه
                    </p>
                </div>

                <label class="switch" style="margin:4px 0 16px;">
                    <input type="checkbox" name="trust_device" value="1" checked>
                    <span class="switch-track"><span class="switch-knob"></span></span>
                    <span class="switch-text">این دستگاه را به خاطر بسپار — دفعه‌ی بعد بدون رمز و بدون پیامک وارد شوید</span>
                </label>

                <button type="submit" class="btn btn-primary btn-block" data-busy="در حال ورود…">ورود</button>
            </form>

            <form method="POST" style="margin-top:10px;">
                <?= Csrf::field() ?>
                <input type="hidden" name="phone" value="<?= h($phone) ?>">
                <button type="submit" class="btn btn-secondary btn-block">کد را دوباره بفرست</button>
            </form>
        <?php else: ?>
            <form method="POST" class="auth-form" autocomplete="off">
                <?= Csrf::field() ?>
                <div class="form-group">
                    <label for="phone">شماره موبایل</label>
                    <?php /* ⚠ جای‌نگهدار با ارقامِ **لاتین** است: ورودی
                             چپ‌به‌راست و `tabular-nums` است و ارقامِ فارسی
                             آنجا پهنای دیگری دارند، پس جای‌نگهدار و مقدارِ
                             واقعی روی هم نمی‌افتادند و فیلد می‌پرید. */ ?>
                    <input type="tel" id="phone" name="phone" required autofocus
                           inputmode="numeric" autocomplete="tel" maxlength="20"
                           class="phone-input" placeholder="09123456789"
                           value="<?= h($phone) ?>">
                    <p class="hint"><?= $canSignup
                        ? 'اگر حساب دارید وارد می‌شوید، وگرنه با همین شماره برایتان ساخته می‌شود.'
                        : 'همان شماره‌ای که در پروفایلِ حسابتان ثبت شده است.' ?></p>
                </div>
                <button type="submit" class="btn btn-primary btn-block" data-busy="در حال ارسال…">فرستادن کد</button>
            </form>
        <?php endif; ?>

        <a href="<?= APP_BASE_PATH ?>/login.php" class="link-back"
           style="display:block;text-align:center;margin-top:14px">ورود با رمز عبور</a>
    </div>
<?php include __DIR__ . '/includes/auth_form_js.php'; ?>
</body>
</html>
