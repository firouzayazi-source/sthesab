<?php
/**
 * ثبت‌نامِ خودسرویس — پشتِ کلیدِ مدیر.
 *
 * ⛔ **پیش‌فرض خاموش، و این عمدی است.** هر نصبی که تا امروز بالا آمده،
 *    یک دفترِ خصوصی است که مدیرش خودش کاربرها را می‌سازد. روشن شدنِ
 *    خودکارِ ثبت‌نام با یک `git pull`، در ساکت‌ترین حالتِ ممکن، آن دفتر
 *    را به روی اینترنت باز می‌کرد. کسی که می‌خواهد اپ را بفروشد،
 *    خودش روشنش می‌کند.
 *
 * ⛔ منطقِ ساختِ کاربر **یک جا**ست (`createUserAccount()`) و هم
 *    ثبت‌نامِ خودسرویس از آن رد می‌شود هم پنل مدیر. اگر دو نسخه
 *    می‌شدند، قاعده‌ای مثل «کاربر تازه باید کیف پول داشته باشد» در
 *    یکی می‌ماند و از دیگری می‌افتاد — و آن‌وقت تراکنشِ اولِ آن کاربر
 *    در هیچ حسابی نمی‌نشست.
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/login_throttle.php';

/** کلیدِ تنظیم در `app_settings`. */
const SIGNUP_SETTING = 'allow_signup';

/** حداکثر ثبت‌نام از یک IP در یک ساعت. */
const SIGNUP_MAX_PER_IP = 5;

/**
 * عمرِ نشانه‌ی «مالکیتِ این شماره ثابت شد»، به ثانیه.
 *
 * کوتاه است چون فقط باید کاربر را از مرحله‌ی کد تا مرحله‌ی رمز برساند،
 * ولی نه آن‌قدر کوتاه که کسی که وسطِ کار مکث کرده مجبور شود پیامکِ
 * دیگری بگیرد — یعنی هزینه‌ی واقعی، هم برای او هم برای اعتبارِ پنل.
 */
const PHONE_VERIFY_TTL_SEC = 900;

/** کلیدِ همان نشانه در نشست. */
const PHONE_VERIFY_KEY = 'phone_verified';

/**
 * ⛔ «این شماره تأیید شد» فقط در **نشست** می‌ماند، نه در فرم.
 *
 * بقیه‌ی این صفحه شماره را در یک فیلدِ پنهان حمل می‌کند و آنجا بی‌خطر
 * است: خودِ **کد** احراز می‌کند، نه فیلد. ولی از این مرحله به بعد کد
 * سوخته و تنها چیزی که باقی مانده همین ادعاست — و ادعایی که از مرورگر
 * بیاید یعنی هر کسی می‌تواند بنویسد «شماره‌ی فلانی تأیید شده» و بدونِ
 * هیچ پیامکی برایش حساب بسازد. پس نشانه باید سمتِ سرور بماند.
 *
 * ⚠ مقایسه‌ی زمان اینجا در PHP است و این استثنا نیست: هر دو طرف
 *   `time()` اند (اپکِ یونیکس، مستقل از منطقه‌ی زمانی). قاعده‌ی «زمان را
 *   در دیتابیس بسنج» برای مقایسه‌ی مقدارِ PHP با `NOW()` است.
 */
function phoneVerifyRemember(string $phone): void
{
    $_SESSION[PHONE_VERIFY_KEY] = ['phone' => $phone, 'exp' => time() + PHONE_VERIFY_TTL_SEC];
}

/** آیا همین شماره تأییدشده و هنوز معتبر است؟ */
function phoneVerifyOk(string $phone): bool
{
    $m = $_SESSION[PHONE_VERIFY_KEY] ?? null;
    if (!is_array($m) || $phone === '') { return false; }
    if (!hash_equals((string)($m['phone'] ?? ''), $phone)) { return false; }
    return (int)($m['exp'] ?? 0) >= time();
}

