<?php
/**
 * رمزنگاریِ داده‌ی حساس در دیتابیس — شماره‌ی کارت، شماره‌ی حساب، شبا.
 *
 * ⛔ چرا لازم است: تا پیش از این این سه ستون **خام** ذخیره می‌شدند. برای
 *    یک دفترِ شخصی که فقط خودت رویش کار می‌کنی قابل تحمل بود، ولی برای
 *    فروش نه: یک بکاپِ لو رفته، یک دامپِ دیتابیس، یا یک SQL injection در
 *    هر جای دیگرِ برنامه، شماره‌ی کاملِ کارتِ همه‌ی کاربران را می‌داد.
 *
 * ⛔ سه قاعده که نباید شکسته شوند:
 *
 *  ۱. **رمزگشاییِ چیزی که رمز نشده، خودِ همان است.** مقدارهای قدیمی که
 *     پیش از این قابلیت ذخیره شده‌اند دست‌نخورده‌اند و باید همچنان خوانده
 *     شوند. بدون این، روشن کردنِ رمزنگاری یعنی همه‌ی شماره‌کارت‌های
 *     موجود ناگهان آشغال نشان داده شوند.
 *
 *  ۲. **بدون کلید، برنامه نمی‌شکند.** اگر `APP_ENCRYPTION_KEY` تنظیم
 *     نشده باشد، `available()` نادرست برمی‌گرداند و همه چیز دقیقاً مثل
 *     قبل کار می‌کند (خام). قابلیتی که نصب‌های موجود را می‌خواباند،
 *     قابلیت نیست.
 *
 *  ۳. **کلید گم شود، داده رفته است.** این عمدی است و جبران‌پذیر نیست:
 *     اگر کلید در دیتابیس یا کنارِ آن نگه داشته شود، رمزنگاری بی‌معناست.
 *     کلید در `config/config.php` است که هرگز وارد گیت نمی‌شود؛
 *     `deploy/encrypt-cards.php` هنگام ساختنش صریحاً هشدار می‌دهد که
 *     بکاپِ دیتابیس بدونِ آن کلید **قابل بازیابی نیست**.
 *
 * قالبِ ذخیره:  `enc:v1:` + base64(nonce ‖ ciphertext)
 * الگوریتم:     `sodium_crypto_secretbox` (XSalsa20-Poly1305) — احراز
 *               اصالت دارد، پس دست‌کاریِ ردیف در دیتابیس هم گرفته
 *               می‌شود، نه فقط خوانده نشدن.
 *
 * ⚠ `sodium` از PHP 7.2 جزء هسته است و افزونه‌ی جدا لازم ندارد، ولی
 *   بعضی بیلدهای خیلی کوچک آن را ندارند؛ `available()` این را هم
 *   می‌سنجد.
 */

class Crypto
{
    /** پیشوندِ نسخه‌دار. اگر روزی الگوریتم عوض شود، `enc:v2:` می‌آید و
     *  `decrypt()` هر دو را می‌شناسد — بدون آن، تشخیصِ قالبِ قدیمی از
     *  تازه ناممکن می‌شد. */
    public const PREFIX = 'enc:v1:';

    private static ?string $key = null;
    private static ?bool $ready = null;

    /** آیا رمزنگاری در این نصب فعال است؟ */
    public static function available(): bool
    {
        if (self::$ready !== null) { return self::$ready; }

        if (!function_exists('sodium_crypto_secretbox')) {
            return self::$ready = false;
        }
        if (!defined('APP_ENCRYPTION_KEY') || APP_ENCRYPTION_KEY === '') {
            return self::$ready = false;
        }

        $raw = base64_decode(APP_ENCRYPTION_KEY, true);
        if ($raw === false || strlen($raw) !== SODIUM_CRYPTO_SECRETBOX_KEYBYTES) {
            // ⚠ کلیدِ خراب باید **صدا کند**، نه اینکه بی‌سروصدا خاموش
            //   بماند: در آن حالت داده‌ی تازه خام ذخیره می‌شد و کسی
            //   نمی‌فهمید رمزنگاری اصلاً کار نمی‌کند.
            error_log('Crypto: APP_ENCRYPTION_KEY نامعتبر است — باید base64 از ۳۲ بایت باشد.');
            return self::$ready = false;
        }

        self::$key = $raw;
        return self::$ready = true;
    }

