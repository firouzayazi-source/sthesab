<?php
/**
 * ورود با کد پیامکی — پشتِ کلیدِ مدیر.
 *
 * ⛔ پیش‌فرض خاموش، و این قابل مذاکره نیست. ورود با پیامک یک **راهِ
 *    ورودِ تازه** به هر حسابی است؛ روشن شدنش با یک `git pull` یعنی باز
 *    کردنِ دری که مالکِ نصب خبر ندارد وجود دارد. کلید در
 *    `admin/users.php` است (`app_settings.allow_sms_login`).
 *
 * ⛔ و روشن بودنِ کلید هم به‌تنهایی کافی نیست: بدون `SMS_METHOD` هیچ
 *    کدی فرستاده نمی‌شود. `available()` هر سه شرط را با هم می‌سنجد
 *    (کلید، جدول، پنل) — چون کلیدی که کار نمی‌کند از نبودنش بدتر است.
 *
 * ⛔ چهار محافظ، و هیچ‌کدام اختیاری نیست:
 *   ۱. **سقفِ تلاش روی خودِ کد** (`MAX_CODE_ATTEMPTS`). یک کدِ ۵ رقمی
 *      ۱۰۰٬۰۰۰ حالت دارد؛ بدون این، مهاجمی که شماره‌ی قربانی را می‌داند
 *      یک کد درخواست می‌دهد و آزادانه حدس می‌زند — یعنی ورود با پیامک
 *      از ورود با رمز **ضعیف‌تر** می‌شد. این مهم‌ترین محافظ است.
 *   ۲. **یک‌بارمصرف بودن** (`used_at`): وگرنه هر کسی که متنِ پیامک را
 *      ببیند تا آخرِ پنجره می‌تواند دوباره وارد شود.
 *   ۳. **سقفِ درخواست** روی شماره و روی IP. اینجا مسئله فقط امنیت نیست،
 *      **پول** است: هر پیامک هزینه دارد و یک اندپوینتِ بی‌سقف اعتبارِ
 *      پنل را در چند دقیقه خالی می‌کند.
 *   ۴. **پیامِ یکسان** برای شماره‌ی موجود و ناموجود، وگرنه این صفحه یک
 *      ابزارِ آماده برای فهمیدنِ «چه کسی کاربرِ این سایت است» می‌شود.
 *
 * ⛔ همه‌ی حسابِ زمان در **دیتابیس** انجام می‌شود (`NOW()`,
 *    `DATE_ADD`)، نه در PHP. همان درسی که لینکِ بازیابیِ رمز داد:
 *    `Auth::initSession()` منطقه‌ی زمانیِ PHP را عوض می‌کند و اگر یک طرفِ
 *    مقایسه در PHP ساخته شود، کد بلافاصله «منقضی» می‌شود.
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/sms.php';
require_once __DIR__ . '/login_throttle.php';

const SMS_LOGIN_SETTING = 'allow_sms_login';

class SmsLogin
{
    /** طولِ کد. ۵ رقم با سقفِ تلاشِ ۵ عملاً غیرقابل حدس است. */
    public const CODE_LENGTH = 5;

    /** عمرِ کد، به دقیقه. کوتاه عمدی است: پیامک چند ثانیه‌ای می‌رسد. */
    public const CODE_TTL_MIN = 3;

    /** ⛔ سقفِ تلاشِ ناموفق روی **همین** کد. */
    public const MAX_CODE_ATTEMPTS = 5;

    /** سقفِ درخواستِ کد برای یک شماره در ساعت. */
    public const MAX_PER_PHONE = 3;

    /** سقفِ درخواستِ کد از یک IP در ساعت (پشتِ NAT چند نفر واقعی‌اند). */
    public const MAX_PER_IP = 10;

    /** فاصله‌ی لازم بین دو درخواست، به ثانیه. */
    public const RESEND_WAIT_SEC = 60;

    /**
     * ⛔ تنها جایی که شماره‌ی موبایل معنا می‌گیرد.
     *
     * کاربر ممکن است `+989123456789`، `00989123456789`، `9123456789`،
     * `0912 345 6789` یا ارقامِ فارسی بنویسد. اگر ثبتِ شماره و ورود با
     * شماره از دو نرمال‌سازیِ مختلف رد شوند، تطبیق **بی‌صدا** شکست
     * می‌خورد: کد فرستاده می‌شود، کاربر می‌زندش، و «شماره پیدا نشد».
     *
     * فقط موبایلِ ایران پذیرفته می‌شود، چون پنلِ پیامک هم همان را
     * می‌فرستد. خروجی همیشه `09xxxxxxxxx` است یا `null`.
     */
    public static function normalizePhone(string $raw): ?string
    {
        $d = preg_replace('/\D+/', '', toLatinDigits(trim($raw))) ?? '';

        if (str_starts_with($d, '0098'))     { $d = substr($d, 4); }
        elseif (str_starts_with($d, '98') && strlen($d) === 12) { $d = substr($d, 2); }

        if (strlen($d) === 10 && $d[0] === '9') { $d = '0' . $d; }

        return preg_match('/^09\d{9}$/', $d) === 1 ? $d : null;
    }

    /** جدولش آمده است؟ (migration اجرا نشده یعنی این قابلیت وجود ندارد.) */
    public static function tableReady(): bool
    {
        return tableExists('sms_codes') && tableHasColumn('users', 'phone');
    }

    /** مدیر روشنش کرده است؟ (بدونِ توجه به اینکه پنل تنظیم شده یا نه.) */
    public static function switchedOn(): bool
    {
        return getSetting(SMS_LOGIN_SETTING, '0') === '1';
    }

    /**
     * ⛔ چند کاربر اصلاً شماره ثبت کرده‌اند؟
     *
     * بدونِ این عدد، ورودِ پیامکی یک بن‌بستِ کاملاً بی‌صدا دارد و **مرغ و
     * تخم‌مرغ** است: `requestCode()` کاربر را با `WHERE phone = :p` پیدا
     * می‌کند، ولی فیلدِ شماره در پروفایل فقط وقتی دیده می‌شود که ورودِ
     * پیامکی از قبل کار کند. پس تا پیش از راه افتادنِ پنل، هیچ‌کس شماره‌ای
     * نداشته — و بعد از راه افتادنش، اولین تلاشِ ورود کاربری پیدا نمی‌کند.
     *
     * ⚠ و صفحه‌ی ورود عمداً نمی‌تواند این را بگوید: پیامِ «این شماره ثبت
     *   نشده» همان چیزی است که فهرستِ کاربران را لو می‌دهد. پس تنها جایی
     *   که این حقیقت گفتنی است، پنلِ مدیر است — که از قبل فهرستِ کاربران
     *   را می‌بیند و چیزِ تازه‌ای لو نمی‌دهد.
     *
     * @return int منفی یعنی هنوز ستونش نیامده (migration اجرا نشده).
     */
    public static function usersWithPhone(): int
    {
        if (!tableHasColumn('users', 'phone')) { return -1; }
        $st = Database::getConnection()->query(
            "SELECT COUNT(*) FROM users WHERE phone IS NOT NULL AND phone <> ''"
        );
        return (int)$st->fetchColumn();
    }

    /**
     * هر سه شرط با هم — تنها چیزی که صفحه‌ها باید بپرسند.
     */
    public static function available(): bool
    {
        return self::switchedOn() && self::tableReady() && Sms::isConfigured();
    }

    /**
     * ⛔ آیا این کاربر حق دارد **با پیامک** وارد شود؟ — تنها جای این تصمیم.
     *
     * سه دروازه، به همین ترتیب، و هر کدام یک چیزِ متفاوت را می‌گویند:
     *
     * ۱. **کلیدِ مدیر.** خاموش یعنی خاموش، حتی برای کاربرِ Pro. خواسته‌ی
     *    صریحِ مالکِ نصب همین است و به همین دلیل بالاترین دروازه است:
     *    هیچ پرداختی نباید بتواند از رویش رد شود.
     *
     * ۲. **طرح.** ورودِ همیشگی با پیامک یک قابلیتِ Pro است و از
     *    `planAllows()` رد می‌شود — تنها جای تصمیم‌های طرح. تا وقتی
     *    `planEnforced()` خاموش است (پیش‌فرض) این دروازه همیشه باز است،
     *    پس هیچ نصبِ موجودی با یک `git pull` چیزی از دست نمی‌دهد.
     *
     * ۳. **⛔ حسابِ بی‌رمز، استثنای عمدی و نوشته‌شده.** کسی که با شماره
     *    ثبت‌نام کرده و هنوز رمزی نگذاشته، پیامک **تنها درِ** اوست.
     *    بستنِ آن در پاداشِ نخریدن نیست — گروگان گرفتنِ دفترِ خودش است،
     *    همان چیزی که «فقط خواندنی، نه قفلِ کامل» برای جلوگیری‌اش نوشته
     *    شد. و کاربری که نتواند وارد شود، اصلاً به خریدن هم نمی‌رسد.
     *
     *    ⚠ بله، این یعنی «رمز نگذار تا پیامکِ رایگان داشته باشی». هزینه‌اش
     *      واقعی ولی کوچک است: چنین کاربری **هیچ** راهِ ورودِ دیگری ندارد
     *      و سقفِ سه پیامک در ساعت هم سرِ جایش است. هزینه‌ی حالتِ دیگر —
     *      قفل شدنِ کاربر بیرونِ دفترِ خودش — برگشت‌ناپذیر است. اگر روزی
     *      این استثنا را برداشتید، اول باید راهِ بازگشتِ دیگری برای
     *      حساب‌های بی‌رمز ساخته باشید.
     *
     * @return array{ok:bool, reason:string, message:string}
     */
    public static function loginAllowedFor(int $userId): array
    {
        if (!self::available()) {
            return ['ok' => false, 'reason' => 'off',
                    'message' => 'ورود با پیامک در دسترس نیست.'];
        }

        require_once __DIR__ . '/plan.php';
        if (planAllows($userId, 'sms_login')) {
            return ['ok' => true, 'reason' => 'allowed', 'message' => ''];
        }

        if (!userHasPassword($userId)) {
            return ['ok' => true, 'reason' => 'no_other_door', 'message' => ''];
        }

        return [
            'ok'      => false,
            'reason'  => 'need_pro',
            'message' => 'ورود با پیامک بخشی از نسخه‌ی کامل است. '
                       . 'با رمز عبور وارد شوید، یا نسخه‌ی کامل را فعال کنید.',
        ];
    }

    /**
     * درخواستِ کد.
     *
     * ⛔ پیامِ موفقیت برای شماره‌ی موجود و ناموجود **یکی** است. کلیدِ
     *    `sent` فقط برای تست است و به کاربر نشان داده نمی‌شود.
     *
     * @return array{success:bool, message:string, sent?:bool, wait?:int}
     */
    public static function requestCode(string $rawPhone, ?string $ip = null): array
    {
        if (!self::available()) {
            return ['success' => false, 'message' => 'ورود با پیامک در دسترس نیست.'];
        }

        $phone = self::normalizePhone($rawPhone);
        if ($phone === null) {
            return ['success' => false, 'message' => 'شماره موبایل را درست وارد کنید (مثل ۰۹۱۲۳۴۵۶۷۸۹).'];
        }

        $ip  = $ip ?? (string)($_SERVER['REMOTE_ADDR'] ?? '');
        $pdo = Database::getConnection();

        // ---------- سقفِ درخواست ----------
        // ⛔ پیش از پیدا کردنِ کاربر سنجیده می‌شود، وگرنه شمارش فقط برای
        //    شماره‌های موجود انجام می‌شد و مهاجم با شماره‌های تصادفی
        //    آزادانه درخواست می‌داد.
        $wait = self::resendWait($phone);
        if ($wait > 0) {
            return [
                'success' => false,
                'wait'    => $wait,
                'message' => 'کد قبلی هنوز معتبر است. ' . toPersianDigits((string)$wait) . ' ثانیه دیگر می‌توانید کد تازه بخواهید.',
            ];
        }
        if (self::countRecent('phone', $phone) >= self::MAX_PER_PHONE
            || self::countRecent('request_ip', $ip) >= self::MAX_PER_IP) {
            return [
                'success' => false,
                'message' => 'درخواست‌های زیادی ثبت شده است. یک ساعت دیگر دوباره تلاش کنید یا با رمز عبور وارد شوید.',
            ];
        }

        // ⛔ متنِ این پیام از «ورود» به «ورود یا ساختِ حساب» رفت، چون حالا
        //    هر دو کار از همین یک مسیر می‌گذرند. اگر متن فقط «ورود» بماند،
        //    کاربرِ تازه فکر می‌کند این صفحه به دردش نمی‌خورد.
        // ⚠ و همچنان برای شماره‌ی ثبت‌شده و ثبت‌نشده **یکی** است: تفاوتِ
        //   متن یعنی هر کسی می‌تواند فهرستِ کاربرانِ سایت را بسازد.
        $ok = ['success' => true, 'sent' => false,
               'message' => 'کد به این شماره پیامک شد.'];

        $st = $pdo->prepare('SELECT id, is_active FROM users WHERE phone = :p LIMIT 1');
        $st->execute(['p' => $phone]);
        $user = $st->fetch();

        // ⛔ حسابِ **غیرفعال**: همان پیامِ موفقیت، ولی هیچ ردیفی ساخته و
        //    هیچ پیامکی فرستاده نمی‌شود. و عمداً هم به شاخه‌ی ثبت‌نام
        //    نمی‌رود: این شماره صاحب دارد، و ساختنِ حسابِ دوم روی آن یعنی
        //    دور زدنِ همان غیرفعال‌سازی.
        if ($user && (int)$user['is_active'] !== 1) {
            return $ok;
        }

        // ⛔ شماره‌ی ناشناس فقط وقتی کد می‌گیرد که ثبت‌نام با شماره واقعاً
        //    باز باشد (هر دو کلیدِ مدیر). وگرنه همان پیامِ یکسان، بدونِ
        //    هیچ پیامکی — یعنی رفتارِ قبلی، دست‌نخورده.
        if (!$user) {
            require_once __DIR__ . '/signup.php';
            if (!phoneSignupEnabled()) { return $ok; }
        }

        $code = self::newCode();

        // کدهای بازِ قبلیِ همین شماره سوزانده می‌شوند: دو کدِ همزمان
        // معتبر یعنی دو برابر شدنِ شانسِ حدس، بی‌هیچ سودی.
        $pdo->prepare('UPDATE sms_codes SET used_at = NOW() WHERE phone = :p AND used_at IS NULL')
            ->execute(['p' => $phone]);

        $pdo->prepare(
            'INSERT INTO sms_codes (user_id, phone, code_hash, expires_at, request_ip)
             VALUES (:u, :p, :h, DATE_ADD(NOW(), INTERVAL ' . self::CODE_TTL_MIN . ' MINUTE), :ip)'
        )->execute([
            // ⚠ `NULL` یعنی کدِ **ثبت‌نام**: هنوز حسابی وجود ندارد که به آن
            //   وصل شود. سقفِ درخواست روی ستونِ `phone` شمرده می‌شود نه
            //   `user_id`، پس این هیچ سدی را باز نمی‌کند.
            'u'  => $user ? (int)$user['id'] : null,
            'p'  => $phone,
            'h'  => hash('sha256', $code),
            'ip' => $ip,
        ]);

        // ⛔ از `sendCode` می‌رود نه `send`: اگر مدیر یک «الگو» در پنل
        //    ساخته باشد، پیامک از مسیرِ خدماتی می‌رود — ارزان‌تر، بدونِ
        //    نیاز به خطِ اختصاصی، و شبانه‌روزی. بدونِ الگو همان متنِ
        //    آزادِ قبلی فرستاده می‌شود.
        // متنِ حالتِ آزاد از قالبِ قابلِ ویرایشِ مدیر می‌آید
        // (`{code}` و `{ttl}`). در حالتِ الگو اصلاً استفاده نمی‌شود.
        $sent = Sms::sendCode($phone, $code, smsCodeText($code, self::CODE_TTL_MIN));

        // ⚠ اگر پنل قبول نکرد هم پیامِ کاربر عوض نمی‌شود — پیامِ خطای
        //   پنل به مهاجم می‌گفت این شماره واقعاً کاربر است.
        $ok['sent'] = $sent;
        return $ok;
    }

    /**
     * سنجشِ کد. در صورت موفقیت `user` برمی‌گردد (بدون ساختنِ نشست).
     *
     * @return array{success:bool, message:string, user?:array}
     */
    public static function verifyCode(string $rawPhone, string $rawCode, ?string $ip = null): array
    {
        if (!self::available()) {
            return ['success' => false, 'message' => 'ورود با پیامک در دسترس نیست.'];
        }

        $phone = self::normalizePhone($rawPhone);
        $code  = preg_replace('/\D+/', '', toLatinDigits(trim($rawCode))) ?? '';
        if ($phone === null || $code === '') {
            return ['success' => false, 'message' => 'شماره و کد را کامل وارد کنید.'];
        }

        $ip = $ip ?? (string)($_SERVER['REMOTE_ADDR'] ?? '');

        // سدِ عمومیِ حدس، با کلیدِ **شماره** نه نام کاربری: وگرنه مهاجم
        // می‌توانست با درخواستِ کد، ورودِ با رمزِ همان حساب را هم قفل کند.
        $lockedFor = LoginThrottle::lockedFor($phone, $ip);
        if ($lockedFor !== null) {
            return [
                'success' => false,
                'message' => 'تلاش‌های ناموفق زیاد بوده است. ' . toPersianDigits((string)$lockedFor) . ' دقیقه دیگر دوباره تلاش کنید.',
            ];
        }

        $pdo = Database::getConnection();
        $st  = $pdo->prepare(
            'SELECT id, user_id, code_hash, attempts
             FROM sms_codes
             WHERE phone = :p AND used_at IS NULL AND expires_at > NOW()
             ORDER BY id DESC LIMIT 1'
        );
        $st->execute(['p' => $phone]);
        $row = $st->fetch();

        $wrong = ['success' => false, 'message' => 'کد وارد شده درست نیست یا منقضی شده است.'];

        if (!$row) {
            LoginThrottle::recordFailure($phone, $ip);
            return $wrong;
        }

        // ⛔ سقفِ تلاش روی همین کد — محافظِ اصلی.
        if ((int)$row['attempts'] >= self::MAX_CODE_ATTEMPTS) {
            $pdo->prepare('UPDATE sms_codes SET used_at = NOW() WHERE id = :i')
                ->execute(['i' => (int)$row['id']]);
            LoginThrottle::recordFailure($phone, $ip);
            return ['success' => false, 'message' => 'این کد سوخت. کد تازه بخواهید.'];
        }

        if (!hash_equals((string)$row['code_hash'], hash('sha256', $code))) {
            $pdo->prepare('UPDATE sms_codes SET attempts = attempts + 1 WHERE id = :i')
                ->execute(['i' => (int)$row['id']]);
            LoginThrottle::recordFailure($phone, $ip);
            return $wrong;
        }

        // ⛔ سوزاندنِ کد **پیش از** برگرداندنِ کاربر: اگر بعدش انجام
        //    می‌شد، هر خطایی در میانه یک کدِ زنده به جا می‌گذاشت.
        $pdo->prepare('UPDATE sms_codes SET used_at = NOW() WHERE id = :i')
            ->execute(['i' => (int)$row['id']]);

        LoginThrottle::clear($phone);
        self::prune();

        // ⛔ کدِ ثبت‌نام: `user_id` تهی است، پس هنوز حسابی نیست. اینجا
        //    ساخته **نمی‌شود** — این تابع فقط «مالکیتِ شماره ثابت شد» را
        //    برمی‌گرداند و تصمیمِ بعدی مالِ `phoneAuthComplete()` است،
        //    تنها جایی که آن تصمیم گرفته می‌شود.
        if ($row['user_id'] === null) {
            return ['success' => true, 'message' => 'شماره تأیید شد.',
                    'user' => null, 'phone' => $phone];
        }

        $us = $pdo->prepare(
            'SELECT id, full_name, username, role, is_active FROM users WHERE id = :i LIMIT 1'
        );
        $us->execute(['i' => (int)$row['user_id']]);
        $user = $us->fetch();

        if (!$user || (int)$user['is_active'] !== 1) {
            return ['success' => false, 'message' => 'حساب کاربری شما غیرفعال شده است.'];
        }

        return ['success' => true, 'message' => 'ورود موفقیت‌آمیز بود.',
                'user' => $user, 'phone' => $phone];
    }

    /** جدول را کوچک نگه می‌دارد؛ هیچ کارِ زمان‌بندی‌شده‌ای لازم نیست. */
    public static function prune(): int
    {
        try {
            $st = Database::getConnection()->query(
                'DELETE FROM sms_codes WHERE created_at < DATE_SUB(NOW(), INTERVAL 7 DAY)'
            );
            return $st->rowCount();
        } catch (PDOException $e) {
            return 0;
        }
    }

    /**
     * چند ثانیه تا اجازه‌ی درخواستِ بعدی. صفر یعنی آزاد.
     */
    private static function resendWait(string $phone): int
    {
        $st = Database::getConnection()->prepare(
            'SELECT GREATEST(0, ' . self::RESEND_WAIT_SEC . ' - TIMESTAMPDIFF(SECOND, MAX(created_at), NOW()))
             FROM sms_codes WHERE phone = :p'
        );
        $st->execute(['p' => $phone]);
        return (int)$st->fetchColumn();
    }

    /** شمارشِ درخواست‌های یک ساعتِ گذشته روی یک ستونِ کلید. */
    private static function countRecent(string $column, string $value): int
    {
        // نامِ ستون از فراخوانی‌های ثابتِ همین فایل می‌آید، نه از ورودی
        // کاربر — ولی برای اینکه فردا هم همین‌طور بماند، محدود می‌شود.
        if (!in_array($column, ['phone', 'request_ip'], true)) { return PHP_INT_MAX; }

        $st = Database::getConnection()->prepare(
            "SELECT COUNT(*) FROM sms_codes
             WHERE `{$column}` = :v AND created_at > DATE_SUB(NOW(), INTERVAL 60 MINUTE)"
        );
        $st->execute(['v' => $value]);
        return (int)$st->fetchColumn();
    }

    /**
     * کدِ تصادفی. `random_int` است نه `rand`: کدِ قابل پیش‌بینی یعنی
     * نیازی به حدس هم نیست.
     */
    private static function newCode(): string
    {
        $min = (int)str_pad('1', self::CODE_LENGTH, '0');
        $max = (int)str_repeat('9', self::CODE_LENGTH);
        return (string)random_int($min, $max);
    }
}