/**
 * سوزاندنِ نشانه.
 *
 * ⚠ فقط **پس از ساختِ حساب** صدا زده می‌شود، نه در هر شکستی: اگر رمزِ
 *   کوتاه هم نشانه را می‌سوزاند، کاربری که یک بار اشتباه تایپ کرده
 *   مجبور بود پیامکِ تازه بگیرد — یعنی تنبیهِ اشتباهِ تایپی با هزینه.
 */
function phoneVerifyBurn(): void
{
    unset($_SESSION[PHONE_VERIFY_KEY]);
}

/** آیا ثبت‌نامِ خودسرویس روشن است؟ */
function signupEnabled(): bool
{
    return getSetting(SIGNUP_SETTING, '0') === '1';
}

/**
 * ⛔ آیا «ثبت‌نام با شماره موبایل» در دسترس است؟ — تنها جای این تصمیم.
 *
 * **دو کلید لازم است و هیچ‌کدام به‌تنهایی کافی نیست:**
 *   ۱. `allow_signup` — همان کلیدی که ثبت‌نامِ معمولی را باز می‌کند.
 *   ۲. `SmsLogin::available()` — کلیدِ پیامک، جدولش، و پنلِ تنظیم‌شده.
 *
 * ⛔ کلیدِ سومی ساخته نشد، عمداً. مالکِ نصب باید بتواند کلِ قابلیتِ
 *    پیامک را با **یک** کلید بخواباند — حتی برای کاربرِ Pro — و آن
 *    کلید همان `allow_sms_login` است. با کلیدِ سوم، خاموش کردنِ آن یکی
 *    این یکی را باز می‌گذاشت و مالکِ نصب فکر می‌کرد پیامک خاموش است در
 *    حالی که مسیرِ ثبت‌نام هنوز کد می‌فرستاد.
 *
 * ⚠ فهرستِ دومی از این شرط‌ها نسازید: صفحه و اندپوینت هر دو باید از
 *   همین بپرسند، وگرنه صفحه‌ای باز می‌ماند که کارش را انجام نمی‌دهد.
 */
function phoneSignupEnabled(): bool
{
    require_once __DIR__ . '/sms_login.php';
    return signupEnabled() && SmsLogin::available();
}

/**
 * نامِ کاربریِ حسابی که با شماره ساخته می‌شود.
 *
 * ⛔ خودِ شماره است، نه یک رشته‌ی تصادفی. خواسته‌ی صریح این بود که
 *    «شماره مثل نام کاربری عمل کند»؛ با نامِ تصادفی، کاربر یک نامِ
 *    بی‌معنا می‌گرفت که نه یادش می‌ماند نه با آن وارد می‌شد.
 *
 * ⚠ `09xxxxxxxxx` از `usernameRuleError()` رد می‌شود (فقط رقم است)، پس
 *   ویرایشِ بعدیِ پروفایل بی‌آنکه کاربر نامش را عوض کرده باشد رد
 *   نمی‌شود — همان بن‌بستی که یک بار سرِ `ali.k` پیش آمد.
 *
 * ⚠ شماره در `users` یکتاست ولی **نامِ کاربری** ممکن است از قبل به
 *   دستِ کسِ دیگری همین رشته را گرفته باشد (مثلاً کاربری که نامش را
 *   دستی روی همان ارقام گذاشته). پس تکراری بودن سنجیده می‌شود.
 */
function usernameFromPhone(PDO $pdo, string $phone): string
{
    $st = $pdo->prepare('SELECT id FROM users WHERE username = :u LIMIT 1');
    for ($i = 1; $i <= 30; $i++) {
        $try = $i === 1 ? $phone : $phone . '.' . $i;
        $st->execute(['u' => $try]);
        if (!$st->fetch()) { return $try; }
    }
    return $phone . '.' . random_int(1000, 9999);
}