    /** یک کلیدِ تازه به شکل base64. فقط برای اسکریپتِ راه‌اندازی. */
    public static function newKey(): string
    {
        return base64_encode(random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES));
    }

    /**
     * رمز می‌کند. اگر رمزنگاری فعال نباشد یا رشته خالی باشد، همان
     * ورودی برمی‌گردد — پس فراخوانی‌اش هیچ‌جا خطرناک نیست.
     */
    public static function encrypt(?string $plain): ?string
    {
        if ($plain === null || $plain === '') { return $plain; }
        if (!self::available()) { return $plain; }

        // ⚠ دوباره رمز نشود. یک مقدارِ رمزشده که دوباره از اینجا رد شود
        //   لایه‌ی دومی می‌گرفت و رمزگشاییِ یک‌مرحله‌ای دیگر جواب نمی‌داد.
        if (str_starts_with($plain, self::PREFIX)) { return $plain; }

        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $box   = sodium_crypto_secretbox($plain, $nonce, self::$key);

        return self::PREFIX . base64_encode($nonce . $box);
    }

    /**
     * رمزگشایی می‌کند.
     *
     * ⛔ مقداری که پیشوندِ ما را ندارد **خودش برمی‌گردد**، نه null:
     *    ردیف‌های قدیمی خام‌اند و باید بخوانند.
     *
     * اگر رمزگشایی شکست بخورد (کلیدِ عوض‌شده یا ردیفِ دست‌کاری‌شده)
     * `null` برمی‌گردد و در لاگ می‌نشیند — نه رشته‌ی آشغال، چون آن
     * می‌رفت داخل فرم و کاربر ذخیره‌اش می‌کرد.
     */
    public static function decrypt(?string $stored): ?string
    {
        if ($stored === null || $stored === '') { return $stored; }
        if (!str_starts_with($stored, self::PREFIX)) { return $stored; }
        if (!self::available()) { return null; }

        $raw = base64_decode(substr($stored, strlen(self::PREFIX)), true);
        if ($raw === false || strlen($raw) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
            error_log('Crypto: مقدارِ رمزشده‌ی خراب.');
            return null;
        }

        $nonce = substr($raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $box   = substr($raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);

        $plain = sodium_crypto_secretbox_open($box, $nonce, self::$key);
        if ($plain === false) {
            error_log('Crypto: رمزگشایی شکست خورد — کلید عوض شده یا ردیف دست‌کاری شده.');
            return null;
        }
        return $plain;
    }

    /** آیا این مقدار رمزشده است؟ (برای اسکریپتِ مهاجرت) */
    public static function isEncrypted(?string $v): bool
    {
        return $v !== null && str_starts_with($v, self::PREFIX);
    }

    /**
     * چند ستونِ یک ردیف را یک‌جا رمزگشایی می‌کند.
     * ⛔ تنها راهِ خواندنِ این ستون‌ها همین است — هر کوئریِ تازه‌ای که
     *    آن‌ها را می‌آورد باید از اینجا رد شود، وگرنه کاربر رشته‌ی
     *    `enc:v1:…` را داخل فرمِ خودش می‌بیند.
     */
    public static function decryptRow(array $row, array $fields): array
    {
        foreach ($fields as $f) {
            if (array_key_exists($f, $row)) {
                $row[$f] = self::decrypt($row[$f]);
            }
        }
        return $row;
    }

    /** ستون‌های حساسِ جدول `wallets`. یک جا تعریف شده تا فهرستِ دوم نسازیم. */
    public const WALLET_FIELDS = ['card_number', 'account_number', 'iban'];
}