/**
 * نامِ نمایشیِ پیش‌فرضِ حسابی که با شماره ساخته می‌شود.
 *
 * ⚠ عمداً خودِ شماره نیست: نامِ نمایشی بالای هر صفحه و در نوارِ کناری
 *   دیده می‌شود و کاربر گاهی صفحه را جلوی دیگران باز می‌کند — همان
 *   استدلالی که شماره‌ی حساب و شبا را از رویِ کارتِ خانه برداشت.
 */
function displayNameFromPhone(string $phone): string
{
    return 'کاربر ' . substr($phone, -4);
}

/**
 * ⛔ تنها جایی که «نام کاربریِ معتبر» تعریف می‌شود.
 *
 * سه مسیر نام کاربری می‌نویسند و هر سه باید از همین رد شوند: ثبت‌نامِ
 * خودسرویس، ساخت و ویرایشِ کاربر در پنل مدیر، و ویرایشِ پروفایل توسطِ
 * خودِ کاربر. پیش از این هر کدام قاعده‌ی خودش را داشت و **دو تا با هم
 * نمی‌خواندند**: ساخت `[a-zA-Z0-9_.]` بدونِ حداقلِ طول را می‌پذیرفت،
 * ولی پروفایل `[A-Za-z0-9_]{3,50}` می‌خواست.
 *
 * ⛔ خرابی‌اش برای کاربر یک بن‌بستِ کامل بود: کسی که نامش نقطه دارد
 *    (`ali.k` — که پنل مدیر خودش ساخته) هر بار پروفایلش را ذخیره
 *    می‌کرد «نام کاربری معتبر نیست» می‌گرفت، **بی‌آنکه نامش را عوض
 *    کرده باشد**. یعنی ایمیل و شماره‌اش را هم نمی‌توانست ذخیره کند و
 *    هیچ راهی هم پیدا نمی‌کرد، چون خطا درباره‌ی فیلدی بود که دست
 *    نزده بود.
 *
 * ⛔ و **قاعده‌ی سازنده برنده شد، نه سخت‌گیرانه‌ترین**: هر نام کاربری‌ای
 *    که امروز در هر دیتابیسی هست از همین قاعده گذشته. قاعده‌ی
 *    سخت‌گیرانه‌تر (حداقل ۳ کاراکتر) هرگز هنگام ساخت اجرا نشده بود، پس
 *    اعمالش فقط کسانی را از حسابِ خودشان بیرون می‌کرد که کارِ اشتباهی
 *    نکرده‌اند.
 *
 * @return string '' یعنی معتبر، وگرنه متنِ خطا.
 */
function usernameRuleError(string $username): string
{
    if ($username === '') {
        return 'نام کاربری الزامی است.';
    }
    if (mb_strlen($username) > 50) {
        return 'نام کاربری نباید بیشتر از ۵۰ کاراکتر باشد.';
    }
    if (!preg_match('/^[a-zA-Z0-9_.]+$/', $username)) {
        return 'نام کاربری فقط می‌تواند شامل حروف انگلیسی، عدد، نقطه و آندرلاین باشد.';
    }
    return '';
}

/**
 * ⛔ همه‌ی قواعدِ اعتبارسنجیِ یک حسابِ تازه، در یک جا.
 *
 * ⛔ **قاعده‌ی «دست‌کم یک راهِ بازگشت»** جای قاعده‌ی قبلیِ «ایمیل الزامی
 *    است» را گرفت، و جایگزینی‌اش عمدی است نه حذف. استدلالِ قبلی این
 *    بود که «کسی که خودش ثبت‌نام می‌کند مدیری ندارد که رمزش را عوض
 *    کند، پس بدون ایمیل اولین فراموشیِ رمز یعنی از دست رفتنِ حساب» —
 *    و آن استدلال هنوز درست است. ولی **شماره‌ی موبایلِ تأییدشده هم
 *    دقیقاً همان کار را می‌کند**: کدِ پیامکی راهِ بازگشت است.
 *
 *    پس شرط این شد: ایمیل **یا** شماره. هیچ حسابی بدونِ هیچ‌کدام ساخته
 *    نمی‌شود، چون آن حساب از روزِ اول یک بن‌بست است.
 *
 * @param string $phone شماره‌ی **تأییدشده** (نرمال‌شده). خالی یعنی
 *        مسیرِ ثبت‌نامِ معمولی، و آن‌وقت ایمیل و رمز هر دو الزامی‌اند.
 * @return string '' یعنی معتبر، وگرنه متنِ خطا.
 */
function validateNewUser(PDO $pdo, string $fullName, string $username,
                         string $email, string $password, string $confirm,
                         string $phone = ''): string
{
    // ⛔ رمز در **همه‌ی** مسیرها الزامی است — و این عوض شد.
    //
    //    نسخه‌ی قبلی در مسیرِ شماره‌دار رمز را اختیاری می‌گذاشت، چون کدِ
    //    پیامکی احراز کرده بود. نتیجه‌اش حسابی بود که **تنها درش پیامک
    //    است**: روی دستگاهِ دوم، یا وقتی کوکیِ «این دستگاه را به خاطر
    //    بسپار» پاک شود، کاربر هیچ راهِ دیگری ندارد — و ورودِ پیامکیِ
    //    همیشگی قرار است قابلیتِ Pro باشد. یعنی حساب‌هایی می‌ساختیم که
    //    یا باید برای همیشه از گیت معاف بمانند یا کاربرشان بیرونِ دفترِ
    //    خودش قفل شود. رمز هنگامِ ثبت‌نام هر دو را از بین می‌برد.
    //
    // ⚠ حساب‌های **موجودِ** بی‌رمز دست‌نخورده‌اند: `password_hash` هنوز
    //   nullable است، `userHasPassword()` سرِ جایش، و استثنای
    //   `no_other_door` در `SmsLogin::loginAllowedFor()` هم. آن‌ها
    //   میراث‌اند، نه چیزی که از امروز ساخته شود.
    if ($fullName === '' || $username === '' || $password === '') {
        return 'تمام فیلدهای الزامی را پر کنید.';
    }
    if (mb_strlen($fullName) > 100) {
        return 'نام و نام خانوادگی نباید بیشتر از ۱۰۰ کاراکتر باشد.';
    }
    if (($e = usernameRuleError($username)) !== '') { return $e; }

    if ($phone !== '' && SmsLogin::normalizePhone($phone) === null) {
        return 'شماره موبایل معتبر نیست (مثل ۰۹۱۲۳۴۵۶۷۸۹).';
    }

    // ⛔ «دست‌کم یک راهِ بازگشت» — بالا توضیح داده شد. متنِ پیام عمداً
    //    دست‌نخورده ماند: مسیرِ بی‌شماره دقیقاً همان خطای قبلی را
    //    می‌گیرد و کاربرِ آن مسیر تفاوتی حس نمی‌کند.
    if ($email === '' && $phone === '') {
        return 'ایمیل الزامی است — بدون آن نمی‌توانید رمزتان را بازیابی کنید.';
    }
    if ($email !== '' && (!filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($email) > 190)) {
        return 'ایمیل معتبر نیست.';
    }
    if (mb_strlen($password) < 8) {
        return 'رمز عبور باید حداقل ۸ کاراکتر باشد.';
    }
    if ($password !== $confirm) {
        return 'رمز عبور و تکرار آن یکسان نیستند.';
    }

    $st = $pdo->prepare('SELECT id FROM users WHERE username = :u');
    $st->execute(['u' => $username]);
    if ($st->fetch()) { return 'این نام کاربری قبلاً استفاده شده است.'; }

    return '';
}

/**
 * ساختِ حسابِ تازه — تنها مسیرِ ساختِ کاربر.
 *
 * @return array{ok:bool, id?:int, error?:string}
 */
function createUserAccount(PDO $pdo, string $fullName, string $username,
                           string $email, string $password, string $role = 'user',
                           string $phone = ''): array
{
    if (!in_array($role, ['admin', 'user'], true)) { $role = 'user'; }

    try {
        $st = $pdo->prepare(
            'INSERT INTO users (full_name, username, password_hash, role, is_active)
             VALUES (:f, :u, :p, :r, 1)'
        );
        $st->execute([
            'f' => $fullName,
            'u' => $username,
            // ⛔ رمزِ خالی به `NULL` می‌رود، نه به هشِ رشته‌ی خالی. با هشِ
            //    رشته‌ی خالی، هر کسی که فرمِ ورود را با رمزِ خالی بفرستد
            //    وارد می‌شد — و «حسابِ بی‌رمز» از «حسابِ با رمزِ خالی»
            //    قابل تشخیص نبود. `NULL` تنها معنای «رمز ندارد» است.
            'p' => $password === '' ? null : password_hash($password, PASSWORD_DEFAULT),
            'r' => $role,
        ]);
        $id = (int)$pdo->lastInsertId();
    } catch (PDOException $e) {
        error_log('createUserAccount: ' . $e->getMessage());
        return ['ok' => false, 'error' => 'خطایی در ساخت حساب رخ داد.'];
    }

    // ⚠ شماره هم مثل ایمیل از تابعِ مشترکِ خودش رد می‌شود
    //   (`saveUserPhone()`)، نه از کوئریِ بالا: نرمال‌سازی و بررسیِ
    //   تکراری بودن یک جا می‌ماند، وگرنه شماره‌ای ذخیره می‌شد که
    //   `SmsLogin::requestCode()` پیدایش نمی‌کند — و آن خرابی بی‌صداست.
    if ($phone !== '') {
        $perr = saveUserPhone($pdo, $id, $phone);
        if ($perr !== '') {
            return ['ok' => true, 'id' => $id, 'error' => $perr];
        }
    }

    // ⚠ ایمیل از `saveUserEmail()` رد می‌شود، نه از کوئریِ بالا: همه‌ی
    //   قواعدِ ایمیل (اعتبار، یکتایی، «خالی یعنی دست نزن») آنجاست.
    $err = saveUserEmail($pdo, $id, $email);
    if ($err !== '') {
        // حساب ساخته شده ولی ایمیل ننشسته — صریح بگو، نه اینکه در سکوت
        // بگذری. کاربر بدون ایمیل نمی‌تواند رمزش را بازیابی کند.
        return ['ok' => true, 'id' => $id, 'error' => $err];
    }

    // بدون این، اولین تراکنشِ کاربر در هیچ حسابی نمی‌نشیند.
    ensureDefaultWallet($id);

    return ['ok' => true, 'id' => $id];
}

/**
 * سدِ ثبت‌نامِ انبوه.
 *
 * ⛔ بدونش، یک صفحه‌ی ثبت‌نامِ باز یعنی هر کسی می‌تواند در چند دقیقه
 *    هزاران حساب بسازد و دیتابیس را پر کند. از همان جدولِ
 *    `login_attempts` استفاده می‌کند تا جدولِ تازه‌ای لازم نشود؛ کلیدِ
 *    نامِ کاربری‌اش ثابت است، پس با شمارشِ ورودِ ناموفق قاطی نمی‌شود.
 *
 * @return int تعداد دقیقه‌ی باقی‌مانده، یا ۰ اگر آزاد است
 */
function signupThrottleMinutes(string $ip): int
{
    if (!LoginThrottle::available()) { return 0; }

    try {
        $pdo = Database::getConnection();
        $st = $pdo->prepare(
            'SELECT COUNT(*) FROM login_attempts
             WHERE username_tried = :k AND request_ip = :ip
               AND created_at > DATE_SUB(NOW(), INTERVAL 60 MINUTE)'
        );
        $st->execute(['k' => '__signup__', 'ip' => $ip]);
        $n = (int)$st->fetchColumn();
        if ($n < SIGNUP_MAX_PER_IP) { return 0; }

        $st = $pdo->prepare(
            'SELECT TIMESTAMPDIFF(MINUTE, NOW(), DATE_ADD(MIN(created_at), INTERVAL 60 MINUTE))
             FROM login_attempts
             WHERE username_tried = :k AND request_ip = :ip
               AND created_at > DATE_SUB(NOW(), INTERVAL 60 MINUTE)'
        );
        $st->execute(['k' => '__signup__', 'ip' => $ip]);
        return max(1, (int)$st->fetchColumn());
    } catch (PDOException $e) {
        // جدول نیست — سد بی‌سروصدا خاموش می‌ماند، نه اینکه صفحه بخوابد
        return 0;
    }
}

/**
 * ⛔ تنها جایی که تصمیم گرفته می‌شود «کدِ پیامکیِ درست چه کاری می‌کند».
 *
 * کدِ ورود و کدِ ثبت‌نام از یک مسیر می‌روند و **باید** همین‌طور بماند:
 * صفحه از روی شماره نمی‌فهمد (و نباید بفهمد) که این شماره از قبل حسابی
 * دارد یا نه — گفتنش یعنی هر کسی می‌تواند فهرستِ کاربرانِ سایت را
 * بسازد. پس هر دو شاخه یک پیام می‌گیرند و تفاوت **بعد از** تأییدِ کد
 * معلوم می‌شود، یعنی وقتی که آن آدم مالکیتِ شماره را ثابت کرده و دیگر
 * چیزی برای لو رفتن نمانده.
 *
 * ⛔ و این تابع **حساب نمی‌سازد**. شماره‌ی ناشناس فقط «تأیید شد» را
 *    برمی‌گرداند (`need_password`)؛ ساختنِ حساب کارِ
 *    `phoneSignupComplete()` است، بعد از اینکه کاربر رمز گذاشت. دلیلش
 *    بالای `validateNewUser()` نوشته شده: حسابِ بی‌رمز روی دستگاهِ دوم
 *    بن‌بست است.
 *
 * @return array{
 *   ok:bool, message:string, user?:array, created?:bool,
 *   need_pro?:bool, need_password?:bool, phone?:string
 * }
 */
function phoneAuthComplete(string $rawPhone, string $rawCode, ?string $ip = null): array
{
    require_once __DIR__ . '/sms_login.php';

    $res = SmsLogin::verifyCode($rawPhone, $rawCode, $ip);
    if (!$res['success']) { return ['ok' => false, 'message' => $res['message']]; }

    // ---------- حسابِ موجود: ورود ----------
    if (!empty($res['user'])) {
        $user  = $res['user'];
        $allow = SmsLogin::loginAllowedFor((int)$user['id']);
        if (!$allow['ok']) {
            // ⛔ اینجا و نه پیش از فرستادنِ کد: تا وقتی کد تأیید نشده،
            //    هر پیامِ متفاوتی می‌گوید «این شماره کاربرِ ماست».
            return ['ok' => false, 'need_pro' => true, 'message' => $allow['message']];
        }
        return ['ok' => true, 'created' => false, 'user' => $user,
                'message' => 'ورود موفقیت‌آمیز بود.'];
    }

    // ---------- شماره‌ی تازه: یک مرحله مانده ----------
    if (!phoneSignupEnabled()) {
        return ['ok' => false, 'message' => 'ثبت‌نام با شماره موبایل در دسترس نیست.'];
    }

    $phone = SmsLogin::normalizePhone($rawPhone);
    if ($phone === null) {
        return ['ok' => false, 'message' => 'شماره موبایل معتبر نیست.'];
    }

    phoneVerifyRemember($phone);

    return [
        'ok'            => false,
        'need_password' => true,
        'phone'         => $phone,
        'message'       => 'شماره‌ی شما تأیید شد. برای حسابتان یک رمز عبور بگذارید.',
    ];
}

/**
 * ⛔ تنها جایی که حسابِ «ثبت‌نام با شماره» ساخته می‌شود.
 *
 * پیش‌شرطش نشانه‌ی نشست است، نه هیچ چیزی که از فرم بیاید — بالای
 * `phoneVerifyRemember()` توضیح داده شده.
 *
 * @return array{ok:bool, message:string, user?:array, created?:bool, restart?:bool}
 */
function phoneSignupComplete(string $rawPhone, string $password, string $confirm): array
{
    require_once __DIR__ . '/sms_login.php';

    if (!phoneSignupEnabled()) {
        return ['ok' => false, 'message' => 'ثبت‌نام با شماره موبایل در دسترس نیست.'];
    }

    $phone = SmsLogin::normalizePhone($rawPhone);
    if ($phone === null) {
        return ['ok' => false, 'restart' => true, 'message' => 'شماره موبایل معتبر نیست.'];
    }

    // ⛔ `restart` یعنی «برگرد به مرحله‌ی شماره». بدونش کاربر روی فرمِ
    //    رمز می‌ماند و هر بار همان خطا را می‌گیرد، بی‌آنکه بفهمد باید
    //    کدِ تازه بگیرد — همان بن‌بستی که این پروژه جای دیگری هم برایش
    //    تست نوشته.
    if (!phoneVerifyOk($phone)) {
        return ['ok' => false, 'restart' => true,
                'message' => 'مهلتِ این مرحله تمام شد. دوباره شماره‌تان را وارد کنید.'];
    }

    $pdo      = Database::getConnection();
    $username = usernameFromPhone($pdo, $phone);
    $fullName = displayNameFromPhone($phone);

    // ⚠ از همان `validateNewUser()` رد می‌شود، نه از سنجشِ محلی: قاعده‌ی
    //   «دست‌کم یک راهِ بازگشت»، قاعده‌ی نام کاربری، و قاعده‌ی رمز هر سه
    //   آنجا هستند.
    $err = validateNewUser($pdo, $fullName, $username, '', $password, $confirm, $phone);
    if ($err !== '') { return ['ok' => false, 'message' => $err]; }

    $res = createUserAccount($pdo, $fullName, $username, '', $password, 'user', $phone);
    if (!$res['ok']) {
        return ['ok' => false, 'message' => $res['error'] ?? 'ساخت حساب انجام نشد.'];
    }
    if (($res['error'] ?? '') !== '') {
        return ['ok' => false, 'message' => $res['error']];
    }

    // ⛔ نشانه فقط حالا سوخته می‌شود — نه در شکستِ اعتبارسنجیِ بالا.
    phoneVerifyBurn();

    $st = $pdo->prepare('SELECT id, full_name, username, role, is_active FROM users WHERE id = :i');
    $st->execute(['i' => (int)$res['id']]);
    $user = $st->fetch();
    if (!$user) { return ['ok' => false, 'message' => 'ساخت حساب انجام نشد.']; }

    return ['ok' => true, 'created' => true, 'user' => $user,
            'message' => 'حساب شما ساخته شد.'];
}

/** ثبتِ یک ثبت‌نامِ انجام‌شده برای شمارشِ سد. */
function signupThrottleRecord(string $ip): void
{
    if (!LoginThrottle::available()) { return; }
    try {
        Database::getConnection()
            ->prepare('INSERT INTO login_attempts (username_tried, request_ip) VALUES (:k, :ip)')
            ->execute(['k' => '__signup__', 'ip' => $ip]);
    } catch (PDOException $e) { /* مهم نیست */ }
}
